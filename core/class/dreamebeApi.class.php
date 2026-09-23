<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Client du cloud DreameHome.
 *
 * Cette classe ne connaît RIEN de Jeedom : ni eqLogic, ni cmd, ni log::add. Elle
 * reçoit ses identifiants, rend des tableaux PHP, et signale ses ennuis par des
 * exceptions. C'est ce qui permet de la rejouer hors ligne sur des fixtures, et
 * c'est aussi ce qui la rend réparable seule le jour où Dreame changera son API :
 * tout ce qui suit est du protocole, et rien d'autre.
 *
 * Le protocole a été relevé sur trois implémentations libres indépendantes, qui
 * concordent : Tasshack/dreame-vacuum (branche dev, MIT), TA2k/ioBroker.dreame
 * (MIT) et sandraschi/dreame-mcp (MIT). Aucun code n'en a été recopié.
 *
 * Il n'y a ni signature de requête, ni nonce, ni chiffrement : c'est un OAuth2
 * « password grant » classique, avec deux particularités qui font échouer toute
 * tentative naïve et qui sont documentées à leur place plus bas — le port 13267,
 * et le préfixe de route dérivé de bindDomain.
 */

class dreamebeApiException extends Exception {
    /*
     * Le cloud n'a pas reçu l'accusé du robot dans le délai qu'il s'accorde.
     *
     * Ce code ne veut PAS dire que le robot est hors ligne, et c'est le
     * contresens le plus coûteux de cette API : le serveur abandonne son attente
     * au bout de quelques secondes, alors que le robot, lui, exécute l'ordre et
     * publie son changement d'état juste après. Conclure « hors ligne » sur ce
     * code fait clignoter tous les états du plugin pendant un nettoyage, et fait
     * répéter des ordres qui sont déjà partis.
     *
     * L'appelant doit le traiter comme « pas de réponse cette fois-ci » : garder
     * l'état précédent, et se rabattre sur l'image que le cloud garde du robot.
     */
    const NOACK = 1;
    /* Identifiants refusés. Retenter ne servira à rien : c'est à l'utilisateur
     * de corriger, et le plugin doit cesser d'interroger le cloud. */
    const AUTH = 2;
    /* Réseau, DNS, TLS, délai dépassé. Transitoire par nature : on retente. */
    const NETWORK = 3;
    /* Le cloud a répondu, mais pas ce qu'on attendait. */
    const API = 4;
}

class dreamebeApi {

    /* ------------------------------------------------------------------ *
     * Constantes du protocole
     * ------------------------------------------------------------------ */

    /* Le port n'est pas 443. Toute requête vers le 443 tombe dans le vide : c'est
     * la première chose à vérifier quand « rien ne répond ». */
    const PORT = 13267;
    const HOST_SUFFIX = '.iot.dreame.tech';

    /* Le mot de passe n'est jamais envoyé en clair : le serveur attend le MD5 du
     * mot de passe concaténé à ce sel, figé dans l'application mobile. Ce n'est
     * pas un secret — les trois implémentations de référence le publient — et ce
     * n'est pas non plus une protection : c'est simplement le format attendu. */
    const PASSWORD_SALT = 'RAylYC%fmSKp7%Tq';

    /* Le couple client OAuth de l'application Dreame, identique pour tous les
     * comptes. Il reste en place sur TOUS les appels, y compris après connexion :
     * le jeton de l'utilisateur voyage à côté, dans l'en-tête Dreame-Auth.
     * Le remplacer par le jeton est l'erreur qui rend tous les appels 401. */
    const CLIENT_BASIC = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';

    /* Le serveur refuse une requête sans agent utilisateur. Sa valeur exacte,
     * elle, ne semble pas contrôlée : les implémentations de référence en
     * annoncent trois différentes et fonctionnent toutes. */
    const USER_AGENT = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';

    /*
     * En-tête réclamé par le serveur chinois, et par lui seul. Sa valeur est une
     * constante de l'application, identique pour tous les comptes. Sans elle,
     * un compte de la région « cn » échoue à la connexion sans message
     * exploitable, et l'on conclut à tort à de mauvais identifiants.
     */
    const RLC_CN = '1c80b3787b2266776bcdc481f37d8fa42ba10a30af81a6df-1';

    /* Dreame = 000000. Les marques sœurs (Mova, Trouver) utilisent le même
     * protocole avec un autre locataire et un autre domaine ; le plugin ne les
     * gère pas, mais la constante est nommée pour que ça se voie. */
    const TENANT_DREAME = '000000';

    /* Régions proposées par l'application DreameHome. Le compte n'existe que dans
     * une seule : se tromper de région donne « identifiants invalides », pas
     * « mauvaise région », d'où l'importance de le dire dans l'interface. */
    public static $regions = array('eu', 'us', 'cn', 'ru', 'sg', 'kr');

    /* Une lecture de propriétés est découpée en paquets de cette taille. La
     * référence Home Assistant s'en tient à quinze ; au-delà, le comportement du
     * serveur n'est pas documenté et rien ne justifie de le découvrir en
     * production. */
    const PROPERTIES_PER_CALL = 15;

    /* ------------------------------------------------------------------ *
     * État
     * ------------------------------------------------------------------ */

    private $_username = '';
    private $_password = '';
    private $_region = 'eu';

    /* Jeton d'accès, jeton de renouvellement, échéance, identifiant utilisateur
     * et locataire. L'appelant les conserve entre deux requêtes HTTP de Jeedom
     * et les rend par setSession() : sans cela, chaque cycle de polling
     * recommencerait par une authentification complète, ce que le cloud Dreame
     * n'apprécie pas (limitation de débit observée sur l'endpoint de login). */
    private $_token = '';
    private $_refreshToken = '';
    private $_expiresAt = 0;
    private $_uid = '';
    private $_tenant = self::TENANT_DREAME;

    /* Vrai dès qu'un jeton a changé : l'appelant sait alors qu'il doit
     * réenregistrer la session, et seulement à ce moment-là. */
    private $_sessionDirty = false;

    /* Identifiant de corrélation des commandes. Le serveur le renvoie tel quel ;
     * il n'a pas besoin d'être imprévisible, seulement de varier. */
    private $_requestId = 0;

    /* Journalisation : une fonction (niveau, message) fournie par l'appelant.
     * La classe n'appelle jamais log::add() elle-même — c'est ce qui la garde
     * utilisable hors de Jeedom. */
    private $_logger = null;

    private $_connectTimeout = 5;
    private $_timeout = 20;

    public function __construct($_username = '', $_password = '', $_region = 'eu') {
        $this->_username = trim($_username);
        $this->_password = $_password;
        $this->setRegion($_region);
        $this->_requestId = random_int(1000, 9000);
    }

    public function setRegion($_region) {
        $_region = strtolower(trim((string) $_region));
        $this->_region = in_array($_region, self::$regions, true) ? $_region : 'eu';
        return $this;
    }

    public function getRegion() {
        return $this->_region;
    }

    public function setLogger($_logger) {
        $this->_logger = $_logger;
        return $this;
    }

    public function setTimeouts($_connect, $_total) {
        $this->_connectTimeout = max(1, (int) $_connect);
        $this->_timeout = max(2, (int) $_total);
        return $this;
    }

    private function log($_level, $_message) {
        if (is_callable($this->_logger)) {
            call_user_func($this->_logger, $_level, $_message);
        }
    }

    /* ------------------------------------------------------------------ *
     * Session
     * ------------------------------------------------------------------ */

    /*
     * Remet une session enregistrée sous forme de tableau, quelle que soit la
     * forme sous laquelle elle revient.
     *
     * Jeedom range la configuration d'un plugin en base sous forme de texte,
     * mais config::byKey() termine par is_json() : une valeur qui ressemble à du
     * JSON est DÉCODÉE avant d'être rendue. Une session enregistrée par
     * json_encode() revient donc en tableau, pas en chaîne — et lui appliquer
     * json_decode() lève une TypeError fatale en PHP 8.
     *
     * Le piège est sournois parce qu'il ne se manifeste pas au premier essai :
     * tant qu'aucune session n'existe, la clé rend une chaîne vide et tout va
     * bien. Ce n'est qu'à partir de la première connexion réussie que chaque
     * appel suivant meurt — y compris celui de la page Santé, dont le coeur
     * n'attrape que les Exception et pas les Error.
     */
    public static function normalizeSession($_value) {
        if (is_array($_value)) {
            return $_value;
        }
        if (is_string($_value) && trim($_value) !== '') {
            $decoded = json_decode($_value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /*
     * Restaure une session enregistrée. Les champs absents sont ignorés : une
     * session partielle (par exemple un jeton de renouvellement seul, après une
     * mise à jour du plugin) doit rester exploitable plutôt que de tout jeter.
     */
    public function setSession($_session) {
        if (!is_array($_session)) {
            return $this;
        }
        $this->_token = isset($_session['token']) ? (string) $_session['token'] : '';
        $this->_refreshToken = isset($_session['refresh_token']) ? (string) $_session['refresh_token'] : '';
        $this->_expiresAt = isset($_session['expires_at']) ? (int) $_session['expires_at'] : 0;
        $this->_uid = isset($_session['uid']) ? (string) $_session['uid'] : '';
        if (!empty($_session['tenant'])) {
            $this->_tenant = (string) $_session['tenant'];
        }
        if (!empty($_session['region'])) {
            $this->setRegion($_session['region']);
        }
        $this->_sessionDirty = false;
        return $this;
    }

    public function getSession() {
        return array(
            'token' => $this->_token,
            'refresh_token' => $this->_refreshToken,
            'expires_at' => $this->_expiresAt,
            'uid' => $this->_uid,
            'tenant' => $this->_tenant,
            'region' => $this->_region,
        );
    }

    public function isSessionDirty() {
        return $this->_sessionDirty;
    }

    public function getUid() {
        return $this->_uid;
    }

    /* ------------------------------------------------------------------ *
     * Authentification
     * ------------------------------------------------------------------ */

    public function getApiUrl() {
        return 'https://' . $this->_region . self::HOST_SUFFIX . ':' . self::PORT;
    }

    /*
     * Le mot de passe attendu par le serveur.
     *
     * Isolé en méthode publique statique pour une seule raison : c'est la
     * transformation qu'un jeu d'essai doit pouvoir vérifier sans compte réel.
     */
    public static function hashPassword($_password) {
        return md5($_password . self::PASSWORD_SALT);
    }

    /*
     * Ouvre ou renouvelle la session.
     *
     * Le renouvellement passe par le même endpoint que la connexion, au
     * grant_type près. On tente d'abord le jeton de renouvellement : il évite de
     * présenter le mot de passe, et surtout il évite de marteler l'endpoint de
     * connexion, dont on sait qu'il limite le débit.
     */
    public function login($_forcePassword = false) {
        $useRefresh = (!$_forcePassword && $this->_refreshToken !== '');

        if ($useRefresh) {
            $body = 'platform=IOS&scope=all&grant_type=refresh_token'
                  . '&refresh_token=' . rawurlencode($this->_refreshToken);
        } else {
            if ($this->_username === '' || $this->_password === '') {
                throw new dreamebeApiException('Compte DreameHome non configuré.', dreamebeApiException::AUTH);
            }
            $body = 'platform=IOS&scope=all&grant_type=password'
                  . '&username=' . rawurlencode($this->_username)
                  . '&password=' . rawurlencode(self::hashPassword($this->_password))
                  . '&type=account';
        }

        $this->log('debug', 'Authentification DreameHome (' . ($useRefresh ? 'renouvellement' : 'mot de passe')
                          . ') sur ' . $this->getApiUrl());

        $headers = array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: ' . self::CLIENT_BASIC,
            'Tenant-Id: ' . $this->_tenant,
        );
        if ($this->_region === 'cn') {
            $headers[] = 'Dreame-Rlc: ' . self::RLC_CN;
        }

        $answer = $this->http('POST', $this->getApiUrl() . '/dreame-auth/oauth/token', $headers, $body);

        /* Un serveur en panne ou qui demande de ralentir ne juge pas les
         * identifiants. Le lire comme un refus ferait jeter un jeton de
         * renouvellement valide, puis suspendre le plugin en accusant le mot de
         * passe pour un simple incident chez Dreame. */
        if (self::isTransient($answer['status'])) {
            throw new dreamebeApiException('Authentification DreameHome indisponible (HTTP ' . $answer['status']
                                           . '), nouvel essai au prochain cycle.',
                                           dreamebeApiException::NETWORK);
        }

        $data = json_decode($answer['body'], true);
        if (!is_array($data)) {
            throw new dreamebeApiException('Réponse d\'authentification illisible (HTTP ' . $answer['status'] . ').',
                                           dreamebeApiException::API);
        }

        if (empty($data['access_token'])) {
            /*
             * Un jeton de renouvellement périmé n'est pas une erreur d'identifiants :
             * il faut repartir du mot de passe avant de conclure quoi que ce soit.
             * Sans ce rattrapage, une simple absence de quelques semaines suffirait
             * à désactiver le plugin en accusant l'utilisateur.
             */
            $description = isset($data['error_description']) ? (string) $data['error_description'] : '';
            if ($useRefresh) {
                $this->log('info', 'Jeton de renouvellement refusé, nouvelle authentification par mot de passe.');
                $this->_refreshToken = '';
                $this->_sessionDirty = true;
                return $this->login(true);
            }
            throw new dreamebeApiException(
                'Authentification refusée par DreameHome' . ($description !== '' ? ' : ' . $description : '')
                . '. Vérifiez l\'identifiant, le mot de passe et surtout la région du compte.',
                dreamebeApiException::AUTH
            );
        }

        $this->_token = (string) $data['access_token'];
        $this->_refreshToken = isset($data['refresh_token']) ? (string) $data['refresh_token'] : $this->_refreshToken;
        /* La marge de deux minutes est celle de l'implémentation de référence :
         * elle couvre la dérive d'horloge et la durée d'un cycle de polling. */
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->_expiresAt = time() + max(60, $expiresIn - 120);
        if (isset($data['uid'])) {
            $this->_uid = (string) $data['uid'];
        }
        if (!empty($data['tenant_id'])) {
            $this->_tenant = (string) $data['tenant_id'];
        }
        /* Le serveur dit dans quelle région vit réellement le compte. S'il la
         * corrige, la suivre : l'utilisateur a pu se tromper dans la liste, et
         * tous les appels suivants iraient sur un domaine qui ignore son did. */
        if (!empty($data['region']) && in_array(strtolower($data['region']), self::$regions, true)) {
            $this->setRegion($data['region']);
        }
        $this->_sessionDirty = true;

        $this->log('info', 'Session DreameHome ouverte, valable jusqu\'à ' . date('H:i:s', $this->_expiresAt) . '.');
        return true;
    }

    /*
     * Garantit un jeton utilisable avant tout appel. Appelée par chaque méthode
     * publique : aucune n'a le droit de supposer que la session est ouverte.
     */
    private function ensureToken() {
        if ($this->_token === '' || ($this->_expiresAt > 0 && time() >= $this->_expiresAt)) {
            $this->login();
        }
    }

    /* ------------------------------------------------------------------ *
     * Appels applicatifs
     * ------------------------------------------------------------------ */

    /*
     * Un appel JSON authentifié.
     *
     * Le HTTP 401 vaut « jeton expiré » : on se reconnecte une fois et on
     * rejoue. Une seule fois — sur un mot de passe changé, boucler ici
     * reviendrait à marteler l'endpoint de connexion jusqu'au bannissement.
     */
    private function call($_path, $_params = null, $_retryOn401 = true) {
        $this->ensureToken();

        $body = ($_params === null) ? '{}' : json_encode($_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = array(
            'Content-Type: application/json',
            'Authorization: ' . self::CLIENT_BASIC,
            'Tenant-Id: ' . $this->_tenant,
            /* Le jeton voyage brut, sans préfixe « Bearer » : c'est un en-tête
             * propriétaire, pas l'en-tête HTTP standard. */
            'Dreame-Auth: ' . $this->_token,
        );
        if ($this->_region === 'cn') {
            $headers[] = 'Dreame-Rlc: ' . self::RLC_CN;
        }

        $answer = $this->http('POST', $this->getApiUrl() . '/' . ltrim($_path, '/'), $headers, $body);

        if ($answer['status'] == 401) {
            if (!$_retryOn401) {
                throw new dreamebeApiException('Jeton refusé par DreameHome après renouvellement.',
                                               dreamebeApiException::AUTH);
            }
            $this->log('info', 'Jeton expiré, renouvellement puis nouvelle tentative.');
            $this->login();
            return $this->call($_path, $_params, false);
        }

        if ($answer['status'] == 404) {
            /* Sur cette API, un 404 ne veut pas dire « page absente » mais
             * « mauvaise route » : c'est le symptôme d'un préfixe de commande
             * erroné, et le dire évite de chercher du côté du réseau. */
            throw new dreamebeApiException('Route inconnue du cloud DreameHome (' . $_path . ').',
                                           dreamebeApiException::API);
        }

        $data = json_decode($answer['body'], true);
        $code = (is_array($data) && isset($data['code'])) ? (int) $data['code'] : 0;

        /* 80001 et -8 : le cloud a transmis l'ordre mais n'a pas eu l'accusé du
         * robot à temps. Voir le commentaire de NOACK : ce n'est ni un échec de
         * l'ordre, ni une preuve de déconnexion. Testé avant le statut HTTP,
         * qu'un serveur lassé d'attendre peut très bien mettre en 5xx. */
        if ($code === 80001 || $code === -8) {
            throw new dreamebeApiException('Le robot n\'a pas accusé réception dans le délai du cloud.',
                                           dreamebeApiException::NOACK);
        }

        if (self::isTransient($answer['status'])) {
            throw new dreamebeApiException('Cloud DreameHome indisponible (HTTP ' . $answer['status'] . ').',
                                           dreamebeApiException::NETWORK);
        }

        if (!is_array($data)) {
            throw new dreamebeApiException('Réponse illisible du cloud DreameHome (HTTP ' . $answer['status'] . ').',
                                           dreamebeApiException::API);
        }

        if ($code !== 0) {
            $message = isset($data['msg']) ? (string) $data['msg'] : '';
            throw new dreamebeApiException('DreameHome a refusé la requête (code ' . $code
                                           . ($message !== '' ? ', ' . $message : '') . ').',
                                           dreamebeApiException::API);
        }

        /* Une erreur HTTP dont le corps est du JSON sans code : ce n'est pas un
         * succès pour autant. La laisser passer rendrait, par exemple, une
         * liste d'appareils vide au lieu d'un échec. */
        if ($answer['status'] >= 400) {
            throw new dreamebeApiException('DreameHome a refusé la requête (HTTP ' . $answer['status'] . ').',
                                           dreamebeApiException::API);
        }

        return $data;
    }

    /*
     * Les robots du compte.
     *
     * Rend une liste normalisée : le reste du plugin n'a pas à connaître la forme
     * exacte de la réponse, qui imbrique une pagination et une chaîne JSON dans
     * un champ de chaîne.
     */
    public function getDevices() {
        $data = $this->call('dreame-user-iot/iotuserbind/device/listV2', array(
            /* Inclure les appareils partagés : un robot peut avoir été partagé
             * par le conjoint, et il est alors parfaitement pilotable. */
            'sharedStatus' => 1,
            'current' => 1,
            'size' => 100,
            'timestamp' => (int) round(microtime(true) * 1000),
        ));

        $records = array();
        if (isset($data['data']['page']['records']) && is_array($data['data']['page']['records'])) {
            $records = $data['data']['page']['records'];
        } elseif (isset($data['data']['records']) && is_array($data['data']['records'])) {
            /* Forme observée sans pagination sur certains comptes. */
            $records = $data['data']['records'];
        } elseif (isset($data['data']) && is_array($data['data']) && isset($data['data'][0])) {
            $records = $data['data'];
        }

        $devices = array();
        foreach ($records as $record) {
            $device = self::parseDevice($record);
            if ($device !== null) {
                $devices[] = $device;
            }
        }
        $this->log('debug', count($devices) . ' appareil(s) rendu(s) par le compte.');
        return $devices;
    }

    /*
     * Normalise une fiche d'appareil.
     *
     * Statique et sans effet de bord : c'est la méthode que le jeu d'essai
     * rejoue sur des fixtures.
     */
    public static function parseDevice($_record) {
        if (!is_array($_record) || empty($_record['did'])) {
            return null;
        }

        $name = '';
        if (!empty($_record['customName'])) {
            $name = (string) $_record['customName'];
        } elseif (!empty($_record['deviceInfo']['displayName'])) {
            $name = (string) $_record['deviceInfo']['displayName'];
        }

        /* bindDomain porte deux informations en une : l'hôte du courtier de
         * notifications, et surtout son premier label, qui est le préfixe de la
         * route d'envoi de commande. Sans lui, sendCommand répond 404 avec un
         * jeton pourtant valide — c'est le piège dans lequel tombent toutes les
         * réimplémentations. */
        $bindDomain = isset($_record['bindDomain']) ? (string) $_record['bindDomain'] : '';
        $prefix = '';
        if ($bindDomain !== '') {
            $parts = explode('.', $bindDomain);
            $prefix = $parts[0];
        }

        /* Le champ property est une chaîne contenant du JSON. Illisible ou
         * absent, il ne doit rien casser : il ne porte que des compléments. */
        $property = array();
        if (!empty($_record['property']) && is_string($_record['property'])) {
            $decoded = json_decode($_record['property'], true);
            if (is_array($decoded)) {
                $property = $decoded;
            }
        } elseif (isset($_record['property']) && is_array($_record['property'])) {
            $property = $_record['property'];
        }

        return array(
            'did' => (string) $_record['did'],
            'name' => $name,
            'model' => isset($_record['model']) ? (string) $_record['model'] : '',
            'display' => isset($_record['deviceInfo']['displayName']) ? (string) $_record['deviceInfo']['displayName'] : '',
            'firmware' => isset($_record['ver']) ? (string) $_record['ver'] : '',
            'mac' => isset($_record['mac']) ? (string) $_record['mac'] : '',
            'bind_domain' => $bindDomain,
            'prefix' => $prefix,
            'master_uid' => isset($_record['masterUid']) ? (string) $_record['masterUid'] : '',
            'shared' => empty($_record['master']),
            /* Résumé fourni par le cloud lui-même : il vaut ce qu'il vaut, mais
             * il reste vrai quand le robot ne répond plus, là où get_properties
             * échoue. */
            'online' => !empty($_record['online']),
            'battery' => isset($_record['battery']) ? (int) $_record['battery'] : null,
            'latest_status' => isset($_record['latestStatus']) ? (int) $_record['latestStatus'] : null,
            'iot_id' => isset($property['iotId']) ? (string) $property['iotId'] : '',
        );
    }

    /* ------------------------------------------------------------------ *
     * Dialogue avec un robot
     * ------------------------------------------------------------------ */

    /*
     * La route d'envoi de commande dépend de l'appareil, pas du compte.
     *
     * Le préfixe vaut 10000 sur tous les comptes Dreame observés, mais il est
     * dérivé de bindDomain plutôt que codé en dur : le jour où un compte en
     * renvoie un autre, le plugin suivra sans qu'on ait à le corriger.
     */
    private function commandPath($_device) {
        $prefix = isset($_device['prefix']) ? (string) $_device['prefix'] : '';
        if ($prefix === '') {
            throw new dreamebeApiException('Route de commande inconnue pour ce robot : relancez la découverte '
                                           . 'des appareils pour la retrouver.', dreamebeApiException::API);
        }
        return 'dreame-iot-com-' . $prefix . '/device/sendCommand';
    }

    /*
     * Un ordre MIoT. Le corps répète did et id à deux niveaux : c'est la forme
     * qu'attend le serveur, et non une maladresse.
     */
    public function send($_device, $_method, $_params) {
        $did = (string) $_device['did'];
        $this->_requestId = ($this->_requestId % 9000) + 1000;
        $id = $this->_requestId;

        $answer = $this->call($this->commandPath($_device), array(
            'did' => $did,
            'id' => $id,
            'data' => array(
                'did' => $did,
                'id' => $id,
                'method' => $_method,
                'params' => $_params,
            ),
        ));

        if (!isset($answer['data']['result'])) {
            /* Le cloud a dit « succès » sans rien rapporter : c'est ce qu'il
             * répond quand le robot ne l'a pas acquitté. */
            throw new dreamebeApiException('Le robot n\'a pas accusé réception de l\'ordre « ' . $_method . ' ».',
                                           dreamebeApiException::NOACK);
        }
        return $answer['data']['result'];
    }

    /*
     * Lit des propriétés. $_props est une liste de array(siid, piid) ; le retour
     * est indexé par "siid.piid" pour que l'appelant n'ait pas à remettre les
     * réponses en face des questions.
     *
     * Les propriétés en erreur sont simplement absentes du résultat. C'est
     * volontaire : une propriété non supportée par un modèle répond un code non
     * nul, et cela ne doit ni lever d'exception ni produire une valeur inventée.
     */
    public function getProperties($_device, $_props) {
        $did = (string) $_device['did'];
        $values = array();

        foreach (array_chunk($_props, self::PROPERTIES_PER_CALL) as $chunk) {
            $params = array();
            foreach ($chunk as $prop) {
                $params[] = array('did' => $did, 'siid' => (int) $prop[0], 'piid' => (int) $prop[1]);
            }
            $result = $this->send($_device, 'get_properties', $params);
            if (!is_array($result)) {
                continue;
            }
            foreach ($result as $entry) {
                if (!is_array($entry) || !isset($entry['siid']) || !isset($entry['piid'])) {
                    continue;
                }
                if (isset($entry['code']) && $entry['code'] != 0) {
                    continue;
                }
                if (!array_key_exists('value', $entry)) {
                    continue;
                }
                $values[$entry['siid'] . '.' . $entry['piid']] = $entry['value'];
            }
        }
        return $values;
    }

    public function setProperty($_device, $_siid, $_piid, $_value) {
        return $this->send($_device, 'set_properties', array(
            array('did' => (string) $_device['did'], 'siid' => (int) $_siid, 'piid' => (int) $_piid, 'value' => $_value),
        ));
    }

    /*
     * Une action MIoT. Le tableau « in » reste présent même vide : le serveur
     * refuse la requête s'il manque.
     */
    public function action($_device, $_siid, $_aiid, $_in = array()) {
        return $this->send($_device, 'action', array(
            'did' => (string) $_device['did'],
            'siid' => (int) $_siid,
            'aiid' => (int) $_aiid,
            'in' => array_values($_in),
        ));
    }

    /*
     * L'image que le cloud garde du robot.
     *
     * Cette lecture-ci ne réveille pas le robot et ne peut pas échouer sur un
     * défaut d'accusé de réception : le serveur rend ce qu'il a reçu en dernier.
     * C'est le filet de sécurité du cycle d'actualisation — quand le robot dort
     * ou tarde à répondre, mieux vaut une valeur d'il y a deux minutes qu'un
     * trou dans l'historique de Jeedom.
     *
     * Deux pièges, tous deux vérifiés dans les implémentations de référence :
     * « keys » doit être une CHAÎNE de « siid.piid » séparés par des virgules —
     * un tableau fait répondre 10001, une chaîne vide 10007 — et les valeurs
     * rendues sont toutes des chaînes, y compris les nombres.
     */
    public function shadowProperties($_device, $_props) {
        if (empty($_props)) {
            return array();
        }
        $keys = array();
        foreach ($_props as $prop) {
            $keys[] = ((int) $prop[0]) . '.' . ((int) $prop[1]);
        }
        $answer = $this->call('dreame-user-iot/iotstatus/props', array(
            'did' => (string) $_device['did'],
            'keys' => implode(',', $keys),
        ));

        $values = array();
        if (!isset($answer['data']) || !is_array($answer['data'])) {
            return $values;
        }
        foreach ($answer['data'] as $entry) {
            if (!is_array($entry) || !isset($entry['key']) || !array_key_exists('value', $entry)) {
                continue;
            }
            $values[(string) $entry['key']] = $entry['value'];
        }
        return $values;
    }

    /*
     * L'historique des nettoyages.
     *
     * Il n'existe pas d'endpoint « historique de nettoyage » : ce que le cloud
     * archive, ce sont les événements de la propriété de statut du robot
     * (service 4, événement 1), et chaque événement porte une photographie des
     * propriétés du moment. C'est de là que viennent la date, la durée et la
     * surface du dernier nettoyage.
     *
     * Le champ « type » vaut 3 dans toutes les implémentations connues, sans que
     * personne ait su dire ce qu'il désigne. Il est recopié tel quel.
     */
    public function history($_device, $_from = 0, $_limit = 20, $_siid = 4, $_eiid = 1) {
        /*
         * Le propriétaire de l'appareil, pas celui de la session : sur un robot
         * partagé par le conjoint, ce sont deux personnes différentes, et
         * interroger l'historique sous le mauvais identifiant rend une liste
         * vide sans la moindre erreur.
         */
        $uid = (!empty($_device['master_uid'])) ? (string) $_device['master_uid'] : $this->_uid;

        $params = array(
            'uid' => $uid,
            'did' => (string) $_device['did'],
            /* Une borne nulle n'est pas acceptée partout ; la référence utilise
             * cette date de repli, antérieure à tout appareil du parc. */
            'from' => ((int) $_from > 0) ? (int) $_from : 1687019188,
            'limit' => (int) $_limit,
            'siid' => (string) $_siid,
            'eiid' => (string) $_eiid,
            'region' => $this->_region,
            'type' => 3,
        );
        $answer = $this->call('dreame-user-iot/iotstatus/history', $params);
        if (!isset($answer['data']['list']) || !is_array($answer['data']['list'])) {
            return array();
        }
        return $answer['data']['list'];
    }

    /*
     * Met en forme les événements rendus par l'historique.
     *
     * Statique et sans effet de bord, pour la même raison que parseDevice() :
     * c'est la partie qui se trompe, et c'est donc celle qu'un jeu d'essai doit
     * pouvoir rejouer sur des réponses réelles sans compte ni robot.
     *
     * Chaque événement porte une liste de couples « piid, valeur » : ce sont les
     * propriétés du robot au moment où il a changé d'état. Les numéros repris ici
     * sont ceux du service 4, et eux seuls ont un sens dans ce contexte.
     */
    public static function parseHistory($_entries) {
        $history = array();
        if (!is_array($_entries)) {
            return $history;
        }

        foreach ($_entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $raw = null;
            foreach (array('history', 'value', 'val') as $field) {
                if (isset($entry[$field])) {
                    $raw = $entry[$field];
                    break;
                }
            }
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            if (!is_array($raw)) {
                continue;
            }

            $record = array('date' => null, 'duration' => null, 'area' => null,
                            'status' => null, 'completed' => null);
            foreach ($raw as $field) {
                if (!is_array($field) || !isset($field['piid'])) {
                    continue;
                }
                $value = isset($field['value']) ? $field['value'] : null;
                switch ((int) $field['piid']) {
                    case 1:  $record['status'] = (int) $value; break;
                    case 2:  $record['duration'] = (int) $value; break;
                    case 3:  $record['area'] = (int) $value; break;
                    case 8:  $record['date'] = (int) $value; break;
                    case 13: $record['completed'] = ((int) $value === 1); break;
                }
            }

            if ($record['date'] === null && isset($entry['createTime'])) {
                /* Certains micrologiciels datent en millisecondes, d'autres en
                 * secondes. Un horodatage lu à la mauvaise échelle place le
                 * dernier nettoyage en 1970 ou en l'an 56000. */
                $stamp = (int) $entry['createTime'];
                $record['date'] = ($stamp > 10000000000) ? intdiv($stamp, 1000) : $stamp;
            }
            if ($record['date'] !== null && $record['date'] > 0) {
                $history[] = $record;
            }
        }

        usort($history, function ($a, $b) { return $b['date'] - $a['date']; });
        return $history;
    }

    /*
     * L'adresse signée d'un fichier déposé par le robot (carte, journal de
     * nettoyage). Le cloud rend une URL valable quelques dizaines de minutes ;
     * elle se télécharge ensuite sans en-tête d'authentification.
     */
    public function fileUrl($_device, $_filename) {
        $answer = $this->call('dreame-user-iot/iotfile/getDownloadUrl', array(
            'did' => (string) $_device['did'],
            'model' => (string) $_device['model'],
            'filename' => (string) $_filename,
            'region' => $this->_region,
        ));
        if (!isset($answer['data']) || !is_string($answer['data']) || $answer['data'] === '') {
            throw new dreamebeApiException('Le cloud n\'a pas rendu d\'adresse pour ce fichier.',
                                           dreamebeApiException::API);
        }
        return $answer['data'];
    }

    /*
     * Télécharge une adresse signée. Aucun en-tête d'authentification : la
     * signature est dans l'URL, et y ajouter le jeton le ferait fuiter chez
     * l'hébergeur du stockage.
     */
    public function download($_url) {
        $answer = $this->http('GET', $_url, array());
        if ($answer['status'] != 200) {
            throw new dreamebeApiException('Téléchargement refusé (HTTP ' . $answer['status'] . ').',
                                           dreamebeApiException::NETWORK);
        }
        return $answer['body'];
    }

    /* ------------------------------------------------------------------ *
     * Transport
     * ------------------------------------------------------------------ */

    /*
     * Le seul endroit où le plugin parle réellement au réseau.
     *
     * Protégée, et non privée : le jeu d'essai en dérive une version qui rend des
     * fixtures, et vérifie ainsi tout le reste de la classe sans compte Dreame ni
     * accès Internet.
     */
    protected function http($_method, $_url, $_headers, $_body = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->_connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->_timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_method);
        /* Le serveur répond en gzip ; laisser cURL le déclarer et le défaire. */
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $headers = array_merge(array(
            'Accept: */*',
            'Accept-Language: en-US;q=0.8',
            'User-Agent: ' . self::USER_AGENT,
        ), $_headers);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($_body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $_body);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new dreamebeApiException('Cloud DreameHome injoignable : ' . $error . ' (' . $errno . ').',
                                           dreamebeApiException::NETWORK);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return array('status' => $status, 'body' => $body);
    }

    /* Serveur saturé ou en panne : rien à conclure, on retentera plus tard. */
    private static function isTransient($_status) {
        return $_status == 429 || $_status >= 500;
    }

    /*
     * Masque ce qui ne doit jamais atteindre un journal : jeton, mot de passe,
     * en-tête d'autorisation. Le mode debug d'un plugin finit toujours par être
     * activé un jour, et son journal par être collé dans un forum.
     */
    public static function redact($_text) {
        $text = (string) $_text;
        $text = preg_replace('/("(?:access_token|refresh_token|password)"\s*:\s*")[^"]*(")/i', '$1***$2', $text);
        $text = preg_replace('/(password=)[^&\s]*/i', '$1***', $text);
        $text = preg_replace('/(Dreame-Auth:\s*)\S+/i', '$1***', $text);
        $text = preg_replace('/(Authorization:\s*\S+\s+)\S+/i', '$1***', $text);
        return $text;
    }
}
