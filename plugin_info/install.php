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

require_once __DIR__ . '/../../../core/php/core.inc.php';

/* Crée les dossiers produits à l'exécution et les protège d'Apache : une carte
 * est le plan d'un logement, elle n'a rien à faire en libre service. */
function dreamebe_prepareData() {
    foreach (array(__DIR__ . '/../data', __DIR__ . '/../data/maps') as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    /* Réécrit à chaque mise à jour, et en Require plutôt qu'en Deny : le
     * FilesMatch du .htaccess racine de Jeedom autorise tous les png, et
     * l'emporte sur un « Deny from all » de dossier. L'ancienne règle laissait
     * donc les cartes lisibles sans session. */
    $htaccess = __DIR__ . '/../data/.htaccess';
    $rule = "Require all denied\n";
    if (!file_exists($htaccess) || strpos(file_get_contents($htaccess), 'Require all denied') === false) {
        file_put_contents($htaccess, $rule);
    }
}

function dreamebe_install() {
    dreamebe_prepareData();
}

/*
 * Appelée à chaque mise à jour, dans la requête HTTP et sans être détachée :
 * aucun appel réseau ici, sous peine de faire expirer la page « Gestion des
 * plugins ». Tout ce qui s'y trouve doit être hors ligne et rapide.
 */
function dreamebe_update() {
    try {
        dreamebe_prepareData();
        foreach (dreamebe::byType('dreamebe') as $eqLogic) {
            $eqLogic->createCommands();
        }
    } catch (Throwable $e) {
        log::add('dreamebe', 'error', __('Mise à jour du plugin :', __FILE__) . ' ' . $e->getMessage());
    }
}

/*
 * Appelée aussi à la simple désactivation du plugin, pas seulement à sa
 * désinstallation : ne rien y détruire d'irrécupérable. La session, elle, ne
 * vaut plus rien une fois le plugin arrêté, et la garder serait garder un jeton
 * d'accès pour rien.
 */
function dreamebe_remove() {
    try {
        config::save('session', '', 'dreamebe');
    } catch (Throwable $e) {
        log::add('dreamebe', 'error', __('Désinstallation :', __FILE__) . ' ' . $e->getMessage());
    }
}
