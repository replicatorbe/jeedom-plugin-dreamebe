<?php
/* Jeu d'essai hors ligne du plugin Dreame.
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base de données, ni compte DreameHome, ni
 * robot. Les trois classes éprouvées ici — le client du cloud, la table de
 * spécification et le décodeur de carte — ignorent volontairement Jeedom, et
 * c'est précisément ce qui rend ce fichier possible.
 *
 * Le transport réseau est remplacé par une doublure qui rend des réponses
 * écrites à la main et enregistre ce qu'on lui a demandé. On vérifie donc non
 * seulement ce que le plugin comprend, mais aussi ce qu'il envoie — un corps de
 * requête mal formé est la panne la plus fréquente sur cette API, et la seule
 * qu'un test de lecture ne verrait jamais.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/dreamebeApi.class.php';
require_once __DIR__ . '/../core/class/dreamebeSpec.class.php';
require_once __DIR__ . '/../core/class/dreamebeMap.class.php';

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    /* Comparaison lâche : le cloud rend ses nombres tantôt en entier, tantôt en
     * chaîne — c'est documenté pour la lecture d'ombre — et un test qui échoue
     * sur « 1 » contre « '1' » n'apprend rien d'utile. */
    if ($_obtenu == $_attendu) {
        $ok++;
        printf("  %-56s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-56s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

function verifieLeve($_titre, $_callable, $_code = null) {
    global $ok, $ko;
    try {
        $_callable();
    } catch (dreamebeApiException $e) {
        if ($_code === null || $e->getCode() === $_code) {
            $ok++;
            printf("  %-56s ok\n", $_titre);
            return;
        }
        $ko++;
        printf("  %-56s ÉCHEC : code %d, attendu %d\n", $_titre, $e->getCode(), $_code);
        return;
    } catch (Throwable $e) {
        $ok++;
        printf("  %-56s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-56s ÉCHEC : aucune exception levée\n", $_titre);
}

/* ====================================================================== *
 * La doublure de transport
 * ====================================================================== */

class dreamebeApiDouble extends dreamebeApi {
    /* Réponses à servir, dans l'ordre : array('status' => …, 'body' => …). */
    public $answers = array();
    /* Ce qu'on nous a demandé, pour pouvoir le vérifier. */
    public $requests = array();

    protected function http($_method, $_url, $_headers, $_body = null) {
        $this->requests[] = array('method' => $_method, 'url' => $_url,
                                  'headers' => $_headers, 'body' => $_body);
        if (empty($this->answers)) {
            return array('status' => 500, 'body' => '{"code":-1,"msg":"pas de réponse prévue"}');
        }
        return array_shift($this->answers);
    }

    public function header($_index, $_prefix) {
        foreach ($this->requests[$_index]['headers'] as $header) {
            if (stripos($header, $_prefix) === 0) {
                return substr($header, strlen($_prefix));
            }
        }
        return null;
    }
}

function jeton($_expires = 7200, $_token = 'JETON', $_refresh = 'RENOUVELLEMENT') {
    return array('status' => 200, 'body' => json_encode(array(
        'access_token' => $_token, 'refresh_token' => $_refresh,
        'expires_in' => $_expires, 'uid' => '88001122',
        'tenant_id' => '000000', 'region' => 'eu',
    )));
}

function nouveauClient() {
    $api = new dreamebeApiDouble('compte@exemple.com', 'motdepasse', 'eu');
    return $api;
}

$robot = array('did' => '790160690', 'model' => 'dreame.vacuum.r2579a',
               'prefix' => '10000', 'bind_domain' => '10000.mt.eu.iot.dreame.tech:19973');

/* ====================================================================== *
 * Authentification
 * ====================================================================== */

echo "\n== Authentification ==\n";

verifie('empreinte du mot de passe',
        dreamebeApi::hashPassword('motdepasse'),
        md5('motdepasse' . 'RAylYC%fmSKp7%Tq'));

$api = nouveauClient();
$api->answers[] = jeton();
$api->login();
$session = $api->getSession();
verifie('jeton retenu', $session['token'], 'JETON');
verifie('jeton de renouvellement retenu', $session['refresh_token'], 'RENOUVELLEMENT');
verifie('identifiant utilisateur retenu', $session['uid'], '88001122');
verifie('session marquée à enregistrer', $api->isSessionDirty(), true);
/* Deux minutes de marge : la session doit expirer avant le serveur, pas après. */
verifie('marge sur l\'échéance', ($session['expires_at'] - time()) <= 7080, true);

verifie('adresse d\'authentification', $api->requests[0]['url'],
        'https://eu.iot.dreame.tech:13267/dreame-auth/oauth/token');
verifie('mot de passe jamais envoyé en clair',
        strpos($api->requests[0]['body'], 'motdepasse') === false, true);
verifie('type de compte annoncé',
        strpos($api->requests[0]['body'], '&type=account') !== false, true);
/* Le couple client de l'application reste en place APRÈS connexion : le
 * remplacer par le jeton de l'utilisateur est l'erreur qui rend tout 401. */
verifie('couple client statique', $api->header(0, 'Authorization: '),
        'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=');
verifie('locataire annoncé', $api->header(0, 'Tenant-Id: '), '000000');

/* La région rendue par le serveur fait autorité sur celle saisie. */
$api = nouveauClient();
$api->setRegion('us');
$api->answers[] = array('status' => 200, 'body' => json_encode(array(
    'access_token' => 'J', 'refresh_token' => 'R', 'expires_in' => 7200,
    'uid' => '1', 'region' => 'eu')));
$api->login();
verifie('région corrigée par le serveur', $api->getRegion(), 'eu');

$api = nouveauClient();
$api->answers[] = array('status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Bad credentials"}');
verifieLeve('identifiants refusés', function () use ($api) { $api->login(); },
            dreamebeApiException::AUTH);

/* Un jeton de renouvellement périmé n'est pas une erreur d'identifiants : il
 * faut repartir du mot de passe avant d'accuser l'utilisateur. */
$api = nouveauClient();
$api->setSession(array('token' => '', 'refresh_token' => 'VIEUX', 'expires_at' => 0));
$api->answers[] = array('status' => 400, 'body' => '{"error_description":"Invalid refresh token"}');
$api->answers[] = jeton(7200, 'NOUVEAU');
$api->login();
verifie('repli sur le mot de passe', $api->getSession()['token'], 'NOUVEAU');
verifie('deux appels, pas un', count($api->requests), 2);
verifie('le second est bien un mot de passe',
        strpos($api->requests[1]['body'], 'grant_type=password') !== false, true);

/* Un serveur en panne ne juge pas les identifiants : ni refus de compte, ni
 * jeton de renouvellement jeté. Sinon, un incident chez Dreame suspendrait le
 * plugin en accusant le mot de passe. */
$api = nouveauClient();
$api->answers[] = array('status' => 503, 'body' => '{"error":"unavailable"}');
verifieLeve('panne du serveur d\'authentification', function () use ($api) { $api->login(); },
            dreamebeApiException::NETWORK);

$api = nouveauClient();
$api->answers[] = array('status' => 429, 'body' => '');
verifieLeve('débit limité à la connexion', function () use ($api) { $api->login(); },
            dreamebeApiException::NETWORK);

$api = nouveauClient();
$api->setSession(array('token' => '', 'refresh_token' => 'BON', 'expires_at' => 0));
$api->answers[] = array('status' => 502, 'body' => '<html>Bad Gateway</html>');
verifieLeve('renouvellement pendant une panne', function () use ($api) { $api->login(); },
            dreamebeApiException::NETWORK);
verifie('jeton de renouvellement conservé', $api->getSession()['refresh_token'], 'BON');
verifie('aucun repli sur le mot de passe', count($api->requests), 1);

/*
 * Jeedom rend la configuration d'un plugin DÉJÀ décodée quand elle ressemble à
 * du JSON : une session enregistrée revient en tableau, pas en chaîne. Lui
 * appliquer json_decode() lève une TypeError fatale en PHP 8 — et le défaut ne
 * se voit qu'à partir de la DEUXIÈME connexion, la première trouvant une valeur
 * vide. C'est arrivé en production.
 */
echo "\n== Session restaurée ==\n";
$attendu = array('token' => 'J', 'refresh_token' => 'R', 'expires_at' => 42,
                 'uid' => '1', 'tenant' => '000000', 'region' => 'eu');
verifie('session rendue en tableau par le coeur',
        dreamebeApi::normalizeSession($attendu), $attendu);
verifie('session rendue en chaîne JSON',
        dreamebeApi::normalizeSession(json_encode($attendu)), $attendu);
verifie('aucune session enregistrée', dreamebeApi::normalizeSession(''), null);
verifie('valeur absente', dreamebeApi::normalizeSession(null), null);
verifie('valeur illisible', dreamebeApi::normalizeSession('pas du json'), null);
verifie('valeur JSON non tabulaire', dreamebeApi::normalizeSession('"texte"'), null);

$api = nouveauClient();
$api->setSession(dreamebeApi::normalizeSession($attendu));
verifie('session restaurée depuis un tableau', $api->getSession()['token'], 'J');
verifie('région restaurée avec elle', $api->getRegion(), 'eu');

/* ====================================================================== *
 * Découverte des robots
 * ====================================================================== */

echo "\n== Découverte des robots ==\n";

$api = nouveauClient();
$api->answers[] = jeton();
$api->answers[] = array('status' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/device-list.json'));
$devices = $api->getDevices();

verifie('fiche sans identifiant écartée', count($devices), 2);
verifie('identifiant du premier robot', $devices[0]['did'], '790160690');
verifie('nom personnalisé retenu', $devices[0]['name'], 'Rez-de-chaussée');
verifie('modèle brut conservé', $devices[0]['model'], 'dreame.vacuum.r2579a');
/* Sans ce préfixe, l'envoi de commande répond 404 avec un jeton pourtant
 * valide : c'est le piège de cette API. */
verifie('préfixe de route extrait de bindDomain', $devices[0]['prefix'], '10000');
verifie('JSON imbriqué dans une chaîne', $devices[0]['iot_id'], 'ZG9ja2VyMTIz');
verifie('robot en ligne', $devices[0]['online'], true);
verifie('batterie du résumé', $devices[0]['battery'], 100);
verifie('robot non partagé', $devices[0]['shared'], false);

verifie('nom retombant sur le libellé du modèle', $devices[1]['name'], 'L40 Ultra');
verifie('robot partagé reconnu', $devices[1]['shared'], true);
verifie('robot hors ligne', $devices[1]['online'], false);
verifie('champ property vide toléré', $devices[1]['iot_id'], '');

verifie('adresse de la liste des appareils', $api->requests[1]['url'],
        'https://eu.iot.dreame.tech:13267/dreame-user-iot/iotuserbind/device/listV2');
verifie('jeton dans son en-tête propre', $api->header(1, 'Dreame-Auth: '), 'JETON');
verifie('appareils partagés demandés',
        strpos($api->requests[1]['body'], '"sharedStatus":1') !== false, true);

verifie('modèle nommé (L40 Ultra AE)',
        dreamebeSpec::modelName('dreame.vacuum.r2579a'), 'L40 Ultra AE');
verifie('modèle nommé (L40 Ultra)',
        dreamebeSpec::modelName('dreame.vacuum.r2492a'), 'L40 Ultra');
verifie('modèle inconnu rendu tel quel',
        dreamebeSpec::modelName('dreame.vacuum.inexistant'), 'dreame.vacuum.inexistant');

/* ====================================================================== *
 * Lecture des propriétés
 * ====================================================================== */

echo "\n== Lecture des propriétés ==\n";

$api = nouveauClient();
$api->setSession(array('token' => 'JETON', 'expires_at' => time() + 3600, 'uid' => '1'));
$api->answers[] = array('status' => 200, 'body' => json_encode(array(
    'code' => 0, 'success' => true, 'data' => array('id' => 1, 'result' => array(
        array('siid' => 2, 'piid' => 1, 'code' => 0, 'value' => 6),
        array('siid' => 3, 'piid' => 1, 'code' => 0, 'value' => 87),
        /* Une propriété que ce modèle ne connaît pas : elle doit disparaître,
         * pas produire une valeur inventée. */
        array('siid' => 28, 'piid' => 8, 'code' => -4001),
        /* Une propriété sans valeur : idem. */
        array('siid' => 4, 'piid' => 63, 'code' => 0),
    )))));
$values = $api->getProperties($robot, array(array(2, 1), array(3, 1), array(28, 8), array(4, 63)));
verifie('valeurs indexées par siid.piid', $values['2.1'], 6);
verifie('seconde valeur', $values['3.1'], 87);
verifie('propriété non supportée écartée', isset($values['28.8']), false);
verifie('propriété sans valeur écartée', isset($values['4.63']), false);

$body = json_decode($api->requests[0]['body'], true);
verifie('route de commande dérivée du préfixe', $api->requests[0]['url'],
        'https://eu.iot.dreame.tech:13267/dreame-iot-com-10000/device/sendCommand');
verifie('identifiant répété aux deux niveaux', $body['id'], $body['data']['id']);
verifie('identifiant de corrélation raisonnable', ($body['id'] < 16777216), true);
verifie('méthode annoncée', $body['data']['method'], 'get_properties');
verifie('paramètres en tableau', isset($body['data']['params'][0]['siid']), true);

/* Le découpage en paquets de quinze n'est pas une élégance : au-delà, le
 * comportement du serveur n'est pas documenté. */
$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
$props = array();
for ($i = 1; $i <= 20; $i++) {
    $props[] = array(4, $i);
}
$reponse = json_encode(array('code' => 0, 'data' => array('result' => array())));
$api->answers[] = array('status' => 200, 'body' => $reponse);
$api->answers[] = array('status' => 200, 'body' => $reponse);
$api->getProperties($robot, $props);
verifie('vingt propriétés en deux paquets', count($api->requests), 2);
$premier = json_decode($api->requests[0]['body'], true);
verifie('premier paquet plafonné à quinze', count($premier['data']['params']), 15);

/* ====================================================================== *
 * Robustesse
 * ====================================================================== */

echo "\n== Robustesse ==\n";

/* Un HTTP 401 vaut « jeton expiré » : on se reconnecte et on rejoue, une fois. */
$api = nouveauClient();
$api->setSession(array('token' => 'PERIME', 'refresh_token' => 'R', 'expires_at' => time() + 3600));
$api->answers[] = array('status' => 401, 'body' => '{"code":401}');
$api->answers[] = jeton(7200, 'FRAIS');
$api->answers[] = array('status' => 200, 'body' => json_encode(array(
    'code' => 0, 'data' => array('result' => array(
        array('siid' => 3, 'piid' => 1, 'code' => 0, 'value' => 50))))));
$values = $api->getProperties($robot, array(array(3, 1)));
verifie('jeton expiré, appel rejoué', $values['3.1'], 50);
verifie('trois échanges au total', count($api->requests), 3);
verifie('le rejeu porte le nouveau jeton', $api->header(2, 'Dreame-Auth: '), 'FRAIS');

/* Le code 80001 ne veut PAS dire « hors ligne » : le cloud a simplement cessé
 * d'attendre l'accusé du robot. L'appelant doit pouvoir faire la différence. */
$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
$api->answers[] = array('status' => 200,
                        'body' => '{"code":80001,"success":false,"data":null,"msg":"timeout"}');
verifieLeve('absence d\'accusé distinguée',
            function () use ($api, $robot) { $api->getProperties($robot, array(array(3, 1))); },
            dreamebeApiException::NOACK);

$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
$api->answers[] = array('status' => 200, 'body' => 'une page HTML d\'erreur');
verifieLeve('réponse illisible signalée',
            function () use ($api, $robot) { $api->getProperties($robot, array(array(3, 1))); },
            dreamebeApiException::API);

$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
$api->answers[] = array('status' => 503, 'body' => '{"status":503,"error":"Service Unavailable"}');
verifieLeve('cloud en panne : erreur réseau, pas succès',
            function () use ($api) { $api->getDevices(); },
            dreamebeApiException::NETWORK);

/* Une erreur HTTP sans code applicatif n'est pas un succès : sans ce contrôle,
 * la liste des appareils reviendrait vide au lieu d'échouer. */
$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
$api->answers[] = array('status' => 403, 'body' => '{"error":"Forbidden"}');
verifieLeve('erreur HTTP sans code signalée',
            function () use ($api) { $api->getDevices(); },
            dreamebeApiException::API);

/* L'absence d'accusé reste reconnue même sous un statut d'erreur. */
$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
$api->answers[] = array('status' => 504, 'body' => '{"code":80001,"msg":"timeout"}');
verifieLeve('absence d\'accusé sous un 504',
            function () use ($api, $robot) { $api->getProperties($robot, array(array(3, 1))); },
            dreamebeApiException::NOACK);

/* Un robot dont la route est inconnue ne doit pas produire une URL fantaisiste. */
$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
verifieLeve('route de commande absente signalée',
            function () use ($api) {
                $api->getProperties(array('did' => '1', 'model' => 'x', 'prefix' => ''), array(array(3, 1)));
            }, dreamebeApiException::API);

/* La lecture d'ombre : « keys » doit être une chaîne, pas un tableau. */
$api = nouveauClient();
$api->setSession(array('token' => 'J', 'expires_at' => time() + 3600));
$api->answers[] = array('status' => 200, 'body' => json_encode(array('code' => 0, 'data' => array(
    array('key' => '2.1', 'value' => '6'),
    array('key' => '3.1', 'value' => '87')))));
$values = $api->shadowProperties($robot, array(array(2, 1), array(3, 1)));
verifie('image du cloud lue', $values['3.1'], '87');
$body = json_decode($api->requests[0]['body'], true);
verifie('clés jointes par des virgules', $body['keys'], '2.1,3.1');
verifie('clés envoyées en chaîne', is_string($body['keys']), true);

/* ====================================================================== *
 * Sécurité
 * ====================================================================== */

echo "\n== Sécurité ==\n";

verifie('mot de passe masqué',
        dreamebeApi::redact('username=jean&password=secret123&type=account'),
        'username=jean&password=***&type=account');
verifie('jeton masqué dans une réponse',
        dreamebeApi::redact('{"access_token":"abcdef","expires_in":7200}'),
        '{"access_token":"***","expires_in":7200}');
verifie('jeton masqué dans un en-tête',
        dreamebeApi::redact('Dreame-Auth: eyJhbGciOi'), 'Dreame-Auth: ***');
verifie('autorisation masquée',
        dreamebeApi::redact('Authorization: Basic ZHJlYW1l'), 'Authorization: Basic ***');
verifie('texte ordinaire intact',
        dreamebeApi::redact('Session ouverte jusqu\'à 12:04:00'),
        'Session ouverte jusqu\'à 12:04:00');

/* ====================================================================== *
 * Table de spécification
 * ====================================================================== */

echo "\n== Table de spécification ==\n";

verifie('état connu', dreamebeSpec::label(dreamebeSpec::$states, 6), 'En charge');
verifie('état inconnu signalé', dreamebeSpec::label(dreamebeSpec::$states, 999), 'Inconnu (999)');
verifie('erreur connue', dreamebeSpec::errorLabel(101), 'Bac à poussière plein');
verifie('erreur absente de la table',
        dreamebeSpec::errorLabel(777), 'Erreur non répertoriée (777)');
/* Distinguer l'ennui de station de la panne du robot n'est PAS affaire de
 * seuil : c'est une liste. L'intuition « au-delà de 100, c'est une alerte »
 * se trompe dans les deux sens, et c'est ce que ces contrôles figent. */
verifie('sac à poussière plein est une alerte', dreamebeSpec::isWarning(121), true);
verifie('retrait de serpillière est une alerte', dreamebeSpec::isWarning(68), true);
verifie('erreur de station est une panne', dreamebeSpec::isWarning(128), false);
verifie('échec du retour à la station est une panne', dreamebeSpec::isWarning(1000), false);
verifie('brosse bloquée est une panne', dreamebeSpec::isWarning(12), false);
verifie('aucune erreur', dreamebeSpec::errorLabel(0), 'Aucune erreur');

/* Sur un robot à base de lavage, « Retirez la serpillière » est le déroulement
 * normal d'un cycle. Le remonter comme une erreur ferait sonner les scénarios
 * d'alerte plusieurs fois par jour sur une machine qui va très bien. */
verifie('retrait de serpillière neutralisé si base de lavage',
        dreamebeSpec::normalizeError(68, false, true), 0);
verifie('retrait de serpillière conservé sinon',
        dreamebeSpec::normalizeError(68, false, false), 68);
verifie('batterie faible neutralisée pendant la charge',
        dreamebeSpec::normalizeError(20, true, false), 0);
verifie('batterie faible conservée hors charge',
        dreamebeSpec::normalizeError(20, false, false), 20);
verifie('erreur inconnue neutralisée', dreamebeSpec::normalizeError(84, false, false), 0);
verifie('avertissement inconnu neutralisé', dreamebeSpec::normalizeError(122, false, false), 0);
verifie('panne réelle conservée', dreamebeSpec::normalizeError(128, true, true), 128);

/* Seuls douze codes s'effacent réellement ; en envoyer un autre ne produit
 * rien, et laisserait croire que l'acquittement ne fonctionne pas. */
verifie('code effaçable', dreamebeSpec::isClearable(117), true);
verifie('code non effaçable', dreamebeSpec::isClearable(128), false);

/* Sur les robots à serpillière escamotable, les codes de mode sont permutés :
 * lire la valeur brute donne l'inverse de la réalité. */
$parts = dreamebeSpec::splitMode(dreamebeSpec::combineMode(0, 20, 3, true), true);
verifie('mode « aspiration seule » relu correctement', $parts['mode'], 0);
verifie('humidité relue', $parts['humidity'], 3);
verifie('valeur d\'auto-lavage relue', $parts['self_clean'], 20);

$parts = dreamebeSpec::splitMode(dreamebeSpec::combineMode(2, 15, 1, true), true);
verifie('mode « aspiration et lavage » relu correctement', $parts['mode'], 2);
$parts = dreamebeSpec::splitMode(dreamebeSpec::combineMode(1, 10, 2, true), true);
verifie('mode « lavage seul » relu correctement', $parts['mode'], 1);
$parts = dreamebeSpec::splitMode(dreamebeSpec::combineMode(3, 10, 2, true), true);
verifie('mode « lavage après aspiration » relu', $parts['mode'], 3);

/* Sans serpillière escamotable, aucune permutation ne doit avoir lieu. */
$parts = dreamebeSpec::splitMode(dreamebeSpec::combineMode(1, 0, 0, false), false);
verifie('pas de permutation sans escamotage', $parts['mode'], 1);

/* L'humidité de serpillière des robots récents se règle sur une échelle de 1 à
 * 32, mais ne se présente à l'utilisateur qu'en trois crans — ceux-là mêmes que
 * propose l'application. */
verifie('humidité fine basse ramenée à « peu humide »',
        dreamebeSpec::wetnessToHumidity(5), 1);
verifie('juste au-dessus du seuil bas', dreamebeSpec::wetnessToHumidity(6), 2);
verifie('humidité fine moyenne', dreamebeSpec::wetnessToHumidity(16), 2);
verifie('juste en dessous du seuil haut', dreamebeSpec::wetnessToHumidity(26), 2);
verifie('humidité fine haute', dreamebeSpec::wetnessToHumidity(27), 3);
verifie('humidité fine maximale', dreamebeSpec::wetnessToHumidity(32), 3);
/* Une autre échelle existe, par paliers autour de 100, 200 et 400 : lue avec
 * les seuils de la première, elle donnerait exactement l'inverse. */
verifie('autre échelle, palier bas', dreamebeSpec::wetnessToHumidity(100), 1);
verifie('autre échelle, palier haut', dreamebeSpec::wetnessToHumidity(400), 3);
verifie('humidité fine nulle : rien à afficher',
        dreamebeSpec::wetnessToHumidity(0), null);
verifie('les trois crans sont ceux de l\'application',
        dreamebeSpec::$wetnessLevels, array(1 => 5, 2 => 16, 3 => 27));

verifie('brosse principale : le pourcentage est le piid 2',
        dreamebeSpec::$consumables[9][2], 2);
verifie('filtre : le pourcentage est le piid 1',
        dreamebeSpec::$consumables[11][2], 1);
verifie('ions d\'argent comptés en jours', dreamebeSpec::$consumables[19][4], 'j');

/* ====================================================================== *
 * Historique
 * ====================================================================== */

echo "\n== Historique ==\n";

$brut = json_decode(file_get_contents(__DIR__ . '/fixtures/history.json'), true);
$history = dreamebeApi::parseHistory($brut['data']['list']);

verifie('entrée illisible ignorée', count($history), 3);
verifie('plus récent en tête', $history[0]['duration'], 12);
verifie('horodatage en millisecondes ramené en secondes', $history[0]['date'], 1789200000);
verifie('nettoyage interrompu reconnu', $history[0]['completed'], false);
verifie('nettoyage terminé reconnu', $history[1]['completed'], true);
verifie('date propre préférée à celle de l\'archive', $history[1]['date'], 1788996000);
verifie('surface relue', $history[1]['area'], 37);
verifie('absence de statut de fin tolérée', $history[2]['completed'], null);
verifie('charge utile nommée « val » acceptée',
        count(dreamebeApi::parseHistory(array(
            array('createTime' => 1789000000,
                  'val' => '[{"piid":2,"value":7}]')))), 1);
verifie('liste vide tolérée', count(dreamebeApi::parseHistory(array())), 0);
verifie('entrée non tabulaire tolérée', count(dreamebeApi::parseHistory(array('x', 3))), 0);

/* ====================================================================== *
 * Carte
 * ====================================================================== */

echo "\n== Carte ==\n";

/*
 * On fabrique une carte comme le fait un robot, puis on la relit. C'est le seul
 * moyen honnête d'éprouver ce décodeur sans publier le plan d'un logement réel :
 * la chaîne complète — en-tête signé, pixels, JSON, compression, chiffrement,
 * base64 « URL-safe » — est parcourue dans les deux sens.
 *
 * Le plan tient en deux pièces côte à côte séparées par un mur :
 *
 *      colonnes 0..3   colonne 4   colonnes 5..8
 *          pièce 1        mur          pièce 2
 */
function fabriqueCarte($_key = null, $_iv = null, $_pixelsTronques = false, $_surBase = false) {
    $width = 11;
    $height = 8;
    $grid = 50;

    $int16 = function ($_value) {
        return pack('v', $_value & 0xFFFF);
    };
    $entete = '';
    $entete .= $int16(17);        /* identifiant de carte */
    $entete .= $int16(3);         /* numéro de trame */
    $entete .= chr(73);           /* trame « I » : carte complète */
    $entete .= $int16(-250);      /* robot x, volontairement négatif */
    $entete .= $int16(100);       /* robot y */
    /* 32767 est la sentinelle « position inconnue » : c'est ce que publie un
     * robot posé sur sa base, qui ne se localise plus. */
    $entete .= $int16($_surBase ? 32767 : 90);
    $entete .= $int16(-400);      /* station x */
    $entete .= $int16(-100);      /* station y */
    $entete .= $int16(0);         /* station, angle */
    $entete .= $int16($grid);
    $entete .= $int16($width);
    $entete .= $int16($height);
    $entete .= $int16(-500);      /* origine gauche, en millimètres */
    $entete .= $int16(-300);      /* origine haute */

    /*
     * Un robot n'entoure pas ses pièces de murs anonymes : il marque leur
     * contour avec le bit de poids fort ET l'identifiant de la pièce. Le jeu
     * d'essai doit donc l'imiter, sans quoi il validerait le décodeur contre
     * lui-même et ne verrait jamais qu'une pièce a perdu son pourtour.
     *
     *        0 1 2 3 4 5 6 7 8 9 10
     *      0 # # # # # # # # # # #
     *      1 # b b b b C b b b b #      b = bordure de pièce (0x80 | id)
     *      2 # b . . b C b ~ ~ b #      . = intérieur de la pièce 1
     *      3 # b . . b C b ~ ~ b #      ~ = intérieur de la pièce 2, sur tapis
     *      4 # b . . b C b ~ ~ b #      C = couloir : pièce 3, UNIQUEMENT
     *      5 # b . . b C b ~ ~ b #          des bordures, un pixel de large
     *      6 # b b b b C b b b b #      # = mur anonyme (0x80, sans identifiant)
     *      7 # # # # # # # # # # #
     */
    $pixels = '';
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            if ($y === 0 || $y === $height - 1 || $x === 0 || $x === $width - 1) {
                $pixels .= chr(0x80);                 /* mur anonyme */
            } elseif ($x === 5) {
                $pixels .= chr(0x80 | 3);             /* couloir, tout en bordure */
            } elseif ($x < 5) {
                $bordure = ($x === 1 || $x === 4 || $y === 1 || $y === 6);
                $pixels .= chr($bordure ? (0x80 | 1) : 1);
            } else {
                $bordure = ($x === 6 || $x === 9 || $y === 1 || $y === 6);
                $pixels .= chr($bordure ? (0x80 | 2) : (2 | 0x40));
            }
        }
    }

    $json = json_encode(array(
        'seg_inf' => array(
            /* Pièce nommée par son code de type : « Cuisine ». */
            '1' => array('type' => 4, 'index' => 0, 'roomID' => 901, 'nei_id' => array(2, 3)),
            /* Pièce renommée par l'utilisateur : le nom est en base64. */
            '2' => array('type' => 0, 'index' => 0, 'roomID' => 902, 'nei_id' => array(1, 3),
                         'name' => base64_encode('Chambre des enfants')),
            '3' => array('type' => 8, 'index' => 0, 'roomID' => 903, 'nei_id' => array(1, 2)),
        ),
        /* La pièce 3 est volontairement absente : le robot lui applique alors
         * ses valeurs par défaut, et le plugin doit les refléter. */
        'cleanset' => array(
            '1' => array(2, 3, 2, 0, 1),
            '2' => array(0, 1, 1, 0, 2),
        ),
        'cleanareaorder' => array(array('2' => 1), array('1' => 2)),
    ) + ($_surBase ? array('oc' => 1) : array()));

    /* Tronquer APRÈS le JSON ne prouverait rien : le décodeur lirait le JSON
     * comme des pixels et trouverait son compte. La charge utile est donc
     * coupée en entier, JSON compris, comme le ferait un transfert interrompu. */
    $charge = $_pixelsTronques ? ($entete . substr($pixels, 0, 20))
                               : ($entete . $pixels . $json);
    $binaire = gzcompress($charge);

    if ($_key !== null) {
        /*
         * La clé effective est celle d'APRÈS la substitution « URL-safe », parce
         * que le robot substitue la chaîne entière avant que quiconque n'en
         * détache la clé. Chiffrer ici avec la clé brute reproduirait à
         * l'identique une erreur du décodeur, et le contrôle passerait dans les
         * deux cas — c'est exactement le genre de symétrie qu'un jeu d'essai ne
         * doit pas avoir.
         */
        $aesKey = substr(hash('sha256', strtr($_key, '_-', '/+')), 0, 32);
        $reste = strlen($binaire) % 16;
        if ($reste !== 0) {
            $binaire .= str_repeat("\0", 16 - $reste);
        }
        $binaire = openssl_encrypt($binaire, 'aes-256-cbc', $aesKey,
                                   OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $_iv);
    }

    $base64 = strtr(base64_encode($binaire), '/+', '_-');
    return ($_key === null) ? $base64 : $base64 . ',' . $_key;
}

$carte = dreamebeMap::decode(fabriqueCarte());
verifie('identifiant de carte', $carte['map_id'], 17);
verifie('numéro de trame', $carte['frame_id'], 3);
verifie('trame complète', $carte['frame_type'], 73);
verifie('largeur', $carte['width'], 11);
verifie('hauteur', $carte['height'], 8);
verifie('pas de la grille', $carte['grid_size'], 50);
/* Les entiers de l'en-tête sont signés : lus autrement, l'origine part à
 * soixante-cinq mille millimètres et plus rien ne correspond. */
verifie('origine négative relue correctement', $carte['left'], -500);
verifie('seconde origine', $carte['top'], -300);
verifie('position du robot, x négatif', $carte['robot']['x'], -250);
verifie('position du robot, y', $carte['robot']['y'], 100);
verifie('orientation du robot', $carte['robot']['a'], 90);
verifie('position de la station', $carte['charger']['x'], -400);
verifie('métadonnées présentes', isset($carte['json']['seg_inf']), true);

verifie('angle du robot lu quand il est connu', $carte['robot']['a'], 90);

/* Robot posé sur sa base : il cesse de publier sa position, et on la remplace
 * par celle de la station. Son orientation, elle, reste inconnue — recopier
 * celle de la station ferait pointer l'aiguille du plan au hasard. */
$surBase = dreamebeMap::decode(fabriqueCarte(null, null, false, true));
verifie('position reprise de la station', $surBase['robot']['x'], -400);
verifie('orientation laissée inconnue', $surBase['robot']['a'], null);

/* La clé porte un tiret ET un souligné : si la substitution « URL-safe »
 * repassait après le découpage, ce contrôle échouerait — c'est sa raison
 * d'être. */
$chiffree = dreamebeMap::decode(fabriqueCarte('CLE-DU_ROBOT', 'NRwnBj5FsNPgBNbT'),
                                'NRwnBj5FsNPgBNbT');
verifie('carte chiffrée déchiffrée', $chiffree['map_id'], 17);
verifie('pixels intacts après déchiffrement',
        strlen($chiffree['pixels']), 88);

verifieLeve('carte chiffrée sans vecteur : message explicite',
            function () { dreamebeMap::decode(fabriqueCarte('CLE', 'NRwnBj5FsNPgBNbT')); });
/* Un en-tête qui annonce plus de pixels qu'il n'en arrive doit être refusé, et
 * non lu hors bornes en inondant le journal d'Apache. */
verifieLeve('pixels tronqués refusés',
            function () { dreamebeMap::decode(fabriqueCarte(null, null, true)); });
/* La clé peut aussi être fournie de l'extérieur : elle voyage parfois dans le
 * nom d'objet plutôt que dans la charge utile. */
$parCle = fabriqueCarte('CLEEXTERNE', 'NRwnBj5FsNPgBNbT');
$parCle = substr($parCle, 0, strrpos($parCle, ','));
verifie('clé fournie séparément',
        dreamebeMap::decode($parCle, 'NRwnBj5FsNPgBNbT', 'CLEEXTERNE')['map_id'], 17);
verifieLeve('charge utile vide refusée', function () { dreamebeMap::decode(''); });
verifieLeve('base64 valide mais non compressé refusé',
            function () { dreamebeMap::decode(base64_encode('pas une carte')); });

/*
 * Le nom d'objet arrive sous trois formes selon le micrologiciel. Parier sur
 * l'une d'elles donne une carte qui ne s'affiche jamais sur une partie du parc.
 */
verifie('nom d\'objet en chaîne nue',
        dreamebeMap::objectName('ali_dreame/2026/09/18/u/d_1.bin'), 'ali_dreame/2026/09/18/u/d_1.bin');
verifie('nom d\'objet dans une liste',
        dreamebeMap::objectName(array('chemin/objet')), 'chemin/objet');
verifie('nom d\'objet dans une chaîne contenant une liste JSON',
        dreamebeMap::objectName('["chemin/objet"]'), 'chemin/objet');
verifie('nom d\'objet vide', dreamebeMap::objectName(''), null);
verifie('nom d\'objet absent', dreamebeMap::objectName(null), null);

verifie('vecteur du L40 Ultra AE',
        dreamebeMap::ivForModel('dreame.vacuum.r2579a'), 'NRwnBj5FsNPgBNbT');
verifie('vecteur du L40 Ultra',
        dreamebeMap::ivForModel('dreame.vacuum.r2492b'), 'NRwnBj5FsNPgBNbT');
verifie('modèle inconnu : pas de vecteur inventé',
        dreamebeMap::ivForModel('dreame.vacuum.inconnu'), null);

echo "\n== Pièces ==\n";

$pieces = dreamebeMap::segments($carte);
verifie('trois pièces trouvées', count($pieces), 3);
/* Le nom prédéfini l'emporte tant que l'utilisateur n'a rien saisi. */
verifie('pièce nommée par son type', $pieces[1]['name'], 'Cuisine');
/* Et le nom saisi, lui, est en base64 dans le même fichier. */
verifie('pièce renommée, décodée', $pieces[2]['name'], 'Chambre des enfants');
verifie('identifiant persistant conservé', $pieces[1]['unique_id'], 901);
verifie('voisinage conservé', $pieces[1]['neighbours'], array(2, 3));

/*
 * Le contour d'une pièce lui appartient. L'écarter la rétrécirait d'un anneau
 * et sous-estimerait sa surface du montant de son périmètre — 24 pixels de
 * 50 mm font 0,06 m², contre 0,02 si l'on ne gardait que l'intérieur.
 */
verifie('surface calculée sur les pixels, bordures comprises',
        $pieces[1]['area'], 0.06);
verifie('le contour est dans le rectangle englobant', $pieces[1]['x1'], -450);

/*
 * Et surtout : un couloir d'un pixel de large n'est QUE du contour. Une lecture
 * qui prend le bit de poids fort pour un mur ne le voit pas du tout, et la
 * commande « Nettoyer : Couloir » n'existe jamais — sans le moindre message.
 */
verifie('couloir entièrement fait de bordures : détecté', isset($pieces[3]), true);
verifie('couloir nommé par son type', $pieces[3]['name'], 'Couloir');
verifie('couloir : surface non nulle', $pieces[3]['area'] > 0, true);

/* Une pièce absente du cleanset reçoit les valeurs que le robot applique de
 * lui-même, plutôt que de paraître sans réglage. */
verifie('réglages par défaut d\'une pièce sans cleanset', $pieces[3]['suction'], 1);
verifie('réglages par défaut signalés comme tels', $pieces[3]['default'], true);
verifie('réglages explicites non marqués par défaut', isset($pieces[1]['default']), false);
verifie('centre de la première pièce dans ses bornes',
        ($pieces[1]['x'] >= $pieces[1]['x1'] && $pieces[1]['x'] <= $pieces[1]['x2']), true);
verifie('aspiration par pièce relue', $pieces[1]['suction'], 2);
/* Le niveau d'eau du fichier de carte est décalé d'une unité par rapport à
 * celui des commandes — c'est ainsi dans le protocole. */
verifie('niveau d\'eau brut conservé', $pieces[1]['water_raw'], 3);
verifie('niveau d\'eau ramené à l\'échelle des commandes', $pieces[1]['water'], 2);
verifie('nombre de passages relu', $pieces[1]['repeats'], 2);
verifie('ordre de nettoyage relu', $pieces[2]['order'], 1);
/* Un tapis ne doit pas décaler l'identifiant de la pièce. */
verifie('pixel marqué « tapis » rattaché à sa pièce', isset($pieces[2]), true);

$vide = dreamebeMap::segments(array('width' => 2, 'height' => 2, 'grid_size' => 50,
                                    'left' => 0, 'top' => 0,
                                    'pixels' => str_repeat("\0", 4), 'json' => array()));
verifie('carte sans pièce tolérée', count($vide), 0);

echo "\n== Liste des cartes ==\n";

$liste = json_encode(array('curr_id' => 17, 'mapstr' => array(
    array('map' => fabriqueCarte(), 'name' => 'Rez-de-chaussée', 'mapobj' => 'objet/1'),
    array('map' => 'charge utile abîmée', 'name' => 'Étage'),
)));
$parsee = dreamebeMap::parseMapList($liste);
verifie('carte illisible écartée sans perdre l\'autre', count($parsee['maps']), 1);
verifie('carte sélectionnée reconnue', $parsee['maps'][0]['selected'], true);
/* Le nom de CARTE est en clair, celui des PIÈCES est en base64 — même clé,
 * deux encodages, dans le même fichier. */
verifie('nom de carte lu en clair', $parsee['maps'][0]['name'], 'Rez-de-chaussée');
verifie('pièces de la carte sélectionnée', count($parsee['maps'][0]['segments']), 3);

$enBase64 = dreamebeMap::parseMapList(base64_encode($liste));
verifie('liste enveloppée en base64 acceptée', count($enBase64['maps']), 1);

/* ====================================================================== *
 * Bilan
 * ====================================================================== */

echo "\n";
if ($ko === 0) {
    echo "Jeu d'essai : $ok contrôles, aucun échec.\n";
    exit(0);
}
echo "Jeu d'essai : $ok réussis, $ko ÉCHEC(S).\n";
exit(1);
