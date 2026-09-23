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
 * Rendu d'une carte en image PNG.
 *
 * Volontairement modeste : sol colorié par pièce, murs, robot et station. Pas
 * d'icônes de meubles, pas d'obstacles détectés, pas de badges de réglages.
 *
 * Ce choix mérite d'être justifié plutôt que subi. Le rendu complet de
 * l'implémentation de référence fait plus de quatre mille lignes et s'appuie sur
 * vingt-sept mégaoctets de ressources graphiques et sur une bibliothèque
 * d'imagerie que GD n'égale pas : sa transparence est sur sept bits, ses lignes
 * n'ont ni jointure ni extrémité arrondies, et son texte n'a pas de contour. Le
 * transposer produirait beaucoup de code fragile pour une image qu'on regarde
 * trois secondes. Ce qu'on veut réellement dans Jeedom — « où en est-il, et
 * quelle pièce fait-il ? » — tient dans ce qui suit.
 */

class dreamebeMapImage {

    /* Au-delà, l'image pèse plus qu'elle n'informe sur un tableau de bord. */
    const MAX_WIDTH = 900;

    /*
     * Un plan d'habitation tient très largement là-dedans. Le plafond n'est pas
     * une préférence esthétique : largeur et hauteur viennent d'un en-tête
     * binaire, et un en-tête abîmé peut en annoncer 32767 chacune — soit une
     * demande d'allocation de plusieurs gigaoctets, donc une erreur fatale dans
     * le processus qui rend la page.
     */
    const MAX_PIXELS = 4000000;
    const MAX_SCALE = 16;

    /*
     * Les quatre couleurs de pièces de l'application. Quatre suffisent : deux
     * pièces voisines ne les partagent jamais, c'est le théorème des quatre
     * couleurs appliqué à un plan d'appartement.
     */
    public static $palette = array(
        array(171, 199, 248),
        array(249, 224, 125),
        array(184, 227, 255),
        array(184, 217, 141),
    );

    /*
     * Un plan se lit sur un fond clair, quel que soit le thème.
     *
     * Dessiné sur fond transparent, il laissait passer le gris du tableau de
     * bord — et des murs gris clair sur ce gris-là ne se distinguaient plus.
     * Le fond est donc opaque et neutre, et les murs assez sombres pour
     * trancher sur les pastels des pièces.
     */
    const COLOR_PAPER = array(246, 247, 249);
    const COLOR_WALL = array(72, 78, 86);
    const COLOR_UNKNOWN = array(214, 218, 223);
    const COLOR_ROBOT = array(30, 90, 160);
    const COLOR_DOCK = array(90, 90, 90);

    /*
     * Dessine la carte et l'écrit sur disque. Rend faux plutôt que de lever une
     * exception : une carte manquante ne doit pas faire échouer un cycle
     * d'actualisation par ailleurs réussi.
     */
    public static function render($_map, $_path) {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        if (empty($_map['pixels']) || $_map['width'] <= 0 || $_map['height'] <= 0
            || empty($_map['grid_size'])) {
            return false;
        }
        if (($_map['width'] * $_map['height']) > self::MAX_PIXELS) {
            return false;
        }

        $width = $_map['width'];
        $height = $_map['height'];
        $pixels = $_map['pixels'];

        $box = self::boundingBox($pixels, $width, $height);
        if ($box === null) {
            return false;
        }

        $useful = array(
            'x1' => max(0, $box['x1'] - 1),
            'y1' => max(0, $box['y1'] - 1),
            'x2' => min($width - 1, $box['x2'] + 1),
            'y2' => min($height - 1, $box['y2'] + 1),
        );
        $cols = $useful['x2'] - $useful['x1'] + 1;
        $rows = $useful['y2'] - $useful['y1'] + 1;

        /* Un pixel de carte fait typiquement cinq centimètres : sans
         * agrandissement, un appartement tient dans une vignette. */
        $scale = max(1, min(self::MAX_SCALE, (int) floor(self::MAX_WIDTH / max($cols, $rows))));

        $image = imagecreatetruecolor($cols * $scale, $rows * $scale);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocate($image,
                  self::COLOR_PAPER[0], self::COLOR_PAPER[1], self::COLOR_PAPER[2]));
        imagealphablending($image, true);

        $colours = self::segmentColours($_map);
        $allocated = array();
        $allocate = function ($rgb) use ($image, &$allocated) {
            $key = $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2];
            if (!isset($allocated[$key])) {
                $allocated[$key] = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
            }
            return $allocated[$key];
        };

        for ($y = $useful['y1']; $y <= $useful['y2']; $y++) {
            for ($x = $useful['x1']; $x <= $useful['x2']; $x++) {
                $offset = ($width * $y) + $x;
                if (!isset($pixels[$offset])) {
                    continue;
                }
                $byte = ord($pixels[$offset]);
                if ($byte === 0) {
                    continue;
                }
                $segment = $byte & 0x3F;
                if ($byte & 0x80) {
                    /* Bit de poids fort : mur, ou bordure d'une pièce. Les deux
                     * se dessinent pareil — c'est le trait noir du plan. */
                    $rgb = self::COLOR_WALL;
                } elseif ($segment > 0) {
                    $index = isset($colours[$segment]) ? $colours[$segment] : ($segment % count(self::$palette));
                    $rgb = self::$palette[$index];
                } else {
                    $rgb = self::COLOR_UNKNOWN;
                }

                /* L'axe vertical est inversé : dans le tampon, y croît vers le
                 * bas, alors que sur le plan il croît vers le haut. Sans cette
                 * inversion, la carte s'affiche en miroir et la station se
                 * retrouve du mauvais côté. */
                $ix = ($x - $useful['x1']) * $scale;
                $iy = ($useful['y2'] - $y) * $scale;
                imagefilledrectangle($image, $ix, $iy, $ix + $scale - 1, $iy + $scale - 1, $allocate($rgb));
            }
        }

        /*
         * Monde vers image. Le terme vertical est « y2 + 1 » et non « y2 » :
         * la formule rend le coin haut-gauche de la cellule, pas son centre, et
         * ajouter une demi-cellule par-dessus décalerait le robot et la station
         * en haut à droite — d'autant plus visiblement que l'agrandissement est
         * fort.
         */
        $toImage = function ($_point) use ($_map, $useful, $scale) {
            $px = (($_point['x'] - $_map['left']) / $_map['grid_size']) - $useful['x1'];
            $py = ($useful['y2'] + 1) - (($_point['y'] - $_map['top']) / $_map['grid_size']);
            return array((int) round($px * $scale), (int) round($py * $scale));
        };

        if (!empty($_map['charger'])) {
            $point = $toImage($_map['charger']);
            $size = max(4, $scale * 2);
            imagefilledrectangle($image, $point[0] - $size, $point[1] - $size,
                                 $point[0] + $size, $point[1] + $size, $allocate(self::COLOR_DOCK));
        }

        if (!empty($_map['robot'])) {
            $point = $toImage($_map['robot']);
            $angle = $_map['robot']['a'];
            $radius = max(6, $scale * 3);
            $colour = $allocate(self::COLOR_ROBOT);
            imagefilledellipse($image, $point[0], $point[1], $radius * 2, $radius * 2, $colour);
            /* Un trait dans le sens de la marche : sans lui, on ne sait pas si
             * le robot arrive ou repart. Posé à la station, il ne publie pas son
             * orientation : mieux vaut un disque sans aiguille qu'une aiguille
             * qui pointe au hasard. */
            if ($angle !== null) {
                $radians = deg2rad($angle);
                imagesetthickness($image, max(2, (int) ($scale / 2)));
                imageline($image, $point[0], $point[1],
                          (int) round($point[0] + cos($radians) * $radius * 1.8),
                          (int) round($point[1] - sin($radians) * $radius * 1.8),
                          imagecolorallocate($image, 255, 255, 255));
                imagesetthickness($image, 1);
            }
        }

        /* Écrite à côté puis renommée : map.php ne doit jamais servir une
         * image à moitié écrite, et un échec en cours de route ne doit pas
         * détruire la carte précédente. */
        $tmp = $_path . '.tmp';
        $ok = imagepng($image, $tmp);
        imagedestroy($image);
        if (!$ok || !rename($tmp, $_path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /*
     * L'empreinte de ce que render() dessinerait : exactement les données
     * qu'il lit, plus son propre code. Deux cartes de même empreinte donnent
     * la même image — inutile alors de la redessiner, et surtout de changer
     * son adresse, ce qui ferait recharger l'image dans chaque tuile ouverte.
     */
    public static function fingerprint($_map) {
        return md5(serialize(array(
            isset($_map['pixels']) ? $_map['pixels'] : '',
            isset($_map['width']) ? $_map['width'] : 0,
            isset($_map['height']) ? $_map['height'] : 0,
            isset($_map['grid_size']) ? $_map['grid_size'] : 0,
            isset($_map['left']) ? $_map['left'] : 0,
            isset($_map['top']) ? $_map['top'] : 0,
            isset($_map['robot']) ? $_map['robot'] : null,
            isset($_map['charger']) ? $_map['charger'] : null,
            isset($_map['json']['seg_inf']) ? $_map['json']['seg_inf'] : null,
            md5_file(__FILE__),
        )));
    }

    /* Le rectangle réellement occupé : le reste n'est que du vide à ne pas
     * transporter jusqu'au navigateur. */
    private static function boundingBox($_pixels, $_width, $_height) {
        $x1 = $_width;
        $y1 = $_height;
        $x2 = -1;
        $y2 = -1;
        $length = strlen($_pixels);
        for ($index = 0; $index < $length; $index++) {
            if ($_pixels[$index] === "\0") {
                continue;
            }
            $x = $index % $_width;
            $y = intdiv($index, $_width);
            $x1 = min($x1, $x);
            $y1 = min($y1, $y);
            $x2 = max($x2, $x);
            $y2 = max($y2, $y);
        }
        return ($x2 < 0) ? null : array('x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2);
    }

    /*
     * Attribue une couleur à chaque pièce.
     *
     * L'index de couleur n'est pas transmis par le robot : il se recalcule à
     * partir de la liste des voisins que porte chaque pièce. On prend à chaque
     * fois la couleur disponible la moins employée, ce qui évite qu'un
     * appartement entier vire au bleu.
     */
    public static function segmentColours($_map) {
        $info = isset($_map['json']['seg_inf']) && is_array($_map['json']['seg_inf'])
              ? $_map['json']['seg_inf'] : array();

        $neighbours = array();
        foreach ($info as $id => $meta) {
            $neighbours[(int) $id] = (isset($meta['nei_id']) && is_array($meta['nei_id']))
                                   ? array_map('intval', $meta['nei_id']) : array();
        }

        $colours = array();
        $usage = array_fill(0, count(self::$palette), 0);
        $ids = array_keys($neighbours);
        sort($ids);

        foreach ($ids as $id) {
            $taken = array();
            foreach ($neighbours[$id] as $neighbour) {
                if (isset($colours[$neighbour])) {
                    $taken[$colours[$neighbour]] = true;
                }
            }
            $best = null;
            foreach ($usage as $index => $count) {
                if (isset($taken[$index])) {
                    continue;
                }
                if ($best === null || $count < $usage[$best]) {
                    $best = $index;
                }
            }
            /* Plus de quatre voisins mutuels : impossible sur un plan, mais une
             * carte abîmée ne doit pas faire planter le rendu. */
            if ($best === null) {
                $best = $id % count(self::$palette);
            }
            $colours[$id] = $best;
            $usage[$best]++;
        }
        return $colours;
    }
}
