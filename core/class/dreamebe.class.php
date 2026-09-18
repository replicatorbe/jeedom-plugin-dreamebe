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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur de Jeedom ne sait résoudre que la classe portant le nom du
 * plugin : les classes annexes doivent être incluses explicitement. */
require_once __DIR__ . '/dreamebeApi.class.php';
require_once __DIR__ . '/dreamebeSpec.class.php';
require_once __DIR__ . '/dreamebeMap.class.php';
require_once __DIR__ . '/dreamebeMapImage.class.php';

class dreamebe extends eqLogic {

    /*
     * Ce que le coeur doit chiffrer avant de l'écrire dans sa table de
     * configuration. Sans cette déclaration, config::save() range la valeur en
     * clair : le mot de passe DreameHome et les jetons de session ressortiraient
     * tels quels dans la moindre sauvegarde de Jeedom.
     */
    public static $_encryptConfigKey = array('password', 'session');

    /* Un seul client par requête HTTP : trois robots partagent un compte, et
     * donc une session. Ouvrir trois sessions ferait trois authentifications
     * là où une suffit, sur un endpoint qui limite les tentatives. */
    private static $_api = null;

    /* Les identifiants des commandes que createCommands() vient de poser. Ce
     * qui n'y figure pas et porte la marque du plugin n'a plus lieu d'être :
     * voir pruneCommands(). */
    private $_expected = array();

    /* Les noms déjà attribués pendant la passe de création. Jeedom impose des
     * noms de commande uniques par équipement, et la violation ne se solde pas
     * par une commande manquante mais par l'échec de TOUT l'enregistrement. */
    private $_usedNames = array();

    /*
     * Ce qui s'affiche sur le tableau de bord, par défaut.
     *
     * Un robot expose ici une centaine de commandes ; toutes visibles, elles
     * noyaient l'essentiel. On s'en tient donc à ce qu'on vient réellement
     * chercher : ce que fait le robot, sa batterie, une erreur éventuelle, la
     * carte, les cinq ordres du quotidien et les trois réglages qu'on change
     * avant de lancer un nettoyage. Plus une commande par pièce.
     *
     * Chaque tuile porte son nom, et c'est le point : une rangée d'icônes sans
     * libellé ne se devine pas — « Arrêter » interrompt le nettoyage sur place,
     * il ne renvoie pas à la station, et rien ne le dirait sans son étiquette.
     *
     * Ne s'applique qu'à la création : ce que l'utilisateur règle ensuite lui
     * appartient. La méthode applyDefaultVisibility() permet d'y revenir quand
     * il le demande.
     */
    public static $visibleByDefault = array(
        'etat', 'batterie', 'erreur', 'station', 'carte',
        'demarrer', 'pause', 'arreter', 'retour_station', 'localiser',
        'regler_aspiration', 'regler_mode', 'regler_humidite', 'regler_eau',
    );

    public static function defaultVisibility($_logicalId) {
        /* Les pièces sont des raccourcis explicites : « Nettoyer : Cuisine »
         * n'a pas besoin d'explication, et c'est le geste le plus courant. */
        if (strpos($_logicalId, 'room::') === 0) {
            return 1;
        }
        return in_array($_logicalId, self::$visibleByDefault, true) ? 1 : 0;
    }

    /*
     * Ramène la visibilité des commandes à celle d'un équipement neuf.
     *
     * Volontairement hors du cycle normal : elle défait des réglages
     * d'affichage, et cela ne se fait que sur demande explicite.
     */
    public function applyDefaultVisibility() {
        $touchees = 0;
        foreach ($this->getCmd() as $cmd) {
            /* Jamais une commande ajoutée à la main par l'utilisateur. */
            if ($cmd->getConfiguration('managed', 0) != 1) {
                continue;
            }
            $attendue = self::defaultVisibility($cmd->getLogicalId());
            if ($cmd->getIsVisible() != $attendue) {
                $cmd->setIsVisible($attendue);
                $cmd->save();
                $touchees++;
            }
        }
        return $touchees;
    }

    /* ------------------------------------------------------------------ *
     * Client du cloud
     * ------------------------------------------------------------------ */

    public static function api() {
        if (self::$_api !== null) {
            return self::$_api;
        }

        $api = new dreamebeApi(
            config::byKey('username', 'dreamebe', ''),
            config::byKey('password', 'dreamebe', ''),
            config::byKey('region', 'dreamebe', 'eu')
        );
        $api->setTimeouts(
            config::byKey('connect_timeout', 'dreamebe', 5),
            config::byKey('timeout', 'dreamebe', 15)
        );
        /* Le client ne connaît pas Jeedom : on lui prête un journal. Tout ce
         * qu'il écrit passe par le masquage, parce qu'un plugin finit toujours
         * par voir son journal collé dans un forum. */
        $api->setLogger(function ($_level, $_message) {
            log::add('dreamebe', $_level, dreamebeApi::redact($_message));
        });

        $session = dreamebeApi::normalizeSession(config::byKey('session', 'dreamebe', ''));
        if ($session !== null) {
            $api->setSession($session);
        }

        self::$_api = $api;
        return $api;
    }

    /*
     * Enregistre la session si le client l'a renouvelée.
     *
     * Sans cela, chaque cycle de polling rouvrirait une session : c'est
     * exactement ce que le cloud Dreame limite, et le plugin finirait par être
     * éconduit sans que rien n'explique pourquoi.
     */
    public static function saveSession() {
        if (self::$_api === null || !self::$_api->isSessionDirty()) {
            return;
        }
        /*
         * Une seule écriture, et surtout PAS celle de la région.
         *
         * config::save() rappelle postConfig_<clé> après avoir écrit — y compris
         * quand la valeur est celle du fichier de configuration par défaut, où il
         * passe par remove(). Réenregistrer la région ici déclencherait donc
         * postConfig_region(), donc forgetSession(), donc l'effacement immédiat
         * de la session qu'on vient de sauvegarder : le plugin se
         * réauthentifierait à chaque appel, sur un endpoint dont le nombre de
         * tentatives est limité.
         *
         * La région corrigée par le serveur n'est pas perdue pour autant : elle
         * voyage dans le bloc de session, et setSession() la restaure.
         */
        config::save('session', json_encode(self::$_api->getSession()), 'dreamebe');
    }

    /* Le compte a changé : la session d'avant n'a plus de sens. */
    public static function postConfig_username($_value) { self::forgetSession(); }
    public static function postConfig_password($_value) { self::forgetSession(); }
    public static function postConfig_region($_value)   { self::forgetSession(); }

    public static function forgetSession() {
        config::save('session', '', 'dreamebe');
        self::$_api = null;
    }

    /* ------------------------------------------------------------------ *
     * Découverte des robots
     * ------------------------------------------------------------------ */

    /*
     * Les robots du compte, tels que le cloud les décrit.
     *
     * Un compte peut en porter plusieurs, et c'est le cas nominal ici : rien
     * dans ce plugin ne suppose qu'il n'y en a qu'un.
     */
    public static function discover() {
        $devices = self::api()->getDevices();
        self::saveSession();
        return $devices;
    }

    /*
     * Crée les équipements manquants et rafraîchit ceux qui existent.
     *
     * Rend le compte rendu de ce qui a été fait, pour que l'interface puisse le
     * dire plutôt que de laisser l'utilisateur deviner.
     */
    public static function syncDevices() {
        $devices = self::discover();
        $report = array('created' => array(), 'updated' => array(), 'total' => count($devices));

        foreach ($devices as $device) {
            $eqLogic = self::byLogicalId($device['did'], 'dreamebe');
            $isNew = !is_object($eqLogic);

            if ($isNew) {
                $eqLogic = new dreamebe();
                $eqLogic->setEqType_name('dreamebe');
                $eqLogic->setLogicalId($device['did']);
                $eqLogic->setIsEnable(1);
                $eqLogic->setIsVisible(1);
                /* Deux robots du même modèle porteraient le même nom d'usine :
                 * la contrainte d'unicité de Jeedom ferait échouer la création
                 * du second, sans rien dire d'utile. */
                $eqLogic->setName(self::uniqueName($device));
            }

            $eqLogic->setConfiguration('did', $device['did']);
            $eqLogic->setConfiguration('model', $device['model']);
            $eqLogic->setConfiguration('model_name', dreamebeSpec::modelName($device['model']));
            $eqLogic->setConfiguration('prefix', $device['prefix']);
            $eqLogic->setConfiguration('bind_domain', $device['bind_domain']);
            $eqLogic->setConfiguration('firmware', $device['firmware']);
            $eqLogic->setConfiguration('cloud_name', $device['name']);
            $eqLogic->setConfiguration('shared', $device['shared'] ? 1 : 0);
            $eqLogic->save();

            if ($isNew) {
                $report['created'][] = $eqLogic->getName();
            } else {
                $report['updated'][] = $eqLogic->getName();
            }
        }
        return $report;
    }

    private static function uniqueName($_device) {
        $base = ($_device['name'] !== '') ? $_device['name'] : dreamebeSpec::modelName($_device['model']);
        if ($base === '') {
            $base = 'Dreame';
        }
        $name = $base;
        $suffix = 2;
        while (self::nameTaken($name)) {
            $name = $base . ' ' . $suffix;
            $suffix++;
            if ($suffix > 50) {
                /* Repli garanti unique : l'identifiant du robot ne se répète
                 * pas. Sortir de la boucle en gardant un nom déjà pris ferait
                 * échouer la création sur la contrainte d'unicité. */
                return $base . ' ' . substr($_device['did'], -6);
            }
        }
        return $name;
    }

    private static function nameTaken($_name) {
        foreach (self::byType('dreamebe') as $eqLogic) {
            if ($eqLogic->getName() === $_name) {
                return true;
            }
        }
        return false;
    }

    /*
     * La fiche que la couche réseau attend. Elle est reconstruite à partir de la
     * configuration de l'équipement plutôt que d'un appel au cloud : interroger
     * la liste des appareils avant chaque commande doublerait le trafic pour
     * rien.
     */
    public function device() {
        return array(
            'did' => $this->getConfiguration('did', $this->getLogicalId()),
            'model' => $this->getConfiguration('model', ''),
            'prefix' => $this->getConfiguration('prefix', ''),
            'bind_domain' => $this->getConfiguration('bind_domain', ''),
        );
    }

    /*
     * Les propriétés secondaires, exposées par table plutôt qu'à la main.
     *
     * Elles se ressemblent toutes — lire une valeur, lui coller un libellé, en
     * faire une commande — et les écrire une par une aurait produit quatorze
     * blocs quasi identiques dans createCommands() et autant dans applyValues().
     * La table les décrit une fois ; le code qui les traite tient en vingt
     * lignes, et en ajouter une de plus tient en une ligne.
     *
     *   propriété => array(identifiant, libellé, rendu, complément, ordre, rapide)
     *
     * « rapide » distingue ce qui bouge pendant un nettoyage — et doit donc être
     * relu à chaque cycle — de ce qui est un réglage, relu avec l'entretien.
     */
    public static $extras = array(
        'drying_progress'   => array('sechage_progression', 'Progression du séchage', 'numeric', '%', 43, true),
        'task_type'         => array('type_tache', 'Type de tâche', 'enum', 'taskTypes', 44, true),
        'relocation'        => array('localisation', 'Localisation', 'enum', 'relocation', 45, true),
        'dust_collection'   => array('vidage_disponible', 'Auto-vidage disponible', 'enum', 'dustCollection', 46, true),
        'auto_empty_status' => array('vidage_etat', 'Auto-vidage en cours', 'enum', 'autoEmptyStatus', 47, true),

        'volume'            => array('volume', 'Volume des annonces', 'numeric', '%', 50, false),
        'dnd'               => array('dnd', 'Ne pas déranger', 'binary', null, 51, false),
        'water_temperature' => array('temperature_eau', 'Température eau', 'enum', 'waterTemperature', 52, false),
        'mop_wash_level'    => array('niveau_lavage', 'Niveau de lavage', 'enum', 'mopWashLevel', 53, false),
        'drying_time'       => array('duree_sechage', 'Durée de séchage', 'numeric', 'h', 54, false),
        'carpet_cleaning'   => array('tapis', 'Gestion des tapis', 'enum', 'carpetCleaning', 55, false),
        'auto_detergent'    => array('detergent_auto', 'Détergent automatique', 'enum', 'autoDetergent', 56, false),
        'hot_water_status'  => array('eau_chaude', 'Eau chaude', 'enum', 'hotWaterStatus', 57, false),
        'detergent_status'  => array('detergent', 'État du détergent', 'enum', 'detergentStatus', 58, false),
        'first_cleaning'    => array('premier_nettoyage', 'Premier nettoyage', 'date', null, 59, false),
    );

    /*
     * Celles de ces propriétés qui se règlent, et comment.
     *
     *   propriété => array(identifiant, libellé, sous-type, choix, ordre, transtypage)
     *
     * Le transtypage n'est pas une coquetterie : le robot rend « ne pas
     * déranger » sous forme de booléen, pas d'entier, et lui réécrire 1 là où il
     * attend true n'a aucun effet visible — la commande semble réussir et rien
     * ne change.
     */
    public static $extraActions = array(
        'volume'            => array('regler_volume', 'Régler le volume', 'slider', null, 90, 'int'),
        'dnd'               => array('regler_dnd', 'Régler « Ne pas déranger »', 'select',
                                     '0|Désactivé;1|Activé', 91, 'bool'),
        'water_temperature' => array('regler_temperature', 'Régler la température eau', 'select',
                                     '0|Normale;1|Tiède;2|Chaude;3|Très chaude;4|Maximale', 92, 'int'),
        'mop_wash_level'    => array('regler_niveau_lavage', 'Régler le niveau de lavage', 'select',
                                     '0|Économie d\'eau;1|Quotidien;2|Profond', 93, 'int'),
        'carpet_cleaning'   => array('regler_tapis', 'Régler la gestion des tapis', 'select',
                                     '0|Non défini;1|Évitement;2|Adaptation;3|Retrait de la serpillière', 94, 'int'),
        'auto_detergent'    => array('regler_detergent', 'Régler le détergent automatique', 'select',
                                     '0|Désactivé;1|Activé', 95, 'int'),
    );

    /* ------------------------------------------------------------------ *
     * Libellés
     * ------------------------------------------------------------------ */

    /*
     * Les libellés des énumérations, traduits.
     *
     * Ils vivent dans la table de spécification, qui ignore volontairement
     * Jeedom — c'est ce qui la rend éprouvable hors ligne. La traduction se fait
     * donc ici, au moment de l'affichage, et les chaînes se rangent sous ce
     * fichier-ci dans le catalogue.
     *
     * Sans ce détour, un Jeedom en anglais afficherait « Aspiration et lavage »
     * ou « Retour à la station » au milieu de son interface.
     */
    public static function label($_table, $_value, $_default = 'Inconnu') {
        return __(dreamebeSpec::label($_table, $_value, $_default), __FILE__);
    }

    public static function errorLabel($_code) {
        return __(dreamebeSpec::errorLabel($_code), __FILE__);
    }

    /* ------------------------------------------------------------------ *
     * Sondage des capacités
     * ------------------------------------------------------------------ */

    /*
     * Ce que CE robot sait faire.
     *
     * Il n'existe aucune table de propriétés par modèle : le même dictionnaire
     * couvre toute la gamme, et c'est le robot qui tranche à l'exécution. On lui
     * demande donc tout ce qui nous intéresse, une fois, et on retient ce à quoi
     * il a répondu. Une propriété sans réponse ne devient pas une commande :
     * c'est la seule façon de ne pas inventer une fonction que la machine n'a
     * pas — un curseur de température d'eau sur un robot qui n'en a pas.
     */
    public function probe() {
        $candidates = array();
        foreach (dreamebeSpec::$properties as $name => $prop) {
            /* La carte n'est pas une valeur à afficher : elle a son propre
             * chemin, et la sonder ferait transiter des kilo-octets pour rien. */
            if (in_array($name, array('map_data', 'object_name', 'map_list'), true)) {
                continue;
            }
            $candidates[$name] = $prop;
        }
        foreach (dreamebeSpec::$consumables as $siid => $consumable) {
            $candidates[$consumable[0] . '_wear'] = array($siid, $consumable[2]);
            $candidates[$consumable[0] . '_left'] = array($siid, $consumable[3]);
        }

        $values = self::api()->getProperties($this->device(), array_values($candidates));
        self::saveSession();

        $supported = array();
        foreach ($candidates as $name => $prop) {
            $key = $prop[0] . '.' . $prop[1];
            if (array_key_exists($key, $values)) {
                $supported[] = $name;
            }
        }

        /*
         * Un robot éteint répond « rien » à tout. Enregistrer ce rien comme
         * « ce robot ne sait rien faire » effacerait la liste des capacités, et
         * l'élagage des commandes qui suit s'appuierait dessus. On refuse donc
         * un résultat vide, et on refuse aussi un effondrement — une réponse
         * dégradée sur un paquet de lecture ne doit pas coûter des commandes.
         */
        $previous = $this->getConfiguration('supported', array());
        if (empty($supported)) {
            throw new Exception(__('Le robot n\'a répondu à aucune propriété : il est probablement '
                                   . 'éteint ou hors ligne. Rallumez-le puis relancez le sondage.', __FILE__));
        }
        if (is_array($previous) && count($previous) > 0 && count($supported) < (count($previous) / 2)) {
            throw new Exception(__('Le robot n\'a répondu que partiellement : sondage ignoré pour ne pas '
                                   . 'perdre des commandes. Réessayez quand il sera disponible.', __FILE__));
        }

        $this->setConfiguration('supported', $supported);
        $this->setConfiguration('probed_at', time());
        /* Enregistrement direct : postSave() refera les commandes juste après,
         * et une passe suffit. */
        $this->save();

        log::add('dreamebe', 'info', $this->getHumanName() . ' : ' . count($supported)
                 . ' propriété(s) reconnue(s) par le robot.');
        return $supported;
    }

    public function supported($_name) {
        $supported = $this->getConfiguration('supported', array());
        if (!is_array($supported) || empty($supported)) {
            /* Tant que le sondage n'a pas eu lieu, ne rien affirmer. */
            return null;
        }
        return in_array($_name, $supported, true);
    }

    /*
     * Le robot a-t-il une base de lavage ?
     *
     * La réponse change l'interprétation de deux réglages : sans base, le niveau
     * d'eau est une propriété simple ; avec base, c'est un octet caché dans une
     * valeur empaquetée, et les codes de mode y sont permutés. Se tromper ici
     * affiche « aspiration seule » quand le robot lave, et réciproquement.
     */
    public function hasWashBase() {
        return $this->supported('wash_base_status') === true;
    }

    /* ------------------------------------------------------------------ *
     * Actualisation
     * ------------------------------------------------------------------ */

    /*
     * Le cycle d'actualisation.
     *
     * Appelé toutes les minutes par le cœur, il ne travaille que si l'intervalle
     * configuré est écoulé — sauf pendant un nettoyage, où l'on veut voir la
     * progression avancer.
     */
    public static function cron() {
        $eqLogics = self::byType('dreamebe', true);
        if (empty($eqLogics)) {
            return;
        }
        if (trim(config::byKey('username', 'dreamebe', '')) === '') {
            return;
        }

        /*
         * Un seul appel pour tous les robots : la liste des appareils rend
         * l'état de connexion et le niveau de batterie de chacun. C'est gratuit
         * par rapport à une interrogation par robot, et cela évite surtout de
         * réveiller une machine qui dort pour apprendre qu'elle dort.
         */
        $summary = array();
        try {
            foreach (self::discover() as $device) {
                $summary[$device['did']] = $device;
            }
        } catch (dreamebeApiException $e) {
            if ($e->getCode() === dreamebeApiException::AUTH) {
                log::add('dreamebe', 'error', __('Compte DreameHome refusé :', __FILE__) . ' ' . $e->getMessage());
                return;
            }
            /* Sans la liste, chaque robot serait interrogé un par un — c'est
             * exactement le trafic que cet appel groupé sert à éviter, et
             * l'état de connexion resterait faux. Mieux vaut sauter ce tour. */
            log::add('dreamebe', 'warning', __('Liste des robots indisponible, cycle reporté :', __FILE__)
                     . ' ' . $e->getMessage());
            return;
        } catch (Throwable $e) {
            log::add('dreamebe', 'error', __('Cycle d\'actualisation :', __FILE__) . ' ' . $e->getMessage());
            return;
        }

        foreach ($eqLogics as $eqLogic) {
            try {
                $eqLogic->poll(false, isset($summary[$eqLogic->getConfiguration('did')])
                                         ? $summary[$eqLogic->getConfiguration('did')] : null);
            } catch (Throwable $e) {
                log::add('dreamebe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        self::saveSession();
    }

    /*
     * Ne JAMAIS renommer cette méthode « refresh ».
     *
     * eqLogic::refresh() existe dans le coeur et vaut « recharge-toi depuis la
     * base ». jeedom::replaceTag() l'appelle sur n'importe quel équipement avant
     * de le réenregistrer. La redéfinir ici déclencherait une interrogation du
     * cloud au milieu d'un remplacement de commande, puis un enregistrement à
     * partir d'un objet non rechargé — et PHP n'y verrait rien, ajouter des
     * paramètres facultatifs à une redéfinition étant parfaitement légal.
     */
    public function poll($_force = false, $_summary = null) {
        $interval = max(30, (int) config::byKey('polling_interval', 'dreamebe', 120));
        $last = (int) $this->getCache('last_attempt', 0);

        /* Pendant un nettoyage, on suit ; au repos, on se fait oublier. */
        if (!$_force && $this->getCache('active', 0) == 1) {
            $interval = min($interval, 60);
        }
        if (!$_force && (time() - $last) < $interval) {
            return false;
        }
        /* Deux clés, et ce n'est pas un luxe : celle-ci cadence les tentatives,
         * l'autre n'est posée qu'en cas de succès. Les confondre ferait passer
         * pour sain, sur la page Santé, un robot qui échoue depuis une semaine. */
        $this->setCache('last_attempt', time());

        if ($_summary !== null) {
            $this->checkAndUpdateCmd('en_ligne', $_summary['online'] ? 1 : 0);
            if ($_summary['battery'] !== null) {
                $this->checkAndUpdateCmd('batterie', $_summary['battery']);
            }
            /* Un robot que le cloud donne pour déconnecté ne répondra pas : lui
             * parler quand même ne produirait qu'un délai d'attente par cycle. */
            if (!$_summary['online']) {
                $this->setCache('active', 0);
                return false;
            }
        }

        if ($this->supported('state') === null) {
            /* probe() enregistre, et l'enregistrement recrée déjà les commandes
             * par postSave() : les refaire ici serait une seconde passe complète
             * pour rien. */
            $this->probe();
        }

        $props = $this->pollProperties();
        if (empty($props)) {
            return false;
        }

        $values = array();
        try {
            $values = self::api()->getProperties($this->device(), array_values($props));
        } catch (dreamebeApiException $e) {
            if ($e->getCode() !== dreamebeApiException::NOACK) {
                throw $e;
            }
            /*
             * Le robot n'a pas accusé réception à temps. Ce n'est pas une
             * déconnexion : le cloud garde une image de ce qu'il a reçu en
             * dernier, et cette image vaut mieux qu'un trou dans l'historique.
             */
            log::add('dreamebe', 'debug', $this->getHumanName()
                     . ' : pas d\'accusé du robot, lecture de l\'image du cloud.');
            $values = self::api()->shadowProperties($this->device(), array_values($props));
        }

        if (empty($values)) {
            return false;
        }
        $this->setCache('last_success', time());
        $this->applyValues($props, $values);
        $this->refreshExtras();
        return true;
    }

    /* Les propriétés à relire à chaque cycle : celles qui bougent. */
    private function pollProperties() {
        $wanted = array(
            'state', 'error', 'battery', 'charging', 'status', 'cleaning_time',
            'cleaned_area', 'suction', 'task_status', 'cleaning_progress',
            'water_tank', 'mop_pad_installed',
        );
        if ($this->hasWashBase()) {
            $wanted[] = 'cleaning_mode';
            $wanted[] = 'wash_base_status';
            $wanted[] = 'low_water_warning';
            $wanted[] = 'clean_water_tank';
            $wanted[] = 'dirty_water_tank';
            $wanted[] = 'dust_bag';
            /* Sur les robots récents, l'humidité n'est plus l'octet empaqueté
             * mais une échelle de 1 à 32 dans sa propre propriété. Les deux
             * coexistent selon la génération : on lit celle qui répond. */
            $wanted[] = 'wetness_onboard';
            $wanted[] = 'wetness_level';
        } else {
            $wanted[] = 'water_volume';
        }

        foreach (self::$extras as $name => $extra) {
            if ($extra[5]) {
                $wanted[] = $name;
            }
        }

        $props = array();
        foreach ($wanted as $name) {
            if ($this->supported($name) === false) {
                continue;
            }
            if (isset(dreamebeSpec::$properties[$name])) {
                $props[$name] = dreamebeSpec::$properties[$name];
            }
        }
        return $props;
    }

    /*
     * Traduit les valeurs brutes en commandes Jeedom.
     *
     * Une valeur absente n'écrase jamais la précédente : le robot peut très bien
     * ne pas répondre sur une propriété au milieu d'un cycle par ailleurs
     * réussi, et remettre l'état à zéro ferait clignoter tous les widgets.
     */
    public function applyValues($_props, $_values) {
        $get = function ($_name) use ($_props, $_values) {
            if (!isset($_props[$_name])) {
                return null;
            }
            $key = $_props[$_name][0] . '.' . $_props[$_name][1];
            return array_key_exists($key, $_values) ? $_values[$key] : null;
        };

        $state = $get('state');
        if ($state !== null) {
            $this->checkAndUpdateCmd('etat', self::label(dreamebeSpec::$states, $state, 'État inconnu'));
            $this->checkAndUpdateCmd('code_etat', (int) $state);
        }

        $status = $get('status');
        if ($status !== null) {
            $this->checkAndUpdateCmd('statut', self::label(dreamebeSpec::$statuses, $status, 'Statut inconnu'));
        }

        /* « En activité » est ce dont un scénario a besoin : c'est le booléen
         * qui manque dans le protocole, où il faut croiser deux énumérations
         * pour savoir si quelque chose se passe. Le statut seul ne suffit pas —
         * pendant que la station lave la serpillière, le robot est « au repos »
         * alors que la tâche est loin d'être finie. */
        if ($status !== null || $state !== null) {
            $active = dreamebeSpec::isActive($status, $state);
            $this->checkAndUpdateCmd('en_activite', $active ? 1 : 0);
            $this->setCache('active', $active ? 1 : 0);
        }

        $battery = $get('battery');
        if ($battery !== null) {
            $this->checkAndUpdateCmd('batterie', (int) $battery);
        }

        $charging = $get('charging');
        if ($charging !== null) {
            $this->checkAndUpdateCmd('en_charge', ((int) $charging === 1) ? 1 : 0);
            $this->checkAndUpdateCmd('charge', self::label(dreamebeSpec::$chargingStatus, $charging));
        }

        $error = $get('error');
        if ($error !== null) {
            /* Certains codes ne décrivent pas un ennui : « Retirez la
             * serpillière » est le déroulement normal d'un lavage, et une
             * batterie faible pendant la charge n'inquiète personne. Les
             * remonter tels quels ferait sonner les scénarios d'alerte
             * plusieurs fois par jour sur un robot qui va très bien. */
            $code = dreamebeSpec::normalizeError($error, ($charging !== null && (int) $charging === 1),
                                                 $this->hasWashBase());
            $this->checkAndUpdateCmd('code_erreur', $code);
            $this->checkAndUpdateCmd('erreur', self::errorLabel($code));
            /* Une alerte de station n'est pas une panne du robot : les
             * confondre déclenche des notifications pour un bac plein. */
            $this->checkAndUpdateCmd('en_erreur', ($code > 0 && !dreamebeSpec::isWarning($code)) ? 1 : 0);
            $this->checkAndUpdateCmd('en_alerte', dreamebeSpec::isWarning($code) ? 1 : 0);
        }

        $time = $get('cleaning_time');
        if ($time !== null) {
            $this->checkAndUpdateCmd('duree', (int) $time);
        }
        $area = $get('cleaned_area');
        if ($area !== null) {
            $this->checkAndUpdateCmd('surface', (int) $area);
        }
        $progress = $get('cleaning_progress');
        if ($progress !== null) {
            $this->checkAndUpdateCmd('progression', (int) $progress);
        }

        $suction = $get('suction');
        if ($suction !== null) {
            $this->checkAndUpdateCmd('aspiration', (int) $suction);
            $this->checkAndUpdateCmd('aspiration_texte',
                                     self::label(dreamebeSpec::$suctionLevels, $suction));
        }

        $taskStatus = $get('task_status');
        if ($taskStatus !== null) {
            $this->checkAndUpdateCmd('tache', self::label(dreamebeSpec::$taskStatus, $taskStatus));
        }

        /* Le mode et l'humidité : soit deux propriétés simples, soit un seul
         * entier qui en contient trois. Voir splitMode(). */
        if ($this->hasWashBase()) {
            $mode = $get('cleaning_mode');
            if ($mode !== null) {
                $parts = dreamebeSpec::splitMode($mode, true);
                $this->checkAndUpdateCmd('mode', $parts['mode']);
                $this->checkAndUpdateCmd('mode_texte',
                                         self::label(dreamebeSpec::$cleaningModes, $parts['mode']));
                if ($parts['humidity'] > 0) {
                    $this->checkAndUpdateCmd('humidite', $parts['humidity']);
                    $this->checkAndUpdateCmd('humidite_texte',
                                             self::label(dreamebeSpec::$mopHumidity, $parts['humidity']));
                }
            }
            /* Le niveau fin, quand il existe, fait autorité : l'octet
             * empaqueté n'est plus mis à jour sur ces machines, et s'y fier
             * afficherait « humide » pendant que le robot lave à sec. */
            $wetness = $get('wetness_onboard');
            if ($wetness === null) {
                $wetness = $get('wetness_level');
            }
            if ($wetness !== null && (int) $wetness > 0) {
                $this->checkAndUpdateCmd('humidite_niveau', (int) $wetness);
                $humidity = dreamebeSpec::wetnessToHumidity($wetness);
                if ($humidity !== null) {
                    $this->checkAndUpdateCmd('humidite', $humidity);
                    $this->checkAndUpdateCmd('humidite_texte',
                                             self::label(dreamebeSpec::$mopHumidity, $humidity));
                }
            }

            $wash = $get('wash_base_status');
            if ($wash !== null) {
                $this->checkAndUpdateCmd('station', self::label(dreamebeSpec::$washBaseStatus, $wash));
            }
            foreach (array(
                'low_water_warning' => array('alerte_eau', dreamebeSpec::$lowWaterWarning),
                'clean_water_tank' => array('reservoir_propre', dreamebeSpec::$cleanWaterTank),
                'dirty_water_tank' => array('reservoir_sale', dreamebeSpec::$dirtyWaterTank),
                'dust_bag' => array('sac', dreamebeSpec::$dustBag),
            ) as $name => $target) {
                $value = $get($name);
                if ($value !== null) {
                    $this->checkAndUpdateCmd($target[0], self::label($target[1], $value));
                }
            }
        } else {
            $water = $get('water_volume');
            if ($water !== null) {
                $this->checkAndUpdateCmd('eau', (int) $water);
                $this->checkAndUpdateCmd('eau_texte', self::label(dreamebeSpec::$waterVolumes, $water));
            }
        }

        $tank = $get('water_tank');
        if ($tank !== null) {
            $this->checkAndUpdateCmd('reservoir', self::label(dreamebeSpec::$waterTank, $tank));
        }
        $mop = $get('mop_pad_installed');
        if ($mop !== null) {
            $this->checkAndUpdateCmd('serpillere', ((int) $mop === 1) ? 1 : 0);
        }

        $this->applyExtras($_props, $_values);
        $this->refreshWidget();
    }

    /*
     * Pose les propriétés secondaires décrites par la table.
     *
     * Une valeur absente du relevé n'écrase rien : c'est la même règle que pour
     * le reste, et elle vaut ici doublement, ces propriétés n'étant relues qu'au
     * cycle lent pour la plupart.
     */
    public function applyExtras($_props, $_values) {
        foreach (self::$extras as $name => $extra) {
            if (!isset($_props[$name])) {
                continue;
            }
            $key = $_props[$name][0] . '.' . $_props[$name][1];
            if (!array_key_exists($key, $_values)) {
                continue;
            }
            $value = $_values[$key];

            switch ($extra[2]) {
                case 'enum':
                    $table = $extra[3];
                    $this->checkAndUpdateCmd($extra[0], self::label(dreamebeSpec::$$table, $value));
                    break;
                case 'binary':
                    /* Le robot rend tantôt un booléen, tantôt un entier. */
                    $this->checkAndUpdateCmd($extra[0], ($value === true || (int) $value === 1) ? 1 : 0);
                    break;
                case 'date':
                    $stamp = (int) $value;
                    if ($stamp > 0) {
                        $this->checkAndUpdateCmd($extra[0], date('d/m/Y', $stamp));
                    }
                    break;
                default:
                    $this->checkAndUpdateCmd($extra[0], (int) $value);
            }
        }
    }

    /*
     * Ce qu'on ne relit pas à chaque cycle.
     *
     * Consommables, statistiques, pièces et carte changent lentement ou coûtent
     * cher. Les mêler au cycle principal serait le meilleur moyen de se faire
     * limiter par le cloud pour afficher un pourcentage de filtre qui bouge une
     * fois par semaine.
     */
    public function refreshExtras() {
        $now = time();

        if (($now - (int) $this->getCache('last_slow', 0)) >= max(600, (int) config::byKey('slow_interval', 'dreamebe', 1800))) {
            $this->setCache('last_slow', $now);
            try {
                $this->refreshConsumables();
                $this->refreshStatistics();
                $this->refreshSettings();
            } catch (Throwable $e) {
                log::add('dreamebe', 'info', $this->getHumanName()
                         . ' : entretien et statistiques non relus (' . $e->getMessage() . ')');
            }
        }

        if (config::byKey('map_enable', 'dreamebe', 1) == 1
            && ($now - (int) $this->getCache('last_map', 0)) >= max(300, (int) config::byKey('map_interval', 'dreamebe', 900))) {
            $this->setCache('last_map', $now);
            try {
                $this->refreshRooms();
                $this->refreshMap();
            } catch (Throwable $e) {
                log::add('dreamebe', 'info', $this->getHumanName()
                         . ' : carte non relue (' . $e->getMessage() . ')');
            }
        }

        if (config::byKey('history_enable', 'dreamebe', 1) == 1
            && ($now - (int) $this->getCache('last_history', 0)) >= 1800) {
            $this->setCache('last_history', $now);
            try {
                $this->refreshHistory();
            } catch (Throwable $e) {
                log::add('dreamebe', 'info', $this->getHumanName()
                         . ' : historique non relu (' . $e->getMessage() . ')');
            }
        }
    }

    public function refreshConsumables() {
        $props = array();
        foreach (dreamebeSpec::$consumables as $siid => $consumable) {
            if ($this->supported($consumable[0] . '_wear') !== true) {
                continue;
            }
            $props[$consumable[0] . '_wear'] = array($siid, $consumable[2]);
            $props[$consumable[0] . '_left'] = array($siid, $consumable[3]);
        }
        if (empty($props)) {
            return;
        }
        $values = self::api()->getProperties($this->device(), array_values($props));
        foreach (dreamebeSpec::$consumables as $siid => $consumable) {
            foreach (array('wear', 'left') as $part) {
                $name = $consumable[0] . '_' . $part;
                if (!isset($props[$name])) {
                    continue;
                }
                $key = $props[$name][0] . '.' . $props[$name][1];
                if (array_key_exists($key, $values)) {
                    $this->checkAndUpdateCmd($name, (int) $values[$key]);
                }
            }
        }
    }

    /*
     * Les réglages du robot : volume, températures, gestion des tapis…
     *
     * Ils ne bougent que lorsque quelqu'un les change, dans l'application ou
     * depuis Jeedom. Les relire à chaque cycle ne produirait que du trafic pour
     * un chiffre qui ne varie pas d'une semaine à l'autre.
     */
    public function refreshSettings() {
        $props = array();
        foreach (self::$extras as $name => $extra) {
            if ($extra[5] || $this->supported($name) !== true) {
                continue;
            }
            if (isset(dreamebeSpec::$properties[$name])) {
                $props[$name] = dreamebeSpec::$properties[$name];
            }
        }
        if (empty($props)) {
            return;
        }
        $values = self::api()->getProperties($this->device(), array_values($props));
        $this->applyExtras($props, $values);
    }

    public function refreshStatistics() {
        $props = array();
        foreach (array('total_time', 'total_count', 'total_area') as $name) {
            if ($this->supported($name) !== false && isset(dreamebeSpec::$properties[$name])) {
                $props[$name] = dreamebeSpec::$properties[$name];
            }
        }
        if (empty($props)) {
            return;
        }
        $values = self::api()->getProperties($this->device(), array_values($props));
        foreach ($props as $name => $prop) {
            $key = $prop[0] . '.' . $prop[1];
            if (array_key_exists($key, $values)) {
                $this->checkAndUpdateCmd($name, (int) $values[$key]);
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Pièces et carte
     * ------------------------------------------------------------------ */

    /* Une trame « I » : la carte complète. Les autres ne portent qu'un
     * différentiel, ou une carte de couverture Wi-Fi. */
    const FRAME_COMPLETE = 73;

    /*
     * Lit une propriété liée à la carte.
     *
     * L'image que le cloud garde du robot est interrogée EN PREMIER, et ce n'est
     * pas une optimisation : sur les L40 Ultra AE, la propriété qui porte le nom
     * de la carte courante n'est tout simplement PAS rendue quand on interroge
     * le robot directement — le cloud, lui, la connaît, parce que le robot la
     * lui pousse. Constaté sur deux machines : interrogation directe, rien ;
     * lecture de l'ombre, la valeur attendue.
     *
     * L'interrogation directe reste en second, pour les modèles qui feraient
     * l'inverse.
     */
    private function mapProperty($_name) {
        $prop = dreamebeSpec::$properties[$_name];
        $key = $prop[0] . '.' . $prop[1];

        try {
            $values = self::api()->shadowProperties($this->device(), array($prop));
            if (isset($values[$key]) && $values[$key] !== '') {
                return $values[$key];
            }
        } catch (dreamebeApiException $e) {
            log::add('dreamebe', 'debug', $this->getHumanName() . ' : image du cloud indisponible pour '
                     . $_name . ' (' . $e->getMessage() . ')');
        }

        $values = self::api()->getProperties($this->device(), array($prop));
        return isset($values[$key]) ? $values[$key] : null;
    }

    public function rooms() {
        $rooms = $this->getConfiguration('rooms', array());
        return is_array($rooms) ? $rooms : array();
    }

    /*
     * Va chercher les pièces.
     *
     * Le chemin est long — propriété, adresse signée, téléchargement,
     * déchiffrement, décompression, décodage — et il n'y en a pas d'autre : le
     * protocole n'expose nulle part la liste des pièces.
     */
    public function refreshRooms() {
        $device = $this->device();
        $list = $this->mapProperty('map_list');
        if ($list === null || $list === '') {
            return false;
        }
        if (is_string($list)) {
            $list = json_decode($list, true);
        }
        $objectName = null;
        if (is_array($list)) {
            $objectName = isset($list['object_name']) ? $list['object_name']
                        : (isset($list['obj_name']) ? $list['obj_name'] : null);
        }
        if ($objectName === null || $objectName === '') {
            return false;
        }

        $url = self::api()->fileUrl($device, $objectName);
        $content = self::api()->download($url);
        $parsed = dreamebeMap::parseMapList($content, dreamebeMap::ivForModel($device['model']));

        /*
         * Sur un logement à plusieurs étages, plusieurs cartes coexistent, et
         * seule celle qui est sélectionnée décrit ce que le robot va nettoyer.
         * Mais si aucune n'est marquée — carte courante illisible, ou champ
         * absent — mieux vaut les pièces du premier étage lisible que pas de
         * pièces du tout.
         */
        $retenue = null;
        foreach ($parsed['maps'] as $map) {
            if ($map['selected']) {
                $retenue = $map;
                break;
            }
        }
        if ($retenue === null && !empty($parsed['maps'])) {
            $retenue = $parsed['maps'][0];
            log::add('dreamebe', 'info', $this->getHumanName()
                     . ' : aucune carte marquée comme courante, la première est retenue.');
        }

        $rooms = array();
        if ($retenue !== null) {
            foreach ($retenue['segments'] as $segment) {
                $rooms[$segment['id']] = $segment;
            }
        }
        if (empty($rooms)) {
            return false;
        }

        $this->setConfiguration('rooms', $rooms);
        $this->setConfiguration('rooms_at', time());
        /* Enregistrement direct : postSave() referait l'ensemble des commandes
         * alors que seules celles des pièces ont pu changer. */
        $this->save(true);
        $this->createRoomCommands();
        log::add('dreamebe', 'info', $this->getHumanName() . ' : ' . count($rooms) . ' pièce(s) reconnue(s).');
        return true;
    }

    /*
     * La carte telle qu'elle est en ce moment : position du robot, et image.
     *
     * Séparée des pièces parce que ce n'est pas la même donnée : les pièces
     * vivent dans les cartes sauvegardées, la position du robot dans la carte
     * courante, qui, elle, ne porte pas les noms.
     */
    public function refreshMap() {
        $device = $this->device();
        /* La valeur est tantôt une chaîne, tantôt une liste, tantôt une chaîne
         * contenant une liste JSON selon le micrologiciel. Traiter les trois
         * coûte quelques lignes ; parier coûte une carte qui ne s'affiche
         * jamais. */
        $objectName = dreamebeMap::objectName($this->mapProperty('object_name'));
        if ($objectName === null) {
            return false;
        }

        /*
         * Le nom d'objet porte parfois la clé de déchiffrement, après une
         * virgule. L'envoyer telle quelle au cloud lui fait répondre « fichier
         * inconnu », et le message d'erreur qui en résulte accuse le mauvais
         * coupable.
         */
        $mapKey = null;
        if (strpos($objectName, ',') !== false) {
            $parts = explode(',', $objectName, 2);
            $objectName = $parts[0];
            $mapKey = $parts[1];
        }

        $url = self::api()->fileUrl($device, $objectName);
        $content = self::api()->download($url);
        $map = dreamebeMap::decode($content, dreamebeMap::ivForModel($device['model']), $mapKey);

        /*
         * Pendant un nettoyage, le robot publie surtout des trames partielles,
         * qui ne contiennent que ce qui a changé depuis la précédente. Les lire
         * comme une carte complète produit une image incohérente et une position
         * fantaisiste. Le plugin ne recompose pas les trames : il attend la
         * suivante qui soit complète.
         */
        if ((int) $map['frame_type'] !== self::FRAME_COMPLETE) {
            log::add('dreamebe', 'debug', $this->getHumanName()
                     . ' : trame de carte partielle ignorée, la prochaine carte complète sera reprise.');
            return false;
        }

        if ($map['robot'] !== null) {
            $this->checkAndUpdateCmd('position_x', $map['robot']['x']);
            $this->checkAndUpdateCmd('position_y', $map['robot']['y']);
            $this->checkAndUpdateCmd('orientation', $map['robot']['a']);
        }

        /*
         * L'image se dessine à partir de la carte SAUVEGARDÉE, pas de la carte
         * courante — et c'est contre-intuitif, donc il faut le dire.
         *
         * La carte courante ne porte ni les noms de pièces ni leur voisinage, et
         * sur ces machines ses pixels n'encodent pas les identifiants de la même
         * façon : la dessiner telle quelle donne un plan d'un seul tenant, d'une
         * seule couleur, où aucune pièce ne se distingue. Constaté.
         *
         * Or la carte courante EMBARQUE la carte sauvegardée, sous « rism ».
         * On la décode donc au passage — sans téléchargement supplémentaire —
         * et on lui greffe la position du robot, qui, elle, n'existe que dans la
         * carte courante. Les deux partagent le même repère en millimètres,
         * seule leur origine diffère : la conversion s'en charge.
         */
        $pourImage = $map;
        if (!empty($map['json']['rism'])) {
            try {
                $sauvegardee = dreamebeMap::decode($map['json']['rism'],
                                                   dreamebeMap::ivForModel($device['model']));
                $sauvegardee['robot'] = $map['robot'];
                $sauvegardee['charger'] = $map['charger'];
                $pourImage = $sauvegardee;
            } catch (dreamebeMapException $e) {
                log::add('dreamebe', 'info', $this->getHumanName()
                         . ' : carte sauvegardée jointe illisible, la carte courante sera dessinée '
                         . 'sans distinction de pièces (' . $e->getMessage() . ')');
            }
        }

        $path = self::mapDir() . '/' . $this->getId() . '.png';
        if (!dreamebeMapImage::render($pourImage, $path)) {
            return false;
        }
        /* L'horodatage est celui du FICHIER, pas de l'instant : il empêche le
         * navigateur de servir une image périmée, sans pour autant produire un
         * événement et une écriture à chaque cycle quand rien n'a bougé. */
        clearstatcache(true, $path);
        $this->checkAndUpdateCmd('carte', self::mapUrl($this->getId()) . '&t=' . (int) filemtime($path));
        return true;
    }

    public static function mapDir() {
        $dir = __DIR__ . '/../../data/maps';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function mapUrl($_id) {
        return 'plugins/dreamebe/core/php/map.php?id=' . $_id;
    }

    /*
     * Les derniers nettoyages.
     *
     * Il n'existe pas d'endpoint d'historique : ce que le cloud archive, ce sont
     * les événements de la propriété de statut, chacun portant une photographie
     * des propriétés du moment. Le plugin n'en fait pas des centaines de
     * commandes — il en tire les trois informations qu'un scénario utilise
     * vraiment, et garde le reste consultable dans l'interface.
     */
    public function refreshHistory() {
        $history = dreamebeApi::parseHistory(self::api()->history($this->device(), 0, 20));
        if (empty($history)) {
            return false;
        }

        $last = $history[0];
        $this->checkAndUpdateCmd('dernier_nettoyage', date('d/m/Y H:i', $last['date']));
        if ($last['duration'] !== null) {
            $this->checkAndUpdateCmd('derniere_duree', $last['duration']);
        }
        if ($last['area'] !== null) {
            $this->checkAndUpdateCmd('derniere_surface', $last['area']);
        }

        /* Direct : rien de ce qui définit les commandes n'a changé, et cette
         * méthode passe toutes les demi-heures sur chaque robot. */
        $this->setConfiguration('history', array_slice($history, 0, 20));
        $this->save(true);
        return true;
    }

    /* ------------------------------------------------------------------ *
     * Ordres
     * ------------------------------------------------------------------ */

    private function act($_name, $_in = array()) {
        if (!isset(dreamebeSpec::$actions[$_name])) {
            throw new Exception(__('Action inconnue :', __FILE__) . ' ' . $_name);
        }
        $action = dreamebeSpec::$actions[$_name];
        return $this->dispatch(function () use ($action, $_in) {
            return self::api()->action($this->device(), $action[0], $action[1], $_in);
        }, $_name);
    }

    /*
     * Envoie un ordre et interprète sa réponse.
     *
     * Deux subtilités, et les deux se paient au tableau de bord :
     *
     * 1. L'absence d'accusé de réception n'est PAS un échec. Le cloud cesse
     *    d'attendre au bout de quelques secondes, alors que le robot part
     *    exécuter l'ordre. Lever une exception ici afficherait « erreur » sur un
     *    robot qui se met en route, et ferait échouer l'étape d'un scénario pour
     *    une commande pourtant partie.
     * 2. Le refus, lui, est dans le code rendu et non dans le code HTTP — et ce
     *    code arrive tantôt à la racine, tantôt dans le premier élément d'une
     *    liste.
     */
    private function dispatch($_call, $_label) {
        try {
            $result = $_call();
        } catch (dreamebeApiException $e) {
            if ($e->getCode() === dreamebeApiException::NOACK) {
                log::add('dreamebe', 'info', $this->getHumanName() . ' : ordre « ' . $_label
                         . ' » transmis, sans accusé du robot dans le délai du cloud.');
                return null;
            }
            throw $e;
        }
        self::saveSession();

        $verdict = $result;
        if (is_array($verdict) && isset($verdict[0]) && is_array($verdict[0])) {
            $verdict = $verdict[0];
        }
        if (is_array($verdict) && isset($verdict['code']) && (int) $verdict['code'] !== 0) {
            throw new Exception(__('Le robot a refusé l\'ordre (code', __FILE__)
                                . ' ' . $verdict['code'] . ').');
        }
        return $result;
    }

    public function commandStart()  { return $this->act('start'); }
    public function commandPause()  { return $this->act('pause'); }
    public function commandStop()   { return $this->act('stop'); }
    public function commandDock()   { return $this->act('dock'); }
    public function commandLocate() { return $this->act('locate'); }
    /*
     * Acquitter l'alerte de la station.
     *
     * L'action n'efface rien si on ne lui dit pas QUOI effacer : elle attend la
     * liste JSON du code concerné. Et tous les codes ne s'effacent pas — le
     * robot ignore silencieusement les autres, ce qui laisse croire que le
     * bouton ne marche pas. Deux cas sortent du lot : une alerte de niveau
     * d'eau s'acquitte par une propriété dédiée, et une vidange terminée par la
     * remise à zéro de son propre état.
     */
    public function commandClearWarning() {
        $props = array('error' => dreamebeSpec::$properties['error']);
        if ($this->supported('low_water_warning') !== false) {
            $props['low_water_warning'] = dreamebeSpec::$properties['low_water_warning'];
        }
        $values = self::api()->getProperties($this->device(), array_values($props));

        $code = 0;
        $key = $props['error'][0] . '.' . $props['error'][1];
        if (isset($values[$key])) {
            $code = (int) $values[$key];
        }

        /* Alerte d'eau : c'est une propriété qui se remet à un, pas une action. */
        if (isset($props['low_water_warning'])) {
            $waterKey = $props['low_water_warning'][0] . '.' . $props['low_water_warning'][1];
            if (isset($values[$waterKey]) && (int) $values[$waterKey] > 0) {
                $prop = dreamebeSpec::$properties['low_water_warning'];
                $this->dispatch(function () use ($prop) {
                    return self::api()->setProperty($this->device(), $prop[0], $prop[1], 1);
                }, 'clear_water_warning');
                return true;
            }
        }

        if ($code === 0) {
            throw new Exception(__('Aucune alerte à acquitter.', __FILE__));
        }
        if (!dreamebeSpec::isClearable($code)) {
            throw new Exception(__('Cette alerte ne s\'acquitte pas depuis l\'application :', __FILE__)
                                . ' ' . self::errorLabel($code));
        }
        return $this->act('clear_warning', array(
            array('piid' => dreamebeSpec::PIID_ARG_PROPERTIES, 'value' => '[' . $code . ']'),
        ));
    }
    public function commandAutoEmpty()    { return $this->act('auto_empty'); }

    /*
     * Un ordre à la station. Le paramètre n'est pas du JSON mais un couple
     * « sous-commande,valeur » en clair : 2,1 lance le lavage, 3,1 le séchage,
     * 3,0 l'arrête. Ces codes sont relevés dans l'implémentation de référence ;
     * ceux qui n'y figurent pas ne sont pas repris, faute de savoir ce qu'ils
     * déclenchent sur une vraie machine.
     */
    /*
     * Lancer — ou reprendre — le lavage de la serpillière.
     *
     * Ce ne sont pas le même ordre : « démarrer » adressé à une station déjà
     * occupée est refusé, et l'utilisateur qui a mis le cycle en pause depuis
     * son téléphone verrait le bouton échouer sans comprendre.
     */
    public function commandWash() {
        $order = '2,1';
        if ($this->supported('wash_base_status') !== false) {
            $prop = dreamebeSpec::$properties['wash_base_status'];
            $values = self::api()->getProperties($this->device(), array($prop));
            $key = $prop[0] . '.' . $prop[1];
            if (isset($values[$key]) && (int) $values[$key] === 4) {
                $order = '1,1';   /* en pause : on reprend */
            }
        }
        return $this->commandStation($order);
    }

    public function commandStation($_order) {
        return $this->act('start_washing', array(
            array('piid' => dreamebeSpec::PIID_ARG_PROPERTIES, 'value' => (string) $_order),
        ));
    }

    /*
     * Nettoyage d'une ou plusieurs pièces.
     *
     * Le cinquième champ vaut 1 et non l'ordre de passage : c'est une exigence
     * des robots de cette génération, et y mettre un index séquentiel casse
     * l'opération — le commentaire est explicite dans l'implémentation de
     * référence. L'ordre, lui, se règle dans l'application.
     */
    public function commandCleanRooms($_ids, $_repeats = 1, $_suction = null, $_water = null) {
        $rooms = $this->rooms();
        $selects = array();
        foreach ($_ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $suction = ($_suction !== null) ? (int) $_suction
                     : (isset($rooms[$id]['suction']) ? (int) $rooms[$id]['suction'] : 1);
            $water = ($_water !== null) ? (int) $_water
                   : (isset($rooms[$id]['water']) ? (int) $rooms[$id]['water'] : 2);
            /* Le cinquième champ vaut 1 sur les robots qui gèrent le réglage
             * par pièce — et c'est le cas de toute la gamme visée ; y mettre un
             * index de passage casse l'opération sur ces machines. Sur les
             * autres, c'est bien l'ordre de passage qui est attendu. */
            $selects[] = array($id, max(1, (int) $_repeats), $suction, $water,
                               ($this->supported('customized_cleaning') === false) ? count($selects) + 1 : 1);
        }
        if (empty($selects)) {
            throw new Exception(__('Aucune pièce valide dans la demande.', __FILE__));
        }

        return $this->startCustom(dreamebeSpec::STATUS_SEGMENT_CLEANING,
                                  json_encode(array('selects' => $selects), JSON_UNESCAPED_SLASHES));
    }

    /*
     * Nettoyage d'une zone rectangulaire, en millimètres dans le repère de la
     * carte. Une zone trop petite est refusée par le robot sans explication : on
     * la refuse ici, avec une.
     */
    public function commandCleanZone($_x1, $_y1, $_x2, $_y2, $_repeats = 1, $_suction = null, $_water = null) {
        $x1 = (int) min($_x1, $_x2);
        $x2 = (int) max($_x1, $_x2);
        $y1 = (int) min($_y1, $_y2);
        $y2 = (int) max($_y1, $_y2);

        $minimum = 100;
        if (($x2 - $x1) <= $minimum || ($y2 - $y1) <= $minimum) {
            throw new Exception(__('Zone trop petite : chaque côté doit dépasser', __FILE__)
                                . ' ' . $minimum . ' mm.');
        }

        $area = array($x1, $y1, $x2, $y2, max(1, (int) $_repeats),
                      ($_suction !== null) ? (int) $_suction : 1,
                      ($_water !== null) ? (int) $_water : 2);
        return $this->startCustom(dreamebeSpec::STATUS_ZONE_CLEANING,
                                  json_encode(array('areas' => array($area)), JSON_UNESCAPED_SLASHES));
    }

    /*
     * Le mécanisme commun aux nettoyages ciblés : une action qui reçoit le mode
     * de départ dans une propriété, et la description de la cible dans une
     * autre, sous forme de CHAÎNE JSON — pas d'objet.
     */
    private function startCustom($_status, $_parameters = null) {
        $in = array(array('piid' => dreamebeSpec::PIID_ARG_STATUS, 'value' => (int) $_status));
        if ($_parameters !== null) {
            $in[] = array('piid' => dreamebeSpec::PIID_ARG_PROPERTIES, 'value' => (string) $_parameters);
        }
        $action = dreamebeSpec::$actions['start_custom'];
        return $this->dispatch(function () use ($action, $in) {
            return self::api()->action($this->device(), $action[0], $action[1], $in);
        }, 'start_custom');
    }

    public function commandSetSuction($_level) {
        if ($_level === null || $_level === '') {
            throw new Exception(__('Indiquez le niveau d\'aspiration (0 à 3).', __FILE__));
        }
        $level = max(0, min(3, (int) $_level));
        $prop = dreamebeSpec::$properties['suction'];
        $this->dispatch(function () use ($prop, $level) {
            return self::api()->setProperty($this->device(), $prop[0], $prop[1], $level);
        }, 'set_water');
        $this->checkAndUpdateCmd('aspiration', $level);
        $this->checkAndUpdateCmd('aspiration_texte', self::label(dreamebeSpec::$suctionLevels, $level));
    }

    /*
     * L'humidité de la serpillière, ou le niveau d'eau selon la machine.
     *
     * Sur un robot à base de lavage, ce réglage n'a pas de propriété à lui : il
     * faut relire la valeur empaquetée, y remplacer le seul octet concerné et
     * tout réécrire. Écrire la valeur seule mettrait le mode de nettoyage à
     * zéro au passage.
     */
    public function commandSetWater($_level) {
        if ($_level === null || $_level === '') {
            throw new Exception(__('Indiquez le niveau (1 à 3).', __FILE__));
        }
        $level = max(1, min(3, (int) $_level));
        if (!$this->hasWashBase()) {
            $prop = dreamebeSpec::$properties['water_volume'];
            $this->dispatch(function () use ($prop, $level) {
                return self::api()->setProperty($this->device(), $prop[0], $prop[1], $level);
            }, 'set_water_volume');
            $this->checkAndUpdateCmd('eau', $level);
            $this->checkAndUpdateCmd('eau_texte', self::label(dreamebeSpec::$waterVolumes, $level));
            return;
        }

        /*
         * Trois emplacements possibles selon la génération, et un seul est le bon
         * sur une machine donnée :
         *
         *  - la propriété d'humidité fine, sur les robots à base de lavage
         *    récents — une échelle de 1 à 32, dont on ne retient que les trois
         *    crans que l'application propose elle-même, pour ne pas afficher
         *    dans Jeedom un réglage qu'elle ne saurait pas représenter ;
         *  - sa variante embarquée, sur les modèles d'entrée de gamme ;
         *  - à défaut, l'octet d'humidité de la valeur empaquetée.
         *
         * C'est le sondage qui tranche, pas une liste de modèles — mais l'ORDRE
         * compte : sur toute la gamme visée ici, c'est la première qui fait foi.
         * Essayer la variante embarquée d'abord ferait écrire dans une propriété
         * que le robot n'utilise pas : la commande semblerait réussir, et le
         * cycle suivant réafficherait l'ancienne valeur.
         */
        foreach (array('wetness_level', 'wetness_onboard') as $name) {
            if ($this->supported($name) !== true) {
                continue;
            }
            $prop = dreamebeSpec::$properties[$name];
            $wetness = dreamebeSpec::$wetnessLevels[$level];
            $this->dispatch(function () use ($prop, $wetness) {
                return self::api()->setProperty($this->device(), $prop[0], $prop[1], $wetness);
            }, 'set_wetness');
            $this->checkAndUpdateCmd('humidite_niveau', $wetness);
            $this->checkAndUpdateCmd('humidite', $level);
            $this->checkAndUpdateCmd('humidite_texte', self::label(dreamebeSpec::$mopHumidity, $level));
            return;
        }

        $prop = dreamebeSpec::$properties['cleaning_mode'];
        $current = self::api()->getProperties($this->device(), array($prop));
        $key = $prop[0] . '.' . $prop[1];
        if (!isset($current[$key])) {
            throw new Exception(__('Mode de nettoyage illisible : réglage annulé.', __FILE__));
        }
        $parts = dreamebeSpec::splitMode($current[$key], true);
        $value = dreamebeSpec::combineMode($parts['mode'], $parts['self_clean'], $level, true);
        $this->dispatch(function () use ($prop, $value) {
            return self::api()->setProperty($this->device(), $prop[0], $prop[1], $value);
        }, 'set_mode');
        $this->checkAndUpdateCmd('humidite', $level);
        $this->checkAndUpdateCmd('humidite_texte', self::label(dreamebeSpec::$mopHumidity, $level));
    }

    public function commandSetMode($_mode) {
        if ($_mode === null || $_mode === '') {
            throw new Exception(__('Indiquez le mode de nettoyage (0 à 3).', __FILE__));
        }
        $mode = max(0, min(3, (int) $_mode));
        $prop = dreamebeSpec::$properties['cleaning_mode'];
        $current = self::api()->getProperties($this->device(), array($prop));
        $key = $prop[0] . '.' . $prop[1];
        if (!isset($current[$key])) {
            throw new Exception(__('Mode de nettoyage illisible : réglage annulé.', __FILE__));
        }
        $parts = dreamebeSpec::splitMode($current[$key], $this->hasWashBase());
        $value = dreamebeSpec::combineMode($mode, $parts['self_clean'], $parts['humidity'], $this->hasWashBase());
        $this->dispatch(function () use ($prop, $value) {
            return self::api()->setProperty($this->device(), $prop[0], $prop[1], $value);
        }, 'set_mode');
        $this->checkAndUpdateCmd('mode', $mode);
        $this->checkAndUpdateCmd('mode_texte', self::label(dreamebeSpec::$cleaningModes, $mode));
    }

    /*
     * Règle une des propriétés secondaires.
     *
     * Le transtypage compte : le robot rend « ne pas déranger » en booléen, et
     * lui réécrire 1 là où il attend true ne produit rien de visible — la
     * commande semble réussir, et le cycle suivant réaffiche l'ancienne valeur.
     */
    public function commandSetExtra($_name, $_value) {
        if (!isset(self::$extraActions[$_name])) {
            throw new Exception(__('Réglage inconnu :', __FILE__) . ' ' . $_name);
        }
        if ($_value === null || $_value === '') {
            throw new Exception(__('Indiquez une valeur pour ce réglage.', __FILE__));
        }
        $action = self::$extraActions[$_name];
        $extra = self::$extras[$_name];
        $prop = dreamebeSpec::$properties[$_name];
        $value = ($action[5] === 'bool') ? ((int) $_value === 1) : (int) $_value;

        $this->dispatch(function () use ($prop, $value) {
            return self::api()->setProperty($this->device(), $prop[0], $prop[1], $value);
        }, 'set_' . $_name);

        /* Rendre la main avec l'ancienne valeur affichée donnerait l'impression
         * que l'ordre n'est pas passé, jusqu'au prochain cycle lent — soit une
         * demi-heure. */
        $this->applyExtras(array($_name => $prop),
                           array($prop[0] . '.' . $prop[1] => $value));
        return true;
    }

    public function commandResetConsumable($_siid) {
        $action = dreamebeSpec::resetAction($_siid);
        $result = $this->dispatch(function () use ($action) {
            return self::api()->action($this->device(), $action[0], $action[1], array());
        }, 'reset_consumable');
        /* Le compteur vient de repartir de zéro : forcer sa relecture au
         * prochain cycle plutôt que d'attendre la demi-heure suivante. */
        $this->setCache('last_slow', 0);
        return $result;
    }

    /* ------------------------------------------------------------------ *
     * Cycle de vie
     * ------------------------------------------------------------------ */

    /*
     * Aucune validation stricte ici : le cœur crée l'équipement avec son seul
     * nom, et une exception rendrait le bouton « Ajouter » définitivement
     * inopérant.
     */
    public function preSave() {
        if ($this->getLogicalId() == '' && $this->getConfiguration('did', '') != '') {
            $this->setLogicalId($this->getConfiguration('did'));
        }
    }

    public function postSave() {
        $this->createCommands();
    }

    public function createCommands() {
        $this->_expected = array();
        $this->_usedNames = array();

        /*
         * Tant que le robot n'a pas été sondé, on ne crée que ce qui vaut pour
         * toute la gamme. Deviner le reste reviendrait à poser des commandes
         * qu'il faudrait retirer ensuite : un équipement fraîchement découvert
         * afficherait un « Niveau d'eau » que sa base de lavage rend faux, et
         * l'utilisateur aurait déjà pu le glisser dans un scénario.
         */
        $probed = ($this->supported('state') !== null);

        $this->addCmd('etat', 'État', 'info', 'string', array('order' => 1));
        $this->addCmd('statut', 'Statut', 'info', 'string', array('order' => 2));
        $this->addCmd('en_activite', 'En activité', 'info', 'binary', array('order' => 3));
        $this->addCmd('en_ligne', 'En ligne', 'info', 'binary', array('order' => 4));
        $this->addCmd('batterie', 'Batterie', 'info', 'numeric',
                      array('order' => 5, 'unite' => '%', 'generic' => 'BATTERY', 'historize' => 1));
        $this->addCmd('en_charge', 'En charge', 'info', 'binary',
                      array('order' => 6, 'generic' => 'BATTERY_CHARGING'));
        $this->addCmd('charge', 'État de charge', 'info', 'string', array('order' => 7, 'visible' => 0));
        $this->addCmd('erreur', 'Erreur', 'info', 'string', array('order' => 8));
        $this->addCmd('code_erreur', 'Code erreur', 'info', 'numeric', array('order' => 9, 'visible' => 0));
        $this->addCmd('en_erreur', 'En erreur', 'info', 'binary', array('order' => 10));
        $this->addCmd('en_alerte', 'Alerte station', 'info', 'binary', array('order' => 11));
        $this->addCmd('code_etat', 'Code état', 'info', 'numeric', array('order' => 12, 'visible' => 0));

        $this->addCmd('duree', 'Durée du nettoyage', 'info', 'numeric',
                      array('order' => 20, 'unite' => 'min', 'historize' => 1));
        $this->addCmd('surface', 'Surface nettoyée', 'info', 'numeric',
                      array('order' => 21, 'unite' => 'm²', 'historize' => 1));
        $this->addCmd('tache', 'Tâche', 'info', 'string', array('order' => 22));
        if ($this->supported('cleaning_progress') !== false) {
            $this->addCmd('progression', 'Progression', 'info', 'numeric',
                          array('order' => 23, 'unite' => '%'));
        }

        /* « Niveau d'aspiration » et non « Aspiration » : ce dernier désigne
         * aussi un ÉTAT du robot, et le catalogue de traduction est un
         * dictionnaire plat — une même chaîne française ne peut pas s'y
         * traduire deux fois. Le nom du réglage et celui de l'état doivent donc
         * différer, faute de quoi l'un des deux serait faux en anglais. */
        $this->addCmd('aspiration', 'Niveau aspiration', 'info', 'numeric',
                      array('order' => 30, 'visible' => 0, 'generic' => 'FAN_SPEED_STATE'));
        $this->addCmd('aspiration_texte', 'Aspiration (texte)', 'info', 'string', array('order' => 31));

        if ($probed && $this->hasWashBase()) {
            $this->addCmd('mode', 'Mode', 'info', 'numeric', array('order' => 32, 'visible' => 0));
            $this->addCmd('mode_texte', 'Mode (texte)', 'info', 'string', array('order' => 33));
            $this->addCmd('humidite', 'Humidité', 'info', 'numeric', array('order' => 34, 'visible' => 0));
            $this->addCmd('humidite_texte', 'Humidité (texte)', 'info', 'string', array('order' => 35));
            if ($this->supported('wetness_level') === true || $this->supported('wetness_onboard') === true) {
                $this->addCmd('humidite_niveau', 'Humidité (niveau fin)', 'info', 'numeric',
                              array('order' => 35, 'visible' => 0));
            }
            $this->addCmd('station', 'Station', 'info', 'string',
                          array('order' => 36, 'generic' => 'DOCK_STATE'));
            $this->addCmd('alerte_eau', 'Alerte eau', 'info', 'string', array('order' => 37));
            $this->addCmd('reservoir_propre', 'Réservoir eau propre', 'info', 'string', array('order' => 38));
            $this->addCmd('reservoir_sale', 'Réservoir eau sale', 'info', 'string', array('order' => 39));
            $this->addCmd('sac', 'Sac à poussière', 'info', 'string', array('order' => 40));
        } elseif ($probed) {
            $this->addCmd('eau', 'Niveau eau', 'info', 'numeric', array('order' => 32, 'visible' => 0));
            $this->addCmd('eau_texte', 'Niveau eau (texte)', 'info', 'string', array('order' => 33));
        }
        $this->addCmd('reservoir', 'Réservoir', 'info', 'string', array('order' => 41, 'visible' => 0));
        $this->addCmd('serpillere', 'Serpillière posée', 'info', 'binary', array('order' => 42));

        /* --- Ordres ---------------------------------------------------- */
        $this->addCmd('demarrer', 'Démarrer', 'action', 'other', array('order' => 50));
        $this->addCmd('pause', 'Pause', 'action', 'other', array('order' => 51));
        $this->addCmd('reprendre', 'Reprendre', 'action', 'other', array('order' => 52));
        $this->addCmd('arreter', 'Arrêter', 'action', 'other', array('order' => 53));
        /* Même raison : « Retour à la station » est un état du robot. L'ordre,
         * lui, se nomme à l'infinitif — ce qui est de toute façon la bonne
         * façon de nommer un bouton. */
        $this->addCmd('retour_station', 'Retourner à la station', 'action', 'other',
                      array('order' => 54, 'generic' => 'DOCK'));
        $this->addCmd('localiser', 'Localiser', 'action', 'other', array('order' => 55));
        $this->addCmd('acquitter', 'Acquitter le message', 'action', 'other', array('order' => 56));

        $this->addCmd('regler_aspiration', 'Régler la puissance', 'action', 'select',
                      array('order' => 60, 'value' => $this->cmdId('aspiration'),
                            'generic' => 'FAN_SPEED',
                            'listValue' => __('0|Silencieux;1|Standard;2|Fort;3|Turbo', __FILE__)));
        if ($probed && $this->hasWashBase()) {
            $this->addCmd('regler_humidite', 'Régler le taux humidité', 'action', 'select',
                          array('order' => 61, 'value' => $this->cmdId('humidite'),
                                'listValue' => __('1|Peu humide;2|Humide;3|Très humide', __FILE__)));
            $this->addCmd('regler_mode', 'Régler le mode', 'action', 'select',
                          array('order' => 62, 'value' => $this->cmdId('mode'),
                                'listValue' => __('0|Aspiration seule;1|Lavage seul;2|Aspiration et lavage;3|Lavage après aspiration', __FILE__)));
            $this->addCmd('laver_serpillere', 'Laver la serpillière', 'action', 'other', array('order' => 63));
            $this->addCmd('secher_serpillere', 'Sécher la serpillière', 'action', 'other', array('order' => 64));
            $this->addCmd('arreter_sechage', 'Arrêter le séchage', 'action', 'other', array('order' => 65));
        } elseif ($probed) {
            $this->addCmd('regler_eau', 'Régler le débit eau', 'action', 'select',
                          array('order' => 61, 'value' => $this->cmdId('eau'),
                                'listValue' => __('1|Faible;2|Moyen;3|Élevé', __FILE__)));
        }
        if ($this->supported('dust_collection') === true || $this->supported('auto_empty_status') === true) {
            $this->addCmd('vider_bac', 'Vider le bac', 'action', 'other', array('order' => 66));
        }

        /* Les commandes à paramètres : c'est ce qui rend les scénarios utiles,
         * plutôt qu'une commande figée par pièce et par réglage. */
        $this->addCmd('nettoyer_pieces', 'Nettoyer des pièces', 'action', 'message',
                      array('order' => 70));
        $this->addCmd('nettoyer_zone', 'Nettoyer une zone', 'action', 'message',
                      array('order' => 71));

        /* --- Entretien ------------------------------------------------- */
        $order = 80;
        foreach (dreamebeSpec::$consumables as $siid => $consumable) {
            if ($this->supported($consumable[0] . '_wear') !== true) {
                continue;
            }
            /*
             * Le nom du consommable est traduit à part, puis composé : un
             * libellé assemblé par concaténation ne traverse jamais __() en
             * entier, et resterait donc en français dans une interface
             * anglaise, quoi qu'on mette dans le catalogue.
             */
            $libelle = __($consumable[1], __FILE__);
            $this->addCmd($consumable[0] . '_wear', $libelle, 'info', 'numeric',
                          array('order' => $order++, 'unite' => '%', 'historize' => 1));
            $this->addCmd($consumable[0] . '_left', sprintf(__('%s (durée)', __FILE__), $libelle),
                          'info', 'numeric',
                          array('order' => $order++, 'unite' => $consumable[4], 'visible' => 0));
            $this->addCmd('raz_' . $consumable[0], sprintf(__('Remettre à zéro : %s', __FILE__), $libelle),
                          'action', 'other', array('order' => $order++, 'visible' => 0));
        }

        /* --- Statistiques et historique -------------------------------- */
        $this->addCmd('total_time', 'Durée totale', 'info', 'numeric',
                      array('order' => 120, 'unite' => 'min', 'visible' => 0));
        $this->addCmd('total_area', 'Surface totale', 'info', 'numeric',
                      array('order' => 121, 'unite' => 'm²', 'visible' => 0));
        $this->addCmd('total_count', 'Nombre de nettoyages', 'info', 'numeric',
                      array('order' => 122, 'visible' => 0));
        if (config::byKey('history_enable', 'dreamebe', 1) == 1) {
            $this->addCmd('dernier_nettoyage', 'Dernier nettoyage', 'info', 'string', array('order' => 123));
            $this->addCmd('derniere_duree', 'Durée du dernier nettoyage', 'info', 'numeric',
                          array('order' => 124, 'unite' => 'min'));
            $this->addCmd('derniere_surface', 'Surface du dernier nettoyage', 'info', 'numeric',
                          array('order' => 125, 'unite' => 'm²'));
        }

        /* --- Carte ------------------------------------------------------ */
        if (config::byKey('map_enable', 'dreamebe', 1) == 1) {
            $this->addCmd('position_x', 'Position X', 'info', 'numeric',
                          array('order' => 130, 'unite' => 'mm', 'visible' => 0));
            $this->addCmd('position_y', 'Position Y', 'info', 'numeric',
                          array('order' => 131, 'unite' => 'mm', 'visible' => 0));
            $this->addCmd('orientation', 'Orientation', 'info', 'numeric',
                          array('order' => 132, 'unite' => '°', 'visible' => 0));
            /* Le gabarit fait de cette adresse une image sur le tableau de
             * bord. Il n'est posé qu'à la création : le réimposer à chaque
             * sauvegarde défairait le choix d'un utilisateur qui aurait préféré
             * un autre affichage. */
            $this->addCmd('carte', 'Carte', 'info', 'string',
                          array('order' => 133, 'template' => 'dreamebe::dreamebeMap'));
        }

        /* --- Propriétés secondaires ------------------------------------ */
        foreach (self::$extras as $name => $extra) {
            if ($this->supported($name) !== true) {
                continue;
            }
            $options = array('order' => $extra[4]);
            /* Une énumération et une date s'affichent en toutes lettres ; le
             * reste garde son type, pour rester utilisable dans un calcul. */
            $subType = in_array($extra[2], array('enum', 'date'), true) ? 'string' : $extra[2];
            if ($extra[2] === 'numeric' && $extra[3] !== null) {
                $options['unite'] = $extra[3];
            }
            $this->addCmd($extra[0], $extra[1], 'info', $subType, $options);
        }

        foreach (self::$extraActions as $name => $action) {
            if ($this->supported($name) !== true) {
                continue;
            }
            $options = array('order' => $action[4], 'value' => $this->cmdId(self::$extras[$name][0]));
            if ($action[3] !== null) {
                $options['listValue'] = __($action[3], __FILE__);
            }
            if ($action[2] === 'slider') {
                $options['min'] = 0;
                $options['max'] = 100;
            }
            $this->addCmd($action[0], $action[1], 'action', $action[2], $options);
        }

        /* La liste des pièces, en clair. Un scénario peut ainsi les énumérer
         * sans que leurs noms soient écrits en dur dans son code. */
        $this->addCmd('pieces', 'Pièces', 'info', 'string', array('order' => 199));

        $this->createRoomCommands();
        $this->pruneCommands();
    }

    /*
     * Retire les commandes que le plugin avait posées et qui n'ont plus lieu
     * d'être.
     *
     * Le cas qui l'impose : à la découverte, le robot n'a pas encore parlé et
     * seules les commandes universelles existent ; le sondage révèle ensuite une
     * base de lavage, et le jeu « niveau d'eau » — s'il avait été créé — doit
     * disparaître. Le laisser en place donnerait une commande qui ne répond
     * jamais, et que rien ne signale comme morte.
     *
     * Trois garde-fous, et le troisième est le plus important :
     *
     * 1. on ne touche qu'aux commandes portant notre marque, donc jamais à
     *    celles que l'utilisateur a ajoutées lui-même ;
     * 2. les commandes de pièces ont leur propre élagage, dans
     *    createRoomCommands(), qui seul sait quelles pièces existent encore ;
     * 3. une commande qui a DÉJÀ porté une valeur n'est jamais supprimée.
     *
     * Ce dernier point protège contre le scénario qui ferait le plus de dégâts :
     * un sondage passe au moment où le robot répond mal, une propriété manque à
     * l'appel, et la commande correspondante serait effacée — avec son
     * historique, définitivement. Une commande devenue inutile mais qui a
     * fonctionné un jour reste donc en place : elle ne coûte qu'une ligne dans
     * une liste, là où sa suppression coûterait des mois de mesures.
     */
    private function pruneCommands() {
        /* Tant que le robot n'a pas parlé, on ne sait pas ce qu'il ne gère
         * pas : ne rien retirer sur une supposition. */
        if ($this->supported('state') === null) {
            return;
        }
        foreach ($this->getCmd() as $cmd) {
            $logicalId = $cmd->getLogicalId();
            if ($logicalId === '' || strpos($logicalId, 'room::') === 0) {
                continue;
            }
            if ($cmd->getConfiguration('managed', 0) != 1) {
                continue;
            }
            if (in_array($logicalId, $this->_expected, true)) {
                continue;
            }
            /*
             * Une commande qui a déjà porté une valeur n'est jamais supprimée.
             *
             * Le test porte sur le CACHE et non sur getValueDate() : cette
             * propriété-là est transitoire, elle n'est renseignée que par
             * execCmd(), et vaut la chaîne vide sur un objet fraîchement lu en
             * base. S'y fier rendrait le garde-fou inopérant sans que rien ne le
             * montre — et c'est justement le garde-fou qui protège des mois
             * d'historique.
             */
            if ($cmd->getIsHistorized() == 1 || $cmd->getCache('value', null) !== null) {
                continue;
            }
            log::add('dreamebe', 'info', $this->getHumanName() . ' : commande « ' . $cmd->getName()
                     . ' » retirée, ce robot ne la gère pas.');
            $cmd->remove();
        }
    }

    /*
     * Une commande par pièce.
     *
     * C'est redondant avec « Nettoyer des pièces », et c'est voulu : dans un
     * scénario Jeedom, cliquer une commande nommée « Nettoyer la cuisine » vaut
     * mieux que retenir qu'il faut envoyer « 3 » à une commande générique. Les
     * deux coexistent, chacune pour son usage.
     */
    public function createRoomCommands() {
        $rooms = $this->rooms();
        /* Appelée seule depuis refreshRooms(), sans passer par createCommands() :
         * la liste des commandes attendues est alors vide, et il ne faut pas que
         * l'élagage général s'y fie. Il n'est de toute façon pas déclenché ici. */
        $order = 200;
        /*
         * Deux pièces peuvent porter le même nom — deux « Bureau » du catalogue,
         * ou deux pièces renommées à l'identique dans l'application. Jeedom
         * impose des noms de commande uniques par équipement : sans ce
         * dédoublonnage, l'enregistrement de l'équipement échoue en entier sur
         * une erreur SQL, la page se rafraîchit et la saisie disparaît, sans que
         * le journal du plugin en dise un mot.
         */
        $pris = array();
        foreach ($rooms as $room) {
            $nom = 'Nettoyer : ' . $room['name'];
            if (isset($pris[$nom])) {
                $pris[$nom]++;
                $nom .= ' (' . $pris[$nom] . ')';
            } else {
                $pris[$nom] = 1;
            }
            $this->addCmd('room::' . $room['id'], $nom, 'action', 'other', array('order' => $order++));
        }

        /* La liste en clair, posée ici plutôt qu'à la relecture de la carte :
         * c'est le seul endroit traversé dans tous les cas, y compris à la
         * création des commandes sur des pièces déjà connues. */
        $noms = array();
        foreach ($rooms as $room) {
            $noms[] = $room['name'];
        }
        $this->checkAndUpdateCmd('pieces', implode(', ', $noms));

        /* Une pièce fusionnée ou supprimée dans l'application laisserait une
         * commande qui échouerait en silence. */
        foreach ($this->getCmd('action') as $cmd) {
            if (strpos($cmd->getLogicalId(), 'room::') !== 0) {
                continue;
            }
            $id = (int) substr($cmd->getLogicalId(), 6);
            if (!isset($rooms[$id])) {
                $cmd->remove();
            }
        }
    }

    private function cmdId($_logicalId) {
        $cmd = $this->getCmd(null, $_logicalId);
        return is_object($cmd) ? $cmd->getId() : null;
    }

    /*
     * Crée une commande si elle manque, et met à jour ce qui peut changer sans
     * casser l'existant.
     *
     * Ce qui est réglé par l'utilisateur — visibilité, historisation — n'est
     * posé qu'à la création : le redéfinir à chaque sauvegarde reviendrait à
     * défaire ses choix à chaque mise à jour du plugin.
     */
    private function addCmd($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $this->_expected[] = $_logicalId;
        $cmd = $this->getCmd(null, $_logicalId);
        $isNew = !is_object($cmd);
        if ($isNew) {
            $cmd = new dreamebeCmd();
            $cmd->setLogicalId($_logicalId);
            $cmd->setEqLogic_id($this->getId());
            /*
             * Masquée par défaut, et c'est délibéré.
             *
             * Un robot expose ici une centaine de commandes ; toutes visibles,
             * elles noyaient l'essentiel. La tuile du tableau de bord montre ce
             * qui compte sans se soucier de ce drapeau — il ne sert qu'à celui
             * qui revient à la présentation d'origine du coeur, et là encore
             * mieux vaut une dizaine de lignes qu'une centaine.
             *
             * Ne s'applique qu'à la création : ce que l'utilisateur a réglé
             * ensuite lui appartient.
             */
            $cmd->setIsVisible(isset($_options['visible']) ? $_options['visible']
                               : self::defaultVisibility($_logicalId));
            $cmd->setIsHistorized(isset($_options['historize']) ? $_options['historize'] : 0);
        }
        /*
         * Deux commandes ne peuvent pas porter le même nom sur un équipement :
         * la contrainte est au niveau de la base, et l'enregistrement échoue en
         * entier — pas seulement la commande fautive. Le symptôme, côté
         * utilisateur, est une page qui se rafraîchit et une saisie perdue.
         *
         * Plutôt que de compter sur la vigilance à chaque ajout dans les tables,
         * on dédoublonne ici. C'est arrivé : « Ne pas déranger » désignait à la
         * fois l'état et son réglage.
         */
        $name = __($_name, __FILE__);
        if (isset($this->_usedNames[$name]) && $this->_usedNames[$name] !== $_logicalId) {
            $suffixe = 2;
            while (isset($this->_usedNames[$name . ' ' . $suffixe])) {
                $suffixe++;
            }
            $name = $name . ' ' . $suffixe;
        }
        $this->_usedNames[$name] = $_logicalId;
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        if (isset($_options['order'])) {
            $cmd->setOrder($_options['order']);
        }
        if (isset($_options['unite'])) {
            $cmd->setUnite($_options['unite']);
        }
        if (isset($_options['generic'])) {
            $cmd->setGeneric_type($_options['generic']);
        }
        if (isset($_options['value']) && $_options['value'] !== null) {
            $cmd->setValue($_options['value']);
        }
        if (isset($_options['listValue'])) {
            $cmd->setConfiguration('listValue', $_options['listValue']);
        }
        if (isset($_options['min'])) {
            $cmd->setConfiguration('minValue', $_options['min']);
        }
        if (isset($_options['max'])) {
            $cmd->setConfiguration('maxValue', $_options['max']);
        }
        /* La marque qui distingue nos commandes de celles que l'utilisateur
         * aurait ajoutées à la main : seules les nôtres peuvent être retirées
         * quand elles n'ont plus lieu d'être. */
        $cmd->setConfiguration('managed', 1);
        if ($isNew && isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    /* ------------------------------------------------------------------ *
     * Santé
     * ------------------------------------------------------------------ */

    public static function health() {
        $rows = array();

        $configured = trim(config::byKey('username', 'dreamebe', '')) !== ''
                   && trim(config::byKey('password', 'dreamebe', '')) !== '';
        $rows[] = array(
            'test' => __('Compte DreameHome', __FILE__),
            'state' => $configured,
            'result' => $configured ? __('Renseigné', __FILE__) : __('À configurer', __FILE__),
            'advice' => $configured ? '' : __('Renseignez le compte dans la configuration du plugin.', __FILE__),
        );

        $session = dreamebeApi::normalizeSession(config::byKey('session', 'dreamebe', ''));
        $valid = ($session !== null) && !empty($session['token'])
              && (int) $session['expires_at'] > time();
        $rows[] = array(
            'test' => __('Session', __FILE__),
            'state' => $valid,
            'result' => $valid ? __('Ouverte jusqu\'à', __FILE__) . ' ' . date('H:i', $session['expires_at'])
                               : __('Fermée', __FILE__),
            'advice' => $valid ? '' : __('Elle se rouvrira au prochain cycle.', __FILE__),
        );

        foreach (self::byType('dreamebe') as $eqLogic) {
            /* La dernière lecture RÉUSSIE, pas la dernière tentative : sinon un
             * robot qui échoue depuis une semaine serait rapporté sain. */
            $last = (int) $eqLogic->getCache('last_success', 0);
            $fresh = ($last > 0 && (time() - $last) < 3600);
            $rows[] = array(
                'test' => $eqLogic->getHumanName(true),
                'state' => $fresh,
                'result' => ($last > 0) ? __('Dernière lecture', __FILE__) . ' ' . date('d/m H:i', $last)
                                        : __('Jamais interrogé', __FILE__),
                'advice' => $fresh ? '' : __('Vérifiez que le robot est allumé et connecté.', __FILE__),
            );
        }
        return $rows;
    }
}

class dreamebeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable.', __FILE__));
        }
        if ($this->getType() != 'action') {
            return;
        }

        $logicalId = $this->getLogicalId();

        /* Nettoyage d'une pièce précise. */
        if (strpos($logicalId, 'room::') === 0) {
            return $eqLogic->commandCleanRooms(array((int) substr($logicalId, 6)));
        }
        if (strpos($logicalId, 'raz_') === 0) {
            foreach (dreamebeSpec::$consumables as $siid => $consumable) {
                if ($logicalId === 'raz_' . $consumable[0]) {
                    return $eqLogic->commandResetConsumable($siid);
                }
            }
            throw new Exception(__('Consommable inconnu :', __FILE__) . ' ' . $logicalId);
        }

        /* Les réglages secondaires sont décrits par une table : les aiguiller
         * un par un aurait demandé autant de cas que d'entrées, et un oubli à
         * chaque ajout. */
        foreach (dreamebe::$extraActions as $name => $action) {
            if ($logicalId === $action[0]) {
                return $eqLogic->commandSetExtra($name, self::argument($_options));
            }
        }

        switch ($logicalId) {
            case 'demarrer':
            case 'reprendre':
                return $eqLogic->commandStart();
            case 'pause':
                return $eqLogic->commandPause();
            case 'arreter':
                return $eqLogic->commandStop();
            case 'retour_station':
                return $eqLogic->commandDock();
            case 'localiser':
                return $eqLogic->commandLocate();
            case 'acquitter':
                return $eqLogic->commandClearWarning();
            case 'vider_bac':
                return $eqLogic->commandAutoEmpty();
            case 'laver_serpillere':
                return $eqLogic->commandWash();
            case 'secher_serpillere':
                return $eqLogic->commandStation('3,1');
            case 'arreter_sechage':
                return $eqLogic->commandStation('3,0');
            case 'regler_aspiration':
                return $eqLogic->commandSetSuction(self::argument($_options));
            case 'regler_humidite':
            case 'regler_eau':
                return $eqLogic->commandSetWater(self::argument($_options));
            case 'regler_mode':
                return $eqLogic->commandSetMode(self::argument($_options));
            case 'nettoyer_pieces':
                return self::cleanRooms($eqLogic, self::argument($_options));
            case 'nettoyer_zone':
                return self::cleanZone($eqLogic, self::argument($_options));
        }
        throw new Exception(__('Commande non gérée :', __FILE__) . ' ' . $logicalId);
    }

    /*
     * Le paramètre d'une commande, quel que soit le chemin par lequel il arrive.
     *
     * Une liste déroulante envoie « select », un message envoie « message », un
     * curseur envoie « slider », et un appel direct peut envoyer une chaîne nue.
     * Les traiter tous évite qu'une commande marche depuis un scénario et pas
     * depuis le tableau de bord.
     */
    private static function argument($_options) {
        if (!is_array($_options)) {
            return $_options;
        }
        foreach (array('select', 'message', 'slider', 'title', 'value') as $key) {
            if (isset($_options[$key]) && $_options[$key] !== '') {
                return $_options[$key];
            }
        }
        return null;
    }

    /*
     * Accepte des identifiants comme des noms de pièces : « 3,5 » autant que
     * « Cuisine, Salon ». Dans un scénario, le nom est ce dont on se souvient.
     */
    private static function roomList($_eqLogic, $_argument) {
        if ($_argument === null || trim((string) $_argument) === '') {
            throw new Exception(__('Indiquez les pièces à nettoyer, séparées par des virgules.', __FILE__));
        }
        $rooms = $_eqLogic->rooms();
        $ids = array();
        foreach (explode(',', (string) $_argument) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (ctype_digit($token) && isset($rooms[(int) $token])) {
                $ids[] = (int) $token;
                continue;
            }
            $found = false;
            foreach ($rooms as $room) {
                /* Le nom affiché, mais aussi celui saisi dans l'application :
                 * c'est celui-là dont l'utilisateur se souvient, et il n'est pas
                 * toujours celui que le robot met en avant. */
                $candidats = array($room['name']);
                if (!empty($room['custom_name'])) {
                    $candidats[] = $room['custom_name'];
                }
                foreach ($candidats as $candidat) {
                    if (mb_strtolower($candidat) === mb_strtolower($token)) {
                        $ids[] = $room['id'];
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                throw new Exception(__('Pièce inconnue :', __FILE__) . ' ' . $token);
            }
        }
        return $ids;
    }

    /*
     * « Cuisine, Salon » nettoie ces deux pièces une fois, au réglage du robot.
     * « Cuisine, Salon | 2 » y passe deux fois. « Cuisine | 2 | 3 » y passe deux
     * fois en Turbo.
     *
     * La barre verticale plutôt qu'une virgule : les noms de pièces en
     * contiennent déjà, et il faut bien séparer la liste de ses paramètres.
     */
    private static function cleanRooms($_eqLogic, $_argument) {
        $parts = explode('|', (string) $_argument);
        $rooms = self::roomList($_eqLogic, array_shift($parts));
        $repeats = isset($parts[0]) && trim($parts[0]) !== '' ? (int) trim($parts[0]) : 1;
        $suction = isset($parts[1]) && trim($parts[1]) !== '' ? (int) trim($parts[1]) : null;
        return $_eqLogic->commandCleanRooms($rooms, $repeats, $suction);
    }

    private static function cleanZone($_eqLogic, $_argument) {
        $parts = array_map('trim', explode(',', (string) $_argument));
        if (count($parts) < 4) {
            throw new Exception(__('Zone attendue sous la forme x1,y1,x2,y2 en millimètres.', __FILE__));
        }
        $repeats = isset($parts[4]) ? (int) $parts[4] : 1;
        return $_eqLogic->commandCleanZone($parts[0], $parts[1], $parts[2], $parts[3], $repeats);
    }
}
