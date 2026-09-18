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
 * Sert l'image de carte d'un robot.
 *
 * Les cartes vivent dans data/, que l'Apache du plugin interdit : c'est voulu,
 * le plan d'un logement n'a pas à être accessible à qui connaît son adresse.
 * Ce point d'entrée les rend accessibles à un utilisateur authentifié, et à lui
 * seul.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

if (!isConnect()) {
    http_response_code(401);
    exit();
}

/* Transtypage explicite : « ?id[]=1 » transmettrait un tableau à byId(). */
$id = (int) init('id');
$eqLogic = eqLogic::byId($id);
/*
 * Le contrôle de droits n'est pas une formalité. isConnect() est vrai pour tout
 * compte connecté, y compris un profil restreint qui n'a accès à aucun
 * équipement : sans hasRight(), il lui suffirait de faire varier l'identifiant
 * pour récupérer le plan de tous les logements de l'installation.
 */
if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'dreamebe' || !$eqLogic->hasRight('r')) {
    http_response_code(404);
    exit();
}

$path = __DIR__ . '/../../data/maps/' . $eqLogic->getId() . '.png';
if (!is_readable($path)) {
    http_response_code(404);
    exit();
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
/* Le nom du fichier ne change jamais : sans cette consigne, le navigateur
 * afficherait la carte d'il y a une heure en croyant bien faire. */
header('Cache-Control: no-store, must-revalidate');
readfile($path);
