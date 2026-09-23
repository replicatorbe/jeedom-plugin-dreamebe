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
 * Décodage des cartes Dreame.
 *
 * Pourquoi ce fichier existe : il n'y a AUCUN moyen d'obtenir la liste des
 * pièces autrement. Ni propriété MIoT, ni endpoint dédié — les noms, les
 * identifiants et les réglages par pièce ne vivent que dans le JSON collé à la
 * fin du binaire de la carte. Un plugin qui veut proposer « Nettoyer la
 * cuisine » doit donc décoder la carte, même s'il n'affiche aucune image.
 *
 * La chaîne de décodage, relevée sur Tasshack/dreame-vacuum (branche dev, MIT) :
 *
 *   texte base64 « URL-safe »  →  découpage sur la virgule (clé éventuelle)
 *      →  base64_decode  →  AES-256-CBC si une clé accompagnait les données
 *         →  zlib (format RFC 1950, pas gzip)
 *            →  en-tête de 27 octets  +  largeur × hauteur pixels  +  JSON
 *
 * Trois détails font échouer toute réimplémentation naïve, et sont traités ici :
 * les entiers de l'en-tête sont SIGNÉS (les coordonnées sont couramment
 * négatives), la clé AES n'est pas la clé reçue mais les 32 premiers caractères
 * de son empreinte SHA-256, et le vecteur d'initialisation est une constante
 * propre à la famille de modèles.
 */

class dreamebeMapException extends Exception {}

class dreamebeMap {

    /* Taille maximale d'une carte une fois décompressée. */
    const MAX_INFLATED = 16777216;

    /* Taille de l'en-tête binaire, avant les pixels. */
    const HEADER_SIZE = 27;

    /*
     * Vecteur d'initialisation AES, par famille de modèles.
     *
     * Ce n'est pas un secret à protéger, c'est une constante du micrologiciel :
     * sans elle, une carte chiffrée est indéchiffrable. La famille L40 (r2492 et
     * r2579, donc le L40 Ultra et sa variante AE) partage la même.
     *
     * Un modèle absent de cette table n'est pas bloqué : s'il n'envoie pas de
     * clé avec ses données, sa carte n'est pas chiffrée et se lit sans IV. C'est
     * seulement s'il en envoie une que le décodage échouera, avec un message qui
     * dit quoi rapporter.
     */
    public static $ivByPrefix = array(
        'r2492' => 'NRwnBj5FsNPgBNbT',
        'r2579' => 'NRwnBj5FsNPgBNbT',
        'r500z' => 'NRwnBj5FsNPgBNbT',
        'r5057' => 'NRwnBj5FsNPgBNbT',
        'r2551' => 'NRwnBj5FsNPgBNbT',
    );

    public static function ivForModel($_model) {
        $model = (string) $_model;
        $position = strrpos($model, '.');
        $suffix = ($position === false) ? $model : substr($model, $position + 1);
        foreach (self::$ivByPrefix as $prefix => $iv) {
            if (strpos($suffix, $prefix) === 0) {
                return $iv;
            }
        }
        return null;
    }

    /*
     * Le nom d'objet, quelle que soit la forme sous laquelle il arrive.
     *
     * Trois formes existent selon le micrologiciel : une chaîne nue, une liste
     * d'une chaîne, ou une CHAÎNE contenant une liste JSON. Parier sur l'une
     * d'elles donne une carte qui ne s'affiche jamais sur une partie du parc.
     */
    public static function objectName($_value) {
        $value = $_value;
        if (is_array($value)) {
            $value = reset($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (substr($value, 0, 1) === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && isset($decoded[0]) && is_string($decoded[0])) {
                return $decoded[0];
            }
        }
        return $value;
    }

    /* ------------------------------------------------------------------ *
     * Lecture binaire
     * ------------------------------------------------------------------ */

    /*
     * Un entier 16 bits petit-boutiste SIGNÉ.
     *
     * unpack('v') rend du non signé : lu ainsi, une coordonnée de -300 mm
     * devient 65236, la carte part à l'autre bout du plan et plus rien ne
     * correspond. La correction tient en une ligne, son absence coûte une
     * après-midi.
     */
    public static function int16($_data, $_offset) {
        if (!isset($_data[$_offset + 1])) {
            return 0;
        }
        $parts = unpack('v', substr($_data, $_offset, 2));
        $value = $parts[1];
        return ($value >= 32768) ? ($value - 65536) : $value;
    }

    /* ------------------------------------------------------------------ *
     * Déchiffrement
     * ------------------------------------------------------------------ */

    /*
     * Ramène une charge utile de carte à son binaire décompressé.
     *
     * $_raw peut porter sa propre clé, après une virgule. C'est la convention du
     * protocole : « données,clé ». Si aucune clé n'accompagne les données, elles
     * ne sont pas chiffrées, et l'IV ne sert pas.
     */
    public static function inflate($_raw, $_iv = null, $_key = null) {
        $raw = trim((string) $_raw);
        if ($raw === '') {
            throw new dreamebeMapException('Charge utile de carte vide.');
        }

        /*
         * La substitution « URL-safe » s'applique à TOUTE la chaîne, AVANT le
         * découpage — et l'ordre n'est pas indifférent : la clé se trouve après
         * la virgule, et c'est la clé DÉJÀ substituée dont on dérive le secret
         * AES. Découper d'abord donnerait une clé différente dès qu'elle
         * contient un tiret ou un souligné, donc un déchiffrement qui échoue
         * sans que rien n'explique pourquoi.
         */
        $raw = strtr($raw, '_-', '/+');

        $key = $_key;
        if (strpos($raw, ',') !== false) {
            $parts = explode(',', $raw, 2);
            $raw = $parts[0];
            $key = $parts[1];
        }
        $binary = base64_decode($raw, false);
        if ($binary === false || $binary === '') {
            throw new dreamebeMapException('Carte : base64 illisible.');
        }

        if ($key !== null && $key !== '') {
            if ($_iv === null || $_iv === '') {
                throw new dreamebeMapException(
                    'Carte chiffrée et vecteur d\'initialisation inconnu pour ce modèle. '
                    . 'Signalez le modèle du robot pour que la table soit complétée.');
            }
            /* La clé n'est pas la chaîne reçue : ce sont les 32 premiers
             * caractères de son empreinte SHA-256 en hexadécimal, pris comme
             * texte — donc 32 octets, d'où l'AES-256. */
            $aesKey = substr(hash('sha256', $key), 0, 32);
            $decrypted = openssl_decrypt($binary, 'aes-256-cbc', $aesKey,
                                         OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $_iv);
            if ($decrypted === false) {
                throw new dreamebeMapException('Carte : déchiffrement impossible.');
            }
            $binary = $decrypted;
        }

        /* Format zlib (en-tête 78 9C), pas gzip et pas deflate brut. Le
         * remplissage AES qui traîne en fin de tampon ne gêne pas : la
         * décompression s'arrête d'elle-même à la fin du flux.
         *
         * Plafonnée : un dépassement de mémoire est une erreur fatale que nul
         * catch ne rattrape, et elle emporterait le cycle de tous les robots.
         * Une vraie carte décompressée pèse quelques centaines de kilo-octets. */
        $inflated = @gzuncompress($binary, self::MAX_INFLATED);
        if ($inflated === false) {
            throw new dreamebeMapException('Carte : décompression impossible.');
        }
        return $inflated;
    }

    /* ------------------------------------------------------------------ *
     * Décodage d'une carte
     * ------------------------------------------------------------------ */

    /*
     * Rend l'en-tête, les pixels et les métadonnées d'une carte.
     *
     * Les positions sont en millimètres dans le repère de la carte, l'angle en
     * degrés. La sentinelle 32767 sur l'angle, ou les drapeaux « nr » et « nc »
     * du JSON, disent que la position n'est pas connue — c'est le cas d'une
     * carte sauvegardée, qui ne contient pas de robot.
     */
    public static function decode($_raw, $_iv = null, $_key = null) {
        $binary = self::inflate($_raw, $_iv, $_key);
        if (strlen($binary) < self::HEADER_SIZE) {
            throw new dreamebeMapException('Carte : en-tête tronqué.');
        }

        $map = array(
            'map_id' => self::int16($binary, 0),
            'frame_id' => self::int16($binary, 2),
            'frame_type' => ord($binary[4]),
            'grid_size' => self::int16($binary, 17),
            'width' => self::int16($binary, 19),
            'height' => self::int16($binary, 21),
            'left' => self::int16($binary, 23),
            'top' => self::int16($binary, 25),
        );

        $robotAngle = self::int16($binary, 9);
        $chargerAngle = self::int16($binary, 15);
        $map['robot'] = array('x' => self::int16($binary, 5), 'y' => self::int16($binary, 7), 'a' => $robotAngle);
        $map['charger'] = array('x' => self::int16($binary, 11), 'y' => self::int16($binary, 13), 'a' => $chargerAngle);

        if ($map['width'] <= 0 || $map['height'] <= 0 || $map['grid_size'] <= 0) {
            throw new dreamebeMapException('Carte : dimensions invalides.');
        }

        $pixelCount = $map['width'] * $map['height'];
        $map['pixels'] = substr($binary, self::HEADER_SIZE, $pixelCount);
        /* Sans ce contrôle, un en-tête qui annonce plus de pixels qu'il n'en
         * arrive produit une image partiellement fausse ET inonde le journal
         * d'Apache d'avertissements d'indice hors bornes — le journal même qui
         * sert de dernier recours au diagnostic. */
        if (strlen($map['pixels']) !== $pixelCount) {
            throw new dreamebeMapException('Carte : ' . strlen($map['pixels']) . ' pixels reçus pour '
                                           . $pixelCount . ' annoncés.');
        }

        /* Ce qui suit les pixels est du JSON : noms des pièces, réglages,
         * zones interdites. C'est la partie utile pour Jeedom. */
        $tail = substr($binary, self::HEADER_SIZE + $pixelCount);
        $json = array();
        if ($tail !== false && trim($tail) !== '') {
            $decoded = json_decode($tail, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
        $map['json'] = $json;

        /* L'origine du JSON prime sur celle de l'en-tête quand elle est là. */
        if (isset($json['origin']) && is_array($json['origin']) && count($json['origin']) > 1) {
            $map['left'] = (int) $json['origin'][0];
            $map['top'] = (int) $json['origin'][1];
        }

        if ($robotAngle == 32767 || !empty($json['nr'])) {
            $map['robot'] = null;
        }
        if ($chargerAngle == 32767 || !empty($json['nc'])) {
            $map['charger'] = null;
        }
        /*
         * Robot à la station : il ne publie pas sa position, mais on la connaît.
         * Son ORIENTATION, en revanche, reste inconnue — recopier celle de la
         * station ferait pointer l'aiguille du plan dans une direction
         * arbitraire, et publierait un angle qui n'a jamais été mesuré.
         */
        if ($map['robot'] === null && $map['charger'] !== null && !empty($json['oc'])) {
            $map['robot'] = array('x' => $map['charger']['x'], 'y' => $map['charger']['y'], 'a' => null);
        }

        return $map;
    }

    /* ------------------------------------------------------------------ *
     * Pièces
     * ------------------------------------------------------------------ */

    /*
     * Les pièces d'une carte : identifiant, nom, géométrie, réglages.
     *
     * Le nom se lit à deux endroits qui s'excluent : un code de type prédéfini
     * (« Cuisine », « Salon »…) tant que l'utilisateur n'a rien saisi, et une
     * chaîne encodée en base64 dès qu'il a renommé la pièce dans l'application.
     * Prendre l'un sans l'autre donne soit des « Pièce 3 » partout, soit des
     * noms absents sur les cartes jamais personnalisées.
     */
    public static function segments($_map) {
        $width = $_map['width'];
        $height = $_map['height'];
        $grid = $_map['grid_size'];
        $pixels = $_map['pixels'];
        $json = isset($_map['json']) ? $_map['json'] : array();

        /* Premier passage : étendue de chaque pièce, en pixels. */
        $bounds = array();
        $rows = array();
        $length = strlen($pixels);
        for ($index = 0; $index < $length; $index++) {
            $byte = ord($pixels[$index]);
            if ($byte === 0) {
                continue;
            }
            /*
             * Bit 7 : mur OU bordure de pièce. Bit 6 : tapis. Les six bits bas
             * portent l'identifiant de la pièce — d'où un maximum de 63.
             *
             * Le bit 7 seul, sans identifiant, est un mur. Accompagné d'un
             * identifiant, c'est la BORDURE de cette pièce-là, et elle en fait
             * partie : l'écarter rétrécit chaque pièce d'un anneau, sous-estime
             * sa surface du montant de son périmètre, et fait carrément
             * disparaître un couloir étroit entièrement constitué de bordures.
             */
            $segment = $byte & 0x3F;
            if ($segment === 0) {
                continue;
            }
            $x = $index % $width;
            $y = intdiv($index, $width);
            if (!isset($bounds[$segment])) {
                $bounds[$segment] = array('x1' => $x, 'y1' => $y, 'x2' => $x, 'y2' => $y, 'count' => 0);
                $rows[$segment] = array();
            }
            $bounds[$segment]['x1'] = min($bounds[$segment]['x1'], $x);
            $bounds[$segment]['y1'] = min($bounds[$segment]['y1'], $y);
            $bounds[$segment]['x2'] = max($bounds[$segment]['x2'], $x);
            $bounds[$segment]['y2'] = max($bounds[$segment]['y2'], $y);
            $bounds[$segment]['count']++;
            if (!isset($rows[$segment][$y])) {
                $rows[$segment][$y] = array();
            }
            $rows[$segment][$y][] = $x;
        }

        $info = (isset($json['seg_inf']) && is_array($json['seg_inf'])) ? $json['seg_inf'] : array();
        $cleanset = self::cleanset($json);
        $order = self::cleaningOrder($json);

        $segments = array();
        foreach ($bounds as $id => $box) {
            $meta = isset($info[(string) $id]) ? $info[(string) $id] : array();
            $type = isset($meta['type']) ? (int) $meta['type'] : 0;
            $nameIndex = isset($meta['index']) ? (int) $meta['index'] : 0;

            $custom = null;
            if (!empty($meta['name'])) {
                $decoded = base64_decode($meta['name'], false);
                /* Un nom illisible ne doit pas faire disparaître la pièce :
                 * mieux vaut « Pièce 3 » qu'une commande manquante. */
                if ($decoded !== false && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
                    $custom = $decoded;
                }
            }

            $center = self::segmentCenter($rows[$id], $box);

            $segments[$id] = array(
                'id' => (int) $id,
                'name' => self::segmentName($type, $nameIndex, $custom, $id),
                'custom_name' => $custom,
                'type' => $type,
                'index' => $nameIndex,
                'unique_id' => isset($meta['roomID']) ? $meta['roomID'] : null,
                'neighbours' => isset($meta['nei_id']) ? $meta['nei_id'] : array(),
                'floor_material' => isset($meta['material']) ? (int) $meta['material'] : null,
                /* Géométrie ramenée en millimètres, repère de la carte. */
                'x' => (int) floor($_map['left'] + ($center[0] * $grid) + intdiv($grid, 2)),
                'y' => (int) floor($_map['top'] + ($center[1] * $grid) + intdiv($grid, 2)),
                'x1' => (int) ($_map['left'] + ($box['x1'] * $grid)),
                'y1' => (int) ($_map['top'] + ($box['y1'] * $grid)),
                'x2' => (int) ($_map['left'] + (($box['x2'] + 1) * $grid)),
                'y2' => (int) ($_map['top'] + (($box['y2'] + 1) * $grid)),
                /* Surface réelle : le compte de pixels, pas le rectangle
                 * englobant, qui surestimerait toute pièce non rectangulaire. */
                'area' => round(($box['count'] * $grid * $grid) / 1000000, 2),
                'order' => isset($order[(string) $id]) ? (int) $order[(string) $id] : null,
            );

            /* Ce que le robot applique quand la carte ne porte aucun réglage —
             * cas d'une carte restaurée. Les laisser absents ferait croire à un
             * réglage manquant, alors que la machine en a bien un. */
            $settings = isset($cleanset[(string) $id]) ? $cleanset[(string) $id]
                      : array('suction' => 1, 'water_raw' => 3, 'water' => 2,
                              'repeats' => 1, 'mode' => 0, 'default' => true);
            $segments[$id] = array_merge($segments[$id], $settings);
        }

        ksort($segments);
        return $segments;
    }

    /*
     * Le centre affichable d'une pièce.
     *
     * Le milieu du rectangle englobant tombe hors de la pièce dès qu'elle est en
     * L — cas de la plupart des séjours. On prend donc le milieu du plus long
     * segment horizontal continu, ce qui reste à l'intérieur par construction.
     */
    private static function segmentCenter($_rows, $_box) {
        $bestLength = 0;
        $bestX = intdiv($_box['x1'] + $_box['x2'], 2);
        $bestY = intdiv($_box['y1'] + $_box['y2'], 2);

        foreach ($_rows as $y => $xs) {
            sort($xs);
            $runStart = null;
            $previous = null;
            foreach ($xs as $x) {
                if ($previous === null || $x !== $previous + 1) {
                    if ($runStart !== null) {
                        $length = $previous - $runStart + 1;
                        if ($length > $bestLength) {
                            $bestLength = $length;
                            $bestX = intdiv($runStart + $previous, 2);
                            $bestY = $y;
                        }
                    }
                    $runStart = $x;
                }
                $previous = $x;
            }
            if ($runStart !== null) {
                $length = $previous - $runStart + 1;
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $bestX = intdiv($runStart + $previous, 2);
                    $bestY = $y;
                }
            }
        }
        return array($bestX, $bestY);
    }

    public static function segmentName($_type, $_index, $_custom, $_id) {
        if ($_type > 0 && isset(self::$segmentTypes[$_type])) {
            $name = self::$segmentTypes[$_type];
            if ($_index > 0) {
                $name .= ' ' . ($_index + 1);
            }
            return $name;
        }
        if ($_custom !== null && $_custom !== '') {
            return $_custom;
        }
        return 'Pièce ' . $_id;
    }

    /* Noms prédéfinis, tels que l'application les propose. */
    public static $segmentTypes = array(
        1 => 'Salon', 2 => 'Chambre principale', 3 => 'Bureau', 4 => 'Cuisine',
        5 => 'Salle à manger', 6 => 'Salle de bain', 7 => 'Balcon', 8 => 'Couloir',
        /* Le type 3 et le type 12 sont deux pièces distinctes du catalogue.
         * Leur donner le même libellé produirait deux commandes « Nettoyer :
         * Bureau » sur le même équipement, et la contrainte d'unicité de Jeedom
         * ferait échouer l'enregistrement complet de l'équipement. */
        9 => 'Buanderie', 10 => 'Dressing', 11 => 'Salle de réunion', 12 => 'Bureau professionnel',
        13 => 'Salle de sport', 14 => 'Salle de jeux', 15 => 'Chambre d\'amis',
    );

    /*
     * Réglages par pièce.
     *
     * Le deuxième champ est décalé d'une unité par rapport au niveau d'eau des
     * commandes — c'est ainsi dans le protocole, sans raison apparente — et sur
     * les robots à base de lavage il porte en réalité un niveau d'humidité sur
     * une échelle bien plus fine. La valeur brute est donc conservée à côté de
     * l'interprétation, pour qu'un doute se tranche en la regardant plutôt qu'en
     * relisant ce commentaire.
     */
    public static function cleanset($_json) {
        if (!isset($_json['cleanset'])) {
            return array();
        }
        $cleanset = $_json['cleanset'];
        if (is_string($cleanset)) {
            $cleanset = json_decode($cleanset, true);
        }
        if (!is_array($cleanset)) {
            return array();
        }

        $result = array();
        foreach ($cleanset as $id => $item) {
            /* Une carte restaurée rend un cleanset vide alors que le robot, lui,
             * applique des valeurs par défaut. Les afficher comme « manquantes »
             * ferait croire à un réglage absent qui existe pourtant. */
            if (!is_array($item) || count($item) < 3) {
                continue;
            }
            $waterRaw = (int) $item[1];
            $result[(string) $id] = array(
                'suction' => (int) $item[0],
                'water_raw' => $waterRaw,
                'water' => ($waterRaw > 1 && $waterRaw < 5) ? $waterRaw - 1 : 1,
                'repeats' => (int) $item[2],
                'mode' => isset($item[4]) ? (int) $item[4] : null,
            );
        }
        return $result;
    }

    /*
     * Ordre de nettoyage. Deux formes coexistent selon la génération ; les
     * robots récents utilisent la première.
     */
    public static function cleaningOrder($_json) {
        $order = array();
        if (isset($_json['cleanareaorder']) && is_array($_json['cleanareaorder'])) {
            foreach ($_json['cleanareaorder'] as $entry) {
                if (is_array($entry)) {
                    foreach ($entry as $id => $rank) {
                        $order[(string) $id] = $rank;
                        break;
                    }
                }
            }
        } elseif (isset($_json['cleanOrder']) && is_array($_json['cleanOrder'])) {
            foreach ($_json['cleanOrder'] as $rank => $id) {
                $order[(string) $id] = $rank + 1;
            }
        }
        return $order;
    }

    /* ------------------------------------------------------------------ *
     * Liste des cartes sauvegardées
     * ------------------------------------------------------------------ */

    /*
     * Le fichier pointé par la propriété « liste des cartes ».
     *
     * Sa forme varie : JSON nu chez les uns, base64 de JSON chez les autres. On
     * essaie les deux plutôt que de parier, parce qu'un mauvais pari se
     * manifesterait par « aucune pièce trouvée » sans autre explication.
     */
    public static function decodeMapList($_content) {
        $content = trim((string) $_content);
        if ($content === '') {
            throw new dreamebeMapException('Liste des cartes vide.');
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            $plain = base64_decode(strtr($content, '_-', '/+'), false);
            if ($plain !== false) {
                $data = json_decode($plain, true);
            }
        }
        if (!is_array($data)) {
            throw new dreamebeMapException('Liste des cartes illisible.');
        }
        return $data;
    }

    /*
     * Toutes les cartes sauvegardées d'un robot, décodées, avec leurs pièces.
     *
     * Les robots multi-étages en gardent plusieurs ; « curr_id » désigne celle
     * qui est active. Le nom de carte, lui, est en clair — contrairement au nom
     * de pièce, qui est en base64 dans le même fichier sous la même clé. Le
     * piège est réel, il est signalé ici pour qu'on ne « corrige » pas l'un en
     * croyant réparer l'autre.
     */
    public static function parseMapList($_content, $_iv = null) {
        $data = self::decodeMapList($_content);

        $maps = array();
        $current = isset($data['curr_id']) ? (int) $data['curr_id'] : null;
        $list = isset($data['mapstr']) && is_array($data['mapstr']) ? $data['mapstr'] : array();

        foreach ($list as $entry) {
            if (!is_array($entry) || empty($entry['map'])) {
                continue;
            }
            try {
                $map = self::decode($entry['map'], $_iv);
            } catch (dreamebeMapException $e) {
                /* Une carte illisible sur cinq ne doit pas priver l'utilisateur
                 * des quatre autres. */
                continue;
            }
            $maps[] = array(
                'map_id' => $map['map_id'],
                'name' => isset($entry['name']) ? (string) $entry['name'] : '',
                'object_name' => isset($entry['mapobj']) ? (string) $entry['mapobj'] : '',
                'selected' => ($current !== null && $map['map_id'] === $current),
                'segments' => self::segments($map),
                'charger' => $map['charger'],
                'width' => $map['width'],
                'height' => $map['height'],
                'grid_size' => $map['grid_size'],
            );
        }
        return array('current' => $current, 'maps' => $maps);
    }
}
