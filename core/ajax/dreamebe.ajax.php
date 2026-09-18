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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* isConnect('admin') est une égalité stricte de profil : isConnect('user')
     * serait faux pour un administrateur. */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    /* Depuis la 4.4, ajax::getToken() rend une chaîne vide : contrôler un jeton
     * casserait l'appel. L'authentification repose sur la session. */
    ajax::init();

    /*
     * eqLogic::byId() caste n'importe quel équipement vers la classe appelante :
     * un identifiant étranger produirait une erreur fatale plus loin, dans une
     * méthode qui n'existe pas. Le contrôle de type est obligatoire.
     */
    $getRobot = function ($_id) {
        $eqLogic = eqLogic::byId(init($_id));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'dreamebe') {
            throw new Exception(__('Robot introuvable :', __FILE__) . ' ' . init($_id));
        }
        return $eqLogic;
    };

    /*
     * Éprouve le compte et rend ce qu'il contient.
     *
     * Ne crée rien : c'est le bouton qu'on presse pour savoir si les
     * identifiants passent, avant d'ajouter quoi que ce soit à Jeedom.
     */
    if (init('action') == 'testAccount') {
        unautorizedInDemo();
        $devices = dreamebe::discover();
        $summary = array();
        foreach ($devices as $device) {
            $summary[] = array(
                'name' => $device['name'],
                'model' => dreamebeSpec::modelName($device['model']),
                'raw_model' => $device['model'],
                'online' => $device['online'],
                'battery' => $device['battery'],
                'shared' => $device['shared'],
            );
        }
        ajax::success(array('count' => count($summary), 'devices' => $summary));
    }

    /* Crée les équipements manquants pour tous les robots du compte. */
    if (init('action') == 'discover') {
        unautorizedInDemo();
        ajax::success(dreamebe::syncDevices());
    }

    /*
     * Redemande au robot ce qu'il sait faire. Utile après une mise à jour de son
     * micrologiciel, qui peut lui apporter des réglages qu'il n'avait pas.
     */
    if (init('action') == 'probe') {
        unautorizedInDemo();
        $robot = $getRobot('id');
        $supported = $robot->probe();
        $robot->createCommands();
        ajax::success(array('count' => count($supported), 'supported' => $supported));
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $robot = $getRobot('id');
        $robot->poll(true);
        ajax::success(true);
    }

    if (init('action') == 'rooms') {
        unautorizedInDemo();
        $robot = $getRobot('id');
        if (init('reload') == 1) {
            $robot->refreshRooms();
        }
        ajax::success(array_values($robot->rooms()));
    }

    if (init('action') == 'map') {
        unautorizedInDemo();
        $robot = $getRobot('id');
        /* Le verdict compte : une carte partielle, absente ou illisible ne
         * produit pas d'image, et annoncer « carte à jour » juste au-dessus
         * d'un « aucune carte » serait se contredire dans la même page. */
        $rendered = $robot->refreshMap();
        ajax::success(array(
            'rendered' => (bool) $rendered,
            'url' => dreamebe::mapUrl($robot->getId()) . '&t=' . time(),
        ));
    }

    if (init('action') == 'history') {
        /* Avec reload=1, cette action interroge le cloud : c'est une écriture
         * au sens du mode démonstration, comme les six autres. */
        unautorizedInDemo();
        $robot = $getRobot('id');
        if (init('reload') == 1) {
            $robot->refreshHistory();
        }
        $history = $robot->getConfiguration('history', array());
        ajax::success(is_array($history) ? $history : array());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));
    /*
     * Throwable et non Exception : les erreurs de PHP 8 n'héritent pas
     * d'Exception, et un appel non rattrapé rendrait un HTTP 500 sans corps
     * JSON — que le JS du coeur réessaie trois fois avant d'abandonner.
     */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
