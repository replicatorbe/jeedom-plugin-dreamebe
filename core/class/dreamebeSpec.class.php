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
 * La spécification MIoT des aspirateurs Dreame : où se trouve chaque donnée, et
 * ce que valent les nombres qu'elle contient.
 *
 * Tout ce fichier est du relevé, pas de l'invention. Les couples siid/piid, les
 * numéros d'action et les énumérations proviennent de Tasshack/dreame-vacuum
 * (branche dev, licence MIT), recoupés avec TA2k/ioBroker.dreame (MIT). Les
 * libellés français, eux, sont de nous.
 *
 * Deux avertissements qui évitent des heures de recherche :
 *
 * 1. Il n'existe AUCUNE table propre à un modèle. Le même dictionnaire sert à
 *    toute la gamme, et c'est le robot qui dit, à l'exécution, ce qu'il sait
 *    faire : une propriété non supportée répond un code non nul. Le plugin sonde
 *    donc une fois, retient la réponse, et ne crée que les commandes qui ont un
 *    sens pour CE robot. C'est la seule façon honnête de couvrir à la fois le
 *    L40 Ultra, ses variantes AE et CE, et les modèles à venir.
 *
 * 2. Deux informations que tout le monde cherche n'existent pas : le temps
 *    restant estimé, et la position du robot. Le premier n'est nulle part dans
 *    le protocole — seul un pourcentage d'avancement est publié. La seconde
 *    n'est lisible que dans le binaire de la carte, pas dans une propriété.
 */

class dreamebeSpec {

    /* ------------------------------------------------------------------ *
     * Propriétés — nom logique => array(siid, piid)
     *
     * Le nom logique sert aussi d'identifiant interne de commande Jeedom : il
     * est donc figé, et le renommer casserait les scénarios des utilisateurs.
     * ------------------------------------------------------------------ */

    public static $properties = array(
        /* --- Robot ---------------------------------------------------- */
        'state'              => array(2, 1),   /* DreameVacuumState */
        'error'              => array(2, 2),   /* code d'erreur */
        'battery'            => array(3, 1),   /* % */
        'charging'           => array(3, 2),   /* DreameVacuumChargingStatus */
        'status'             => array(4, 1),   /* DreameVacuumStatus */
        'cleaning_time'      => array(4, 2),   /* minutes */
        'cleaned_area'       => array(4, 3),   /* m² */
        'suction'            => array(4, 4),   /* 0..3 */
        'water_volume'       => array(4, 5),   /* 1..3, robots SANS base de lavage */
        'water_tank'         => array(4, 6),
        'task_status'        => array(4, 7),
        'task_start'         => array(4, 8),   /* horodatage du nettoyage en cours */
        'relocation'         => array(4, 20),
        'cleaning_mode'      => array(4, 23),  /* valeur empaquetée, voir splitMode() */
        'wash_base_status'   => array(4, 25),
        'customized_cleaning'=> array(4, 26),
        'self_clean'         => array(4, 34),
        'carpet_cleaning'    => array(4, 36),
        'auto_detergent'     => array(4, 37),
        'drying_time'        => array(4, 40),  /* heures */
        'low_water_warning'  => array(4, 41),
        'mop_wash_level'     => array(4, 46),
        'auto_switch'        => array(4, 50),  /* chaîne JSON de réglages fins */
        'auto_water_refill'  => array(4, 51),
        'mop_in_station'     => array(4, 52),
        'mop_pad_installed'  => array(4, 53),
        'task_type'          => array(4, 58),
        'drainage_status'    => array(4, 60),
        'cleaning_progress'  => array(4, 63),  /* % */
        'drying_progress'    => array(4, 64),  /* % */
        'wetness_onboard'    => array(4, 105), /* variante embarquée de wetness */

        /* --- Ne pas ranger, mais savoir où c'est --------------------- */
        'dnd'                => array(5, 1),
        'volume'             => array(7, 1),

        /* --- Station -------------------------------------------------- */
        'auto_empty'         => array(15, 1),  /* auto-vidage activé */
        'auto_empty_freq'    => array(15, 2),
        'dust_collection'    => array(15, 3),  /* disponibilité de l'auto-vidage */
        'auto_empty_status'  => array(15, 5),
        'clean_water_tank'   => array(27, 1),
        'dirty_water_tank'   => array(27, 2),
        'dust_bag'           => array(27, 3),
        'detergent_status'   => array(27, 4),
        'station_drainage'   => array(27, 5),
        'hot_water_status'   => array(27, 15),
        'wetness_level'      => array(28, 1),  /* 1..32, robots à base de lavage */
        'water_temperature'  => array(28, 8),

        /* --- Statistiques cumulées ------------------------------------ */
        'first_cleaning'     => array(12, 1),  /* horodatage */
        'total_time'         => array(12, 2),  /* minutes */
        'total_count'        => array(12, 3),
        'total_area'         => array(12, 4),  /* m² */

        /* --- Carte ---------------------------------------------------- */
        'map_data'           => array(6, 1),
        'object_name'        => array(6, 3),
        'map_list'           => array(6, 8),
    );

    /*
     * Consommables : service => array(nom logique, libellé, piid du pourcentage,
     * piid de la durée restante, unité de la durée).
     *
     * L'ordre des deux piid n'est PAS le même d'un service à l'autre, et l'unité
     * change entre heures et jours. Ce n'est pas une coquille : c'est ainsi dans
     * le protocole, et se fier à une règle générale donne des chiffres faux.
     */
    public static $consumables = array(
        9  => array('main_brush',  'Brosse principale',      2, 1, 'h'),
        10 => array('side_brush',  'Brosse latérale',        2, 1, 'h'),
        11 => array('filter',      'Filtre',                 1, 2, 'h'),
        16 => array('sensor',      'Capteurs',               1, 2, 'h'),
        17 => array('tank_filter', 'Filtre du réservoir',    1, 2, 'h'),
        18 => array('mop_pad',     'Serpillière',            1, 2, 'h'),
        19 => array('silver_ion',  'Module ions argent',  2, 1, 'j'),
        20 => array('detergent',   'Détergent',              1, 2, 'j'),
        /* Ceux-ci n'existent que sur une partie de la gamme — la raclette sur
         * les modèles à rouleau, le module désodorisant et les roues sur les
         * variantes AE. Ils sont listés parce que le sondage décidera : un
         * service absent ne répond pas, et aucune commande n'est alors créée. */
        24 => array('squeegee',    'Raclette',               1, 2, 'j'),
        29 => array('deodorizer',  'Module désodorisant',    2, 1, 'j'),
        30 => array('wheel',       'Roues',                  2, 1, 'j'),
        31 => array('scale_inhib', 'Anti-calcaire',          2, 1, 'j'),
    );

    /* ------------------------------------------------------------------ *
     * Actions — nom => array(siid, aiid)
     * ------------------------------------------------------------------ */

    public static $actions = array(
        'start'        => array(2, 1),
        'pause'        => array(2, 2),
        'dock'         => array(3, 1),   /* retour à la station */
        'start_custom' => array(4, 1),   /* pièces, zones, cartographie */
        'stop'         => array(4, 2),
        'clear_warning'=> array(4, 3),
        'start_washing'=> array(4, 4),   /* commandes de la station */
        'request_map'  => array(6, 1),
        'locate'       => array(7, 1),
        'auto_empty'   => array(15, 1),
    );

    /* Remise à zéro d'un consommable : service => aiid (toujours 1). */
    public static function resetAction($_siid) {
        return array((int) $_siid, 1);
    }

    /* ------------------------------------------------------------------ *
     * Énumérations
     * ------------------------------------------------------------------ */

    /*
     * DreameVacuumState (2/1), table « nouvelle » — celle des robots de la
     * génération L40. Les modèles plus anciens utilisent d'autres valeurs
     * au-delà de 18 ; le plugin ne les cible pas.
     */
    public static $states = array(
        1 => 'Aspiration', 2 => 'Au repos', 3 => 'En pause', 4 => 'Erreur',
        5 => 'Retour à la station', 6 => 'En charge', 7 => 'Lavage des sols',
        8 => 'Séchage', 9 => 'Lavage de la serpillière', 10 => 'Retour pour lavage',
        11 => 'Cartographie', 12 => 'Aspiration et lavage', 13 => 'Charge terminée',
        14 => 'Mise à jour', 15 => 'Nettoyage sur appel', 16 => 'Réinitialisation de la station',
        17 => 'Retour pour poser la serpillière', 18 => 'Retour pour retirer la serpillière',
        19 => 'Contrôle du niveau d\'eau', 20 => 'Remplissage du réservoir',
        21 => 'Lavage en pause', 22 => 'Vidage du bac', 23 => 'Télécommande',
        24 => 'Charge intelligente', 25 => 'Deuxième passage', 26 => 'Suivi de personne',
        27 => 'Nettoyage localisé', 28 => 'Retour pour vidage', 29 => 'En attente de tâche',
        30 => 'Nettoyage de la station', 31 => 'Retour pour vidange', 32 => 'Vidange',
        33 => 'Vidange automatique', 34 => 'Vidage du bac', 35 => 'Séchage du sac',
        36 => 'Séchage du sac en pause', 37 => 'Vers un nettoyage complémentaire',
        38 => 'Nettoyage complémentaire', 95 => 'Recherche d\'animal en pause',
        96 => 'Recherche d\'animal', 97 => 'Raccourci', 98 => 'Surveillance',
        99 => 'Surveillance en pause', 101 => 'Premier nettoyage en profondeur',
        102 => 'Premier nettoyage en profondeur en pause', 103 => 'Assainissement',
        104 => 'Assainissement et séchage', 105 => 'Changement de serpillière',
        106 => 'Changement de serpillière en pause', 107 => 'Entretien du sol',
        108 => 'Entretien du sol en pause', 109 => 'Ramassage à distance',
        113 => 'Rangement d\'objets', 114 => 'Surveillance d\'animal',
        115 => 'Surveillance d\'animal en pause', 116 => 'Pose de la serpillière',
        117 => 'Retrait de la serpillière', 118 => 'Recharge intelligente',
        120 => 'Nettoyage assisté', 121 => 'Entrée dans la station',
        122 => 'Sortie de la station',
        /* Machines à monte-escalier : hors cible, mais un libellé coûte moins
         * cher qu'un « Inconnu (142) » sur le tableau de bord de quelqu'un. */
        140 => 'Vers l\'escalier', 141 => 'Montée d\'escalier',
        142 => 'Descente d\'escalier', 143 => 'Escalier en pause',
        144 => 'Franchissement de marche', 145 => 'Retour par l\'escalier',
        146 => 'Entrée à la station par l\'escalier', 147 => 'Sortie de la station par l\'escalier',
    );

    /* DreameVacuumStatus (4/1) — sert aussi d'argument à start_custom. */
    public static $statuses = array(
        0 => 'Au repos', 1 => 'En pause', 2 => 'Nettoyage', 3 => 'Retour à la station',
        4 => 'Nettoyage partiel', 5 => 'Longe les murs', 6 => 'En charge', 7 => 'Mise à jour',
        8 => 'Test', 9 => 'Configuration Wi-Fi', 10 => 'Éteint', 11 => 'Usine',
        12 => 'Erreur', 13 => 'Télécommande', 14 => 'En veille', 15 => 'Auto-réparation',
        16 => 'Test d\'usine', 17 => 'Disponible', 18 => 'Nettoyage de pièces',
        19 => 'Nettoyage de zones', 20 => 'Nettoyage localisé', 21 => 'Cartographie rapide',
        22 => 'Ronde programmée', 23 => 'Ronde ponctuelle', 24 => 'Nettoyage sur appel',
        25 => 'Raccourci', 26 => 'Suivi de personne', 27 => 'Surveillance d\'animal',
        28 => 'Rangement automatique', 29 => 'Rangement intelligent',
        30 => 'Rangement par zone', 1501 => 'Contrôle du niveau d\'eau',
    );

    /*
     * Les deux piid que start_custom et start_washing utilisent comme boîte aux
     * lettres : le mode de départ, et la description de la cible. Nommés parce
     * qu'ils n'ont rien à voir avec les propriétés de même numéro qu'on lit par
     * ailleurs — ce sont des arguments d'action, pas un état du robot.
     */
    const PIID_ARG_STATUS = 1;
    const PIID_ARG_PROPERTIES = 10;

    /* Les valeurs de statut que start_custom accepte comme mode de départ. */
    const STATUS_SEGMENT_CLEANING = 18;
    const STATUS_ZONE_CLEANING = 19;
    const STATUS_SPOT_CLEANING = 20;
    const STATUS_FAST_MAPPING = 21;

    /*
     * Ce qui vaut « le robot travaille ».
     *
     * Le protocole n'expose pas ce booléen, et il faut croiser deux
     * énumérations pour l'obtenir : le statut dit ce que fait le robot, l'état
     * dit aussi ce que fait la station. Un robot à l'arrêt pendant que sa base
     * lave la serpillière est bel et bien occupé, et c'est précisément le moment
     * où l'on veut voir l'avancement bouger.
     *
     * Les deux listes sont énumérées plutôt que déduites par complément : une
     * valeur inconnue d'un micrologiciel futur doit compter comme « au repos »,
     * faute de quoi une seule valeur non répertoriée suffirait à interroger le
     * cloud toutes les minutes indéfiniment.
     */
    public static $activeStatuses = array(
        2, 3, 4, 5, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
    );

    public static $activeStates = array(
        1, 5, 7, 8, 9, 10, 11, 12, 15, 17, 18, 20, 22, 25, 27, 28,
        30, 31, 32, 33, 34, 35, 37, 38,
        101, 103, 104, 105, 107, 116, 117, 120, 121, 122,
    );

    public static function isActive($_status, $_state) {
        if ($_status !== null && in_array((int) $_status, self::$activeStatuses, true)) {
            return true;
        }
        return ($_state !== null && in_array((int) $_state, self::$activeStates, true));
    }

    public static $chargingStatus = array(
        1 => 'En charge', 2 => 'Sur batterie', 3 => 'Charge terminée',
        5 => 'Retour à la station',
    );

    public static $taskStatus = array(
        0 => 'Terminé', 1 => 'Nettoyage complet', 2 => 'Nettoyage de zones',
        3 => 'Nettoyage de pièces', 4 => 'Nettoyage localisé', 5 => 'Cartographie',
        6 => 'Nettoyage complet en pause', 7 => 'Nettoyage de zones en pause',
        8 => 'Nettoyage de pièces en pause', 9 => 'Nettoyage localisé en pause',
        10 => 'Nettoyage de carte en pause', 11 => 'Retour en pause',
        12 => 'Lavage en pause', 13 => 'Lavage de pièces en pause',
        14 => 'Lavage de zones en pause', 15 => 'Lavage complet en pause',
        16 => 'Retour automatique en pause', 17 => 'Retour après pièces en pause',
        18 => 'Retour après zones en pause', 20 => 'Ronde programmée',
        21 => 'Ronde programmée en pause', 22 => 'Ronde ponctuelle',
        23 => 'Ronde ponctuelle en pause', 24 => 'Nettoyage sur appel en pause',
        25 => 'Retour pour poser la serpillière', 26 => 'Retour pour retirer la serpillière',
        27 => 'Nettoyage de la station', 30 => 'Recherche d\'animal',
        31 => 'Nettoyage interrompu par un lavage', 32 => 'Zone interrompue par un lavage',
        33 => 'Personnalisé interrompu par un lavage',
        34 => 'Ramassage d\'objet', 35 => 'Ramassage d\'objet en pause',
        36 => 'Objet ramassé', 37 => 'Ramassage à distance : initialisation',
        38 => 'Ramassage à distance : identification', 39 => 'Ramassage manuel à distance',
        40 => 'Ramassage automatique à distance', 41 => 'Ramassage à distance en cours',
        42 => 'Ramassage à distance en pause', 43 => 'Dépose d\'objet',
        44 => 'Dépose d\'objet en pause',
    );

    public static $suctionLevels = array(
        0 => 'Silencieux', 1 => 'Standard', 2 => 'Fort', 3 => 'Turbo',
    );

    /* Robots SANS base de lavage : niveau d'eau simple (4/5). */
    public static $waterVolumes = array(
        1 => 'Faible', 2 => 'Moyen', 3 => 'Élevé',
    );

    /* Robots AVEC base de lavage : humidité de la serpillière, troisième octet
     * de la valeur empaquetée 4/23. */
    public static $mopHumidity = array(
        1 => 'Peu humide', 2 => 'Humide', 3 => 'Très humide',
    );

    public static $cleaningModes = array(
        0 => 'Aspiration seule', 1 => 'Lavage seul',
        2 => 'Aspiration et lavage', 3 => 'Lavage après aspiration',
    );

    public static $waterTank = array(
        0 => 'Absent', 1 => 'Installé', 10 => 'Serpillière installée',
        99 => 'Serpillière dans la station',
    );

    public static $washBaseStatus = array(
        0 => 'Au repos', 1 => 'Lavage', 2 => 'Séchage', 3 => 'Retour',
        4 => 'En pause', 5 => 'Attente de remplissage', 6 => 'Remplissage',
        7 => 'Retour pour sécher la serpillière',
    );

    public static $dustCollection = array(
        0 => 'Indisponible', 1 => 'Disponible', 2 => 'Usage prolongé', 3 => 'Jamais',
    );

    public static $autoEmptyStatus = array(
        0 => 'Au repos', 1 => 'En cours', 2 => 'Non effectué',
    );

    public static $lowWaterWarning = array(
        0 => 'Aucune alerte', 1 => 'Plus d\'eau (alerte acquittée)', 2 => 'Plus d\'eau',
        3 => 'Plus d\'eau après le nettoyage', 4 => 'Pas d\'eau pour le lavage',
        5 => 'Niveau d\'eau bas', 6 => 'Réservoir absent',
    );

    public static $cleanWaterTank = array(
        0 => 'Installé', 1 => 'Absent', 2 => 'Niveau bas', 3 => 'Contrôle en cours',
    );

    public static $dirtyWaterTank = array(
        0 => 'Installé', 1 => 'Absent ou plein',
    );

    public static $dustBag = array(
        0 => 'Installé', 1 => 'Absent', 2 => 'À contrôler',
    );

    public static $detergentStatus = array(
        0 => 'Installé', 1 => 'Désactivé', 2 => 'Niveau bas',
    );

    public static $relocation = array(
        0 => 'Localisé', 1 => 'Localisation en cours', 10 => 'Échec', 11 => 'Réussie',
    );

    public static $mopWashLevel = array(
        0 => 'Économie d\'eau', 1 => 'Quotidien', 2 => 'Profond',
    );

    /*
     * Ce que le robot fait des tapis. Les huit valeurs sont celles de
     * l'implémentation de référence ; toutes n'existent pas sur tous les
     * modèles, et une valeur absente de la table s'affiche telle quelle plutôt
     * que d'être devinée.
     */
    public static $carpetCleaning = array(
        0 => 'Non défini', 1 => 'Évitement', 2 => 'Adaptation',
        3 => 'Retrait de la serpillière', 4 => 'Adaptation sans détour',
        5 => 'Aspiration et lavage', 6 => 'Ignorer', 7 => 'Traverser',
    );

    public static $autoDetergent = array(
        0 => 'Désactivé', 1 => 'Activé', 2 => 'Absent',
    );

    public static $hotWaterStatus = array(
        0 => 'Désactivée', 1 => 'Activée',
    );

    /*
     * La NATURE de la tâche en cours, à ne pas confondre avec son avancement :
     * « nettoyage programmé », « nettoyage de bord renforcé »… C'est ce qui
     * permet à un scénario de distinguer un nettoyage lancé à la main d'un
     * nettoyage déclenché par le robot lui-même.
     */
    public static $taskTypes = array(
        0 => 'Aucune', 1 => 'Nettoyage standard', 2 => 'Nettoyage standard en pause',
        3 => 'Nettoyage personnalisé', 4 => 'Nettoyage personnalisé en pause',
        5 => 'Raccourci', 6 => 'Raccourci en pause',
        7 => 'Nettoyage programmé', 8 => 'Nettoyage programmé en pause',
        9 => 'Nettoyage intelligent', 10 => 'Nettoyage intelligent en pause',
        11 => 'Nettoyage partiel', 12 => 'Nettoyage partiel en pause',
        13 => 'Nettoyage sur appel', 14 => 'Nettoyage sur appel en pause',
        15 => 'Traitement d\'une tache', 16 => 'Traitement d\'une tache en pause',
        17 => 'Nettoyage de bord renforcé', 18 => 'Compactage des cheveux',
        19 => 'Nettoyage des grosses particules', 20 => 'Traitement intensif d\'une tache',
        21 => 'Traitement des taches', 22 => 'Premier nettoyage en profondeur',
        23 => 'Premier nettoyage en profondeur en pause', 24 => 'Chauffage de la serpillière',
        25 => 'Nettoyage après cartographie', 26 => 'Nettoyage des fines particules',
        30 => 'Changement de serpillière', 31 => 'Changement de serpillière en pause',
        32 => 'Entretien du sol', 33 => 'Entretien du sol en pause',
        34 => 'Rangement d\'objets', 35 => 'Rangement d\'objets en pause',
        36 => 'Nettoyage intensif des cheveux', 37 => 'Manipulation d\'accessoire',
        38 => 'Nettoyage à vitesse accrue', 39 => 'Nettoyage sous pression',
        40 => 'Nettoyage vapeur', 41 => 'Nettoyage vapeur en pause',
    );

    public static $waterTemperature = array(
        0 => 'Normale', 1 => 'Tiède', 2 => 'Chaude', 3 => 'Très chaude', 4 => 'Maximale',
    );

    /*
     * Codes d'erreur (2/2).
     *
     * Au-delà de 100, ce sont des avertissements de la station plutôt que des
     * pannes du robot : ils s'effacent par l'action « clear_warning » et ne
     * doivent pas être présentés comme une panne.
     */
    /*
     * Les codes qui décrivent un ennui de station plutôt qu'une panne du robot.
     *
     * C'est une LISTE, pas un seuil. L'intuition « au-delà de 100, c'est une
     * alerte » est fausse dans les deux sens : « Retirez la serpillière » (68)
     * est un avertissement parfaitement banal, tandis que « Erreur de la
     * station » (128) ou « Échec du retour à la station » (1000) sont de vraies
     * pannes. La liste est celle de l'implémentation de référence, qui la tient
     * elle-même de l'application.
     */
    public static $warningCodes = array(
        9, 10, 20, 47, 51, 56, 68, 70, 71, 72, 75, 82, 85,
        107, 114, 117, 121, 122, 123, 129, 213, 214,
    );

    /*
     * Ceux que l'action d'acquittement sait réellement effacer. En envoyer un
     * autre ne produit rien : le robot ignore la demande.
     */
    public static $clearableCodes = array(
        20, 68, 70, 75, 82, 84, 114, 117, 121, 123, 213, 214,
    );

    public static $errors = array(
        0 => 'Aucune erreur',
        1 => 'Capteur de vide', 2 => 'Détection de vide', 3 => 'Pare-chocs',
        4 => 'Geste non reconnu', 5 => 'Pare-chocs bloqué', 6 => 'Capteur de vide bloqué',
        7 => 'Capteur de déplacement', 8 => 'Bac à poussière absent',
        9 => 'Réservoir absent', 10 => 'Réservoir d\'eau vide', 11 => 'Bac à poussière plein',
        12 => 'Brosse principale bloquée', 13 => 'Brosse latérale bloquée',
        14 => 'Turbine bloquée', 15 => 'Roue gauche', 16 => 'Roue droite',
        17 => 'Blocage en rotation', 18 => 'Blocage en avançant', 19 => 'Contact de charge',
        20 => 'Batterie faible', 21 => 'Défaut de charge', 22 => 'Niveau de batterie',
        23 => 'Communication interne', 24 => 'Caméra obstruée', 25 => 'Déplacement impossible',
        26 => 'Capteur optique obstrué', 27 => 'Capteur infrarouge obstrué',
        28 => 'Pas d\'alimentation sur la base', 29 => 'Défaut de batterie',
        30 => 'Vitesse de turbine', 31 => 'Vitesse de la roue gauche',
        32 => 'Vitesse de la roue droite', 33 => 'Accéléromètre', 34 => 'Gyroscope',
        35 => 'Capteur XV7001', 36 => 'Aimant gauche', 37 => 'Aimant droit',
        38 => 'Capteur de déplacement', 39 => 'Infrarouge', 40 => 'Caméra',
        41 => 'Champ magnétique important', 42 => 'Pompe à eau', 43 => 'Horloge interne',
        44 => 'Touche bloquée', 45 => 'Alimentation 3,3 V', 46 => 'Caméra inactive',
        47 => 'Robot bloqué', 48 => 'Télémètre laser', 49 => 'Pare-chocs du télémètre',
        50 => 'Pompe à eau', 51 => 'Filtre encrassé', 54 => 'Bord non atteignable',
        55 => 'Tapis', 56 => 'Laser', 57 => 'Bord non atteignable', 58 => 'Ultrasons',
        59 => 'Zone interdite', 61 => 'Trajet impossible', 62 => 'Trajet impossible',
        63 => 'Robot bloqué', 64 => 'Robot bloqué', 65 => 'Zone restreinte',
        66 => 'Zone restreinte', 67 => 'Zone restreinte', 68 => 'Retirez la serpillière',
        69 => 'Serpillière retirée', 70 => 'Serpillière retirée',
        71 => 'Serpillière ne tourne plus', 72 => 'Serpillière ne tourne plus',
        74 => 'Pose de la serpillière impossible', 75 => 'Arrêt sur batterie faible',
        76 => 'Réservoir d\'eau sale absent', 78 => 'Robot dans une pièce inconnue',
        79 => 'Télémètre laser bloqué', 80 => 'Robot coincé', 81 => 'Robot coincé',
        82 => 'Sol glissant', 84 => 'Erreur inconnue', 85 => 'Contrôlez la serpillière',
        86 => 'Réservoir d\'eau sale plein', 88 => 'Patin escamotable bloqué',
        89 => 'Erreur interne', 90 => 'Robot coincé', 91 => 'Robot coincé sous un meuble',
        92 => 'Robot coincé dans un passage', 93 => 'Robot coincé sur un seuil',
        94 => 'Robot coincé dans un creux', 95 => 'Robot coincé sur une pente',
        96 => 'Robot coincé sur un obstacle', 97 => 'Robot bloqué par un animal',
        98 => 'Robot coincé sur une surface glissante', 99 => 'Robot coincé sur un tapis',
        101 => 'Bac à poussière plein', 102 => 'Bac à poussière ouvert',
        103 => 'Bac à poussière ouvert', 104 => 'Bac à poussière plein',
        105 => 'Réservoir d\'eau', 106 => 'Réservoir d\'eau sale',
        107 => 'Réservoir d\'eau vide', 108 => 'Réservoir d\'eau sale',
        109 => 'Réservoir d\'eau sale obstrué', 110 => 'Pompe du réservoir d\'eau sale',
        111 => 'Serpillière', 112 => 'Serpillière humide', 114 => 'Nettoyez la serpillière',
        116 => 'Niveau du réservoir d\'eau propre', 117 => 'Station déconnectée',
        118 => 'Niveau du réservoir d\'eau sale', 119 => 'Niveau de la planche de lavage',
        120 => 'Pas de serpillière dans la station', 121 => 'Sac à poussière plein',
        122 => 'Avertissement inconnu', 123 => 'Échec de l\'autotest',
        124 => 'Planche de lavage inopérante', 125 => 'Échec de la vidange',
        126 => 'Serpillière non détectée', 127 => 'Support de serpillière',
        128 => 'Erreur de la station', 129 => 'Échec du lavage',
        200 => 'Robot coincé dans un rideau', 201 => 'Serpillière de bord bloquée',
        202 => 'Serpillière de bord détachée', 203 => 'Défaut de levage du châssis',
        207 => 'Erreur interne', 209 => 'Capot de serpillière',
        210 => 'Rouleau de lavage', 212 => 'Bras robotisé arrêté',
        213 => 'Réservoir embarqué vide', 214 => 'Réservoir d\'eau sale embarqué plein',
        215 => 'Serpillière non installée', 217 => 'Télémètre laser',
        218 => 'Rouleau de lavage', 222 => 'Rouleau démêlant',
        223 => 'Capot de serpillière', 224 => 'Capot de serpillière',
        225 => 'Rouleau de lavage', 226 => 'Passage bloqué par un obstacle',
        227 => 'Filtre de sortie de vidange', 228 => 'Roues motrices',
        229 => 'Erreur interne', 230 => 'Erreur interne',
        1000 => 'Échec du retour à la station',
    );

    /* ------------------------------------------------------------------ *
     * Aides de décodage
     * ------------------------------------------------------------------ */

    public static function label($_table, $_value, $_default = 'Inconnu') {
        if ($_value === null || $_value === '') {
            return $_default;
        }
        $value = (int) $_value;
        return isset($_table[$value]) ? $_table[$value] : ($_default . ' (' . $value . ')');
    }

    public static function errorLabel($_code) {
        $code = (int) $_code;
        if (isset(self::$errors[$code])) {
            return self::$errors[$code];
        }
        return 'Erreur non répertoriée (' . $code . ')';
    }

    public static function isWarning($_code) {
        return in_array((int) $_code, self::$warningCodes, true);
    }

    public static function isClearable($_code) {
        return in_array((int) $_code, self::$clearableCodes, true);
    }

    /*
     * Ramène à « aucune erreur » ce qui n'en est pas une.
     *
     * Quatre cas, tous repris de l'implémentation de référence, et le dernier
     * est celui qui compte ici : sur un robot à base de lavage, « Retirez la
     * serpillière » est l'état normal d'un cycle de lavage. Le présenter comme
     * une erreur ferait sonner les scénarios d'alerte plusieurs fois par jour,
     * pour un robot qui fonctionne parfaitement.
     */
    public static function normalizeError($_code, $_charging = false, $_hasWashBase = false) {
        $code = (int) $_code;
        if ($code === 84 || $code === 122) {
            return 0;   /* « erreur inconnue » et « avertissement inconnu » */
        }
        if ($code === 20 && $_charging) {
            return 0;   /* batterie faible alors qu'il se recharge */
        }
        if ($code === 68 && $_hasWashBase) {
            return 0;   /* retrait de serpillière : le déroulement normal */
        }
        return $code;
    }

    /*
     * La propriété 4/23 n'est pas un mode : c'est trois réglages empaquetés dans
     * un entier.
     *
     *   octet bas     : mode de nettoyage
     *   octet médian  : valeur d'auto-lavage (surface, durée ou fréquence)
     *   octet haut    : humidité de la serpillière
     *
     * Et sur les robots à serpillière escamotable — tous les Ultra — les codes de
     * mode sont permutés : 2 vaut « aspiration seule » et 0 vaut « aspiration et
     * lavage ». Lire la valeur brute comme un mode donne donc exactement
     * l'inverse de la réalité, sans que rien ne le signale.
     */
    public static function splitMode($_value, $_mopPadLifting = true) {
        $value = (int) $_value;
        $mode = $_mopPadLifting ? ($value & 0x03) : ($value & 1);
        $selfClean = ($value >> 8) & ~0x300;
        $humidity = $value >> 16;

        if ($_mopPadLifting) {
            if ($mode === 2) {
                $mode = 0;       /* aspiration seule */
            } elseif ($mode === 0) {
                $mode = 2;       /* aspiration et lavage */
            }
        }
        return array('mode' => $mode, 'self_clean' => $selfClean, 'humidity' => $humidity);
    }

    public static function combineMode($_mode, $_selfClean, $_humidity, $_mopPadLifting = true) {
        $mode = (int) $_mode;
        if ($_mopPadLifting) {
            if ($mode === 2) {
                $mode = 0;
            } elseif ($mode === 0) {
                $mode = 2;
            }
        }
        return ((((0 ^ ((int) $_humidity)) << 8) ^ ((int) $_selfClean)) << 8) ^ $mode;
    }

    /*
     * Humidité de serpillière sur les robots de dernière génération : une échelle
     * de 1 à 32, pas trois crans. Les trois valeurs ci-dessous sont celles que
     * l'application retient pour ses propres boutons « peu humide / humide /
     * très humide » ; les reprendre évite d'afficher dans Jeedom un réglage que
     * l'application ne saurait pas représenter.
     */
    public static $wetnessLevels = array(1 => 5, 2 => 16, 3 => 27);

    public static function wetnessToHumidity($_wetness) {
        $wetness = (int) $_wetness;
        if ($wetness <= 0) {
            return null;
        }
        /* Certaines machines n'utilisent pas l'échelle de 1 à 32 mais des
         * paliers autour de 100, 200 et 400. Les traiter avec les seuils de
         * l'autre échelle donnerait exactement l'inverse de la réalité. */
        if ($wetness > 32) {
            return ($wetness > 200) ? 3 : 1;
        }
        if ($wetness < 6) {
            return 1;
        }
        return ($wetness > 26) ? 3 : 2;
    }

    /* ------------------------------------------------------------------ *
     * Modèles
     * ------------------------------------------------------------------ */

    /*
     * Ce que le plugin sait nommer. La liste ne sert QU'À l'affichage : le
     * pilotage, lui, repose sur le sondage des propriétés, et un modèle absent
     * d'ici fonctionne donc tout autant. C'est voulu — une liste blanche de
     * modèles serait à refaire à chaque sortie de produit.
     */
    public static $models = array(
        'r2492a' => 'L40 Ultra', 'r2492b' => 'L40 Ultra', 'r2492j' => 'L40 Ultra',
        'r5057a' => 'L40 Ultra A',
        'r2579a' => 'L40 Ultra AE', 'r2579h' => 'L40 Ultra AE',
        'r500zh' => 'L40 Ultra AE', 'r500za' => 'L40 Ultra AE',
        'r2562a' => 'L40 Ultra CE', 'r2562b' => 'L40 Ultra CE',
        'r5021h' => 'L40 Ultra CE', 'r5021b' => 'L40 Ultra CE', 'r5021a' => 'L40 Ultra CE',
        'r501t'  => 'L40 Ultra Gen 2',
        'r2551a' => 'L40s Ultra', 'r2551h' => 'L40s Ultra',
        'r9419a' => 'L40s Pro Ultra', 'r9419e' => 'L40s Pro Ultra',
        'r9419h' => 'L40s Pro Ultra', 'r9419t' => 'L40s Pro Ultra',
        'r9542a' => 'L40', 'r95425' => 'L40',
        'r5345b' => 'L40 Plus', 'r53456' => 'L40 Plus',
        'r2416'  => 'X40 Ultra', 'r2449k' => 'X40 Ultra Complete',
        'r2253c' => 'L20 Ultra', 'r2253w' => 'L20 Ultra',
    );

    public static function modelName($_model) {
        $model = (string) $_model;
        $position = strrpos($model, '.');
        $suffix = ($position === false) ? $model : substr($model, $position + 1);
        return isset(self::$models[$suffix]) ? self::$models[$suffix] : $model;
    }
}
