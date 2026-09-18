# Plugin Dreame

Ce plugin relie Jeedom aux aspirateurs robots Dreame récents, par le cloud
**DreameHome**. Il n'y a ni démon, ni passerelle, ni dépendance à installer :
Jeedom parle au serveur de Dreame, et le serveur parle au robot.

Une fois le compte renseigné, chaque robot devient un équipement Jeedom avec son
état, sa batterie, ses erreurs, ses consommables, ses ordres de nettoyage et,
quand la carte est disponible, une commande par pièce. Tout cela est utilisable
dans les scénarios, ce qui est le seul intérêt de brancher un robot sur une
domotique : qu'il parte quand la maison se vide, et qu'il prévienne quand il
s'arrête.

## Pour quels robots

La cible est le **Dreame L40 Ultra** et ses variantes, dont la variante AE, sur
laquelle le protocole a été relevé. Les autres modèles récents de la gamme
fonctionnent dans la mesure où ils répondent au même dictionnaire de propriétés,
ce que le plugin vérifie robot par robot plutôt que de le supposer (voir « Ce que
le robot sait faire »).

Deux limites de périmètre, à connaître avant d'installer quoi que ce soit :

- Le plugin passe par le compte de l'application **DreameHome**. Les anciens
  Dreame rattachés à **Mi Home** parlent le protocole MiIO de Xiaomi, qui n'a
  rien à voir : un compte Mi Home sera refusé à la connexion, et ce n'est pas un
  problème de mot de passe.
- Les marques sœurs qui utilisent le même cloud avec un autre locataire — Mova,
  Trouver — ne sont pas gérées.

Le nom du modèle affiché par le plugin vient d'une table de correspondance qui ne
sert qu'à l'affichage : un robot absent de cette table apparaît sous sa référence
d'usine et fonctionne exactement pareil.

## Installation

1. Installez le plugin depuis le Market, puis activez-le.
2. Ouvrez sa configuration et renseignez le compte DreameHome (section
   suivante).
3. Cliquez sur **Tester le compte**, dans cette même fenêtre. Le plugin
   enregistre la configuration, ouvre une session et affiche la liste des robots
   trouvés, avec leur modèle et leur état de connexion. Rien n'est créé à ce
   stade : c'est le bouton qu'on presse pour savoir si les identifiants passent.
4. Revenez sur la page du plugin et cliquez sur la vignette **Découvrir les
   robots**. Le plugin crée un équipement par robot, annonce ce qu'il a ajouté et
   ce qu'il connaissait déjà, puis recharge la page.
5. Ouvrez la fiche du robot qui vient d'apparaître dans **Mes robots**, donnez-lui
   un objet parent — sans objet parent, l'équipement n'apparaîtra sur aucun
   dashboard — et **sauvegardez**.

Tant qu'aucun robot n'existe, la page du plugin affiche ces étapes en toutes
lettres, de sorte qu'on n'a pas à revenir ici pour savoir par où commencer.

Aucune dépendance n'est à installer et aucun démon ne tourne : le plugin ne
travaille que dans le cycle d'une minute du cœur de Jeedom.

## Le compte DreameHome

Trois réglages, dans la section **Compte DreameHome** :

| Réglage | Ce qu'il attend |
|---|---|
| **Adresse électronique** | Le compte de l'application DreameHome, pas un compte Xiaomi ni Mi Home. |
| **Mot de passe** | Celui du même compte. |
| **Région** | Celle choisie à la création du compte : Europe, Amérique, Chine, Russie, Singapour ou Corée. |

La **région** mérite une attention particulière, parce que l'erreur qu'elle
provoque ne dit pas son nom. Un compte n'existe que dans **une seule** région :
les serveurs sont indépendants, et celui d'Europe ne connaît pas un compte créé
en Amérique. Si vous vous trompez, le serveur ne répond pas « mauvaise région »
mais **« identifiants refusés »** — et l'on cherche alors longtemps du côté du
mot de passe. En cas de doute, la région est celle du pays choisi dans
l'application lors de la création du compte. Lorsque la connexion réussit et que
le serveur signale que le compte vit ailleurs, le plugin suit cette indication et
corrige le réglage tout seul.

Le mot de passe est conservé dans la base de Jeedom. Il n'est présenté qu'à la
**première** connexion : le plugin obtient alors un jeton de session qu'il
renouvelle ensuite sans le mot de passe. Ce n'est pas un raffinement gratuit, le
nombre de tentatives d'authentification est limité côté Dreame (voir « Limites
connues »).

Modifier l'adresse, le mot de passe ou la région efface la session enregistrée :
la suivante sera rouverte au prochain cycle, avec les nouvelles valeurs.

## La découverte des robots

Le bouton **Découvrir les robots** demande au compte la liste de ses appareils et
crée un équipement pour chacun. **Plusieurs robots sur un même compte donnent
autant d'équipements Jeedom indépendants**, chacun avec ses propres commandes,
son propre état et sa propre carte. Rien dans le plugin ne suppose qu'il n'y en a
qu'un.

Les robots **partagés** avec vous par un autre membre du foyer sont inclus : ils
sont pilotables comme les vôtres.

Deux robots du même modèle jamais renommés porteraient le même nom d'usine ; le
plugin numérote alors le second, faute de quoi Jeedom refuserait de le créer. Le
nom de l'équipement vous appartient ensuite : le renommer dans Jeedom ne change
rien au reste.

Relancer la découverte plus tard est sans danger : les équipements existants sont
mis à jour, pas recréés.

## Ce que le robot sait faire

Le plugin ne crée pas un jeu de commandes fixe. Au premier contact avec un robot,
il lui **demande** ce qu'il sait faire : il l'interroge sur toutes les propriétés
du dictionnaire, note celles auxquelles la machine a répondu, et ne crée que les
commandes correspondantes.

Ce détour a une raison précise. Le protocole MIoT de Dreame **n'expose aucune
table de propriétés par modèle** : le même dictionnaire couvre toute la gamme, du
robot d'entrée de gamme au modèle à base de lavage, et c'est le robot qui tranche
à l'exécution — une propriété qu'il ne connaît pas répond simplement un code
d'erreur. La seule manière honnête de savoir si un robot a une base de lavage, un
module de détergent ou un curseur d'humidité, c'est de le lui demander.

Concrètement, cela veut dire qu'un robot sans base de lavage reçoit une commande
**Régler le niveau d'eau**, quand un robot avec base reçoit **Régler
l'humidité**, **Régler le mode** et les ordres de lavage et de séchage de la
serpillière. Et qu'aucune machine ne se voit affubler d'un réglage qu'elle n'a
pas.

Le sondage a lieu une fois, au premier cycle qui suit la création de
l'équipement, puis sa réponse est conservée. Le bouton **Sonder les capacités**,
sur la fiche du robot, permet de le relancer à la demande. Deux occasions le
justifient : un robot éteint ou débranché au moment de la découverte, qui n'a
donc rien pu répondre, et surtout une **mise à jour du micrologiciel**, qui peut
apporter à la machine des réglages qu'elle n'avait pas — sans nouveau sondage,
les commandes correspondantes ne seraient jamais créées.

## La fiche d'un robot

La page du plugin présente deux vignettes — **Découvrir les robots** et
**Configuration** — puis la liste **Mes robots**. Un clic sur un robot ouvre sa
fiche, organisée en cinq onglets.

Les onglets **Pièces**, **Carte** et **Historique** ne se remplissent qu'au
moment où on les ouvre. C'est un choix assumé : chacun de ces contenus coûte un
appel au cloud, et les charger d'office en ferait trois à chaque ouverture de
fiche, pour des données qu'on ne regarde pas à chaque fois.

Les boutons de ces onglets agissent sur un équipement enregistré : sur un robot
jamais sauvegardé, ils le disent plutôt que d'échouer obscurément.

### Onglet Équipement

À gauche, les réglages habituels de Jeedom : nom, objet parent, catégories,
activation et visibilité.

À droite, l'identité du robot telle que le cloud la donne — **identifiant
DreameHome**, **modèle** et **micrologiciel**. Ces trois champs sont en lecture
seule : ils sont renseignés par la découverte, et modifier l'identifiant
couperait le lien avec le robot.

Au-dessous, un bandeau résume l'état des capacités : le modèle reconnu, le nombre
de propriétés auxquelles le robot a répondu et la date du dernier sondage. Tant
qu'aucun sondage n'a eu lieu, il le dit — c'est l'information la plus utile de la
fiche, puisque sans sondage les commandes restent incomplètes.

Deux boutons l'accompagnent :

- **Sonder les capacités** redemande au robot ce qu'il sait faire et recrée les
  commandes en conséquence. C'est le bouton à utiliser après une mise à jour du
  micrologiciel.
- **Actualiser maintenant** force une lecture immédiate de l'état, sans attendre
  l'intervalle réglé. Pratique pour vérifier un réglage qu'on vient de changer
  dans l'application.

### Onglet Pièces

Le bouton **Relire les pièces** retélécharge la carte et en réextrait les pièces.
À la simple ouverture de l'onglet, le plugin se contente d'afficher celles qu'il
connaît déjà, sans rien demander au cloud.

Le tableau donne, pour chaque pièce :

| Colonne | Contenu |
|---|---|
| **Identifiant** | Le numéro de la pièce, celui qu'accepte `nettoyer_pieces` et qui suffixe la commande `room::…`. |
| **Nom** | Le nom de la pièce, tel qu'il figure dans la carte : le nom saisi dans l'application, ou à défaut son type. |
| **Surface** | En mètres carrés, arrondie à deux décimales. Elle est calculée sur les pixels réellement occupés, et non sur le rectangle englobant, qui surestimerait toute pièce en L. |
| **Centre (mm)** | Un point situé à l'intérieur de la pièce, dans le repère de la carte. |
| **Aspiration** | La puissance réglée pour cette pièce dans l'application DreameHome. |
| **Passages** | Le nombre de passages réglé pour cette pièce dans l'application. |

Un tableau vide signifie que le robot n'a pas de carte enregistrée, ou que la
récupération de la carte est désactivée dans la configuration du plugin.

### Onglet Carte

L'image de la carte, telle que le plugin l'a dessinée au dernier téléchargement.
Le bouton **Retélécharger la carte** va en chercher une nouvelle tout de suite,
ce qui est le moyen de voir où se trouve le robot sans attendre le prochain
cycle. Si aucune carte n'a encore été récupérée, l'onglet le dit.

### Onglet Historique

Les vingt derniers nettoyages connus : **date**, **durée**, **surface** et
**fin**, cette dernière colonne indiquant si le nettoyage est allé à son terme ou
a été interrompu. Le bouton **Relire l'historique** redemande ces événements au
cloud ; sans lui, l'onglet affiche ce qui est déjà conservé avec l'équipement.

### Onglet Commandes

Le tableau habituel des commandes de Jeedom : nom, icône, type, visibilité,
historisation, configuration et test. L'identifiant interne de chaque commande —
celui des tableaux plus bas — s'affiche en infobulle sur sa ligne, ce qui évite
de le chercher ailleurs au moment d'écrire un scénario.

## Les commandes

Les identifiants internes ci-dessous sont ceux à employer dans les scénarios et
les appels d'API. Ils sont figés : ils ne changeront pas d'une version à l'autre.

Le tableau de bord est celui du cœur de Jeedom : chaque commande visible y porte
son nom. C'est le point important, et il vient d'un essai en conditions réelles :
une rangée d'icônes sans libellé ne se devine pas. « Arrêter » interrompt le
nettoyage là où le robot se trouve ; « Retourner à la station » le renvoie se
recharger. Ce sont deux ordres distincts du protocole, et rien ne les
distinguerait sans leur étiquette.

**Dix-neuf commandes sont visibles** à la création, sur un robot à six pièces :
l'état (`etat`), la batterie (`batterie`), l'erreur (`erreur`), la station
(`station`), la carte (`carte`) ; les cinq ordres du quotidien (`demarrer`,
`pause`, `arreter`, `retour_station`, `localiser`) ; les trois réglages qu'on
change avant de lancer un nettoyage (`regler_aspiration`, `regler_mode` et
`regler_humidite`, ou `regler_eau` selon la machine) ; et une commande par pièce,
« Nettoyer : *pièce* » n'ayant besoin d'aucune explication. Tout le reste est
créé **masqué** : un robot expose ici une centaine de commandes, et les afficher
toutes noierait l'essentiel. Masquer n'est pas supprimer — ces commandes restent
tenues à jour, utilisables dans les scénarios, et une case à cocher dans l'onglet
Commandes suffit à en afficher une.

Cette visibilité n'est posée qu'à la **création** : ce que l'utilisateur règle
ensuite lui appartient et n'est jamais redéfini, même après une mise à jour du
plugin. Pour repartir de la présentation d'origine, la méthode
`applyDefaultVisibility()` d'un équipement ramène ses commandes à la visibilité
d'un équipement neuf, sans jamais toucher à une commande ajoutée à la main. Elle
ne figure pas dans le cycle d'actualisation et ne s'exécute que sur demande :
elle défait des réglages d'affichage, ce qui ne doit jamais arriver par surprise.

Dans les tableaux ci-dessous, la mention « masquée » signale les valeurs brutes
qui doublent un libellé lisible, et qui n'ont d'intérêt que pour un calcul dans
un scénario.

### État du robot

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `etat` | État | info / texte | L'état détaillé : aspiration, lavage, retour à la station, charge… |
| `code_etat` | Code état | info / numérique | La valeur brute derrière `etat`. Masquée. |
| `statut` | Statut | info / texte | Ce que fait le robot : nettoyage, nettoyage de pièces, nettoyage de zones, au repos… |
| `en_activite` | En activité | info / binaire | 1 quand le robot travaille. C'est le booléen à utiliser comme déclencheur. |
| `en_ligne` | En ligne | info / binaire | Tel que le cloud voit le robot. |
| `batterie` | Batterie | info / numérique (%) | Historisée. |
| `en_charge` | En charge | info / binaire | |
| `charge` | État de charge | info / texte | En charge, sur batterie, charge terminée, retour à la station. Masquée. |
| `erreur` | Erreur | info / texte | Le libellé de l'erreur, ou « Aucune erreur ». |
| `code_erreur` | Code erreur | info / numérique | Masquée. |
| `en_erreur` | En erreur | info / binaire | 1 sur une panne du robot. |
| `en_alerte` | Alerte station | info / binaire | 1 sur un avertissement de la station : bac plein, réservoir à vider. |

La distinction entre `en_erreur` et `en_alerte` est délibérée. Un bac à poussière
plein et une roue bloquée arrivent par le même canal, mais l'un se règle en
passant devant la station et l'autre demande qu'on aille chercher le robot. Les
confondre revient à recevoir une notification d'incident pour un sac à changer.

### Nettoyage en cours

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `duree` | Durée du nettoyage | info / numérique (min) | Historisée. |
| `surface` | Surface nettoyée | info / numérique (m²) | Historisée. |
| `tache` | Tâche | info / texte | La nature de la tâche en cours ou interrompue. |
| `progression` | Progression | info / numérique (%) | Créée seulement si le robot publie cette valeur. |

### Réglages de nettoyage

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `aspiration` | Niveau aspiration | info / numérique | 0 à 3. Masquée. |
| `aspiration_texte` | Aspiration (texte) | info / texte | Silencieux, Standard, Fort, Turbo. |
| `reservoir` | Réservoir | info / texte | Masquée. |
| `serpillere` | Serpillière posée | info / binaire | |

Sur un robot **à base de lavage** s'ajoutent :

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `mode` | Mode | info / numérique | Masquée. |
| `mode_texte` | Mode (texte) | info / texte | Aspiration seule, Lavage seul, Aspiration et lavage, Lavage après aspiration. |
| `humidite` | Humidité | info / numérique | Masquée. |
| `humidite_texte` | Humidité (texte) | info / texte | Peu humide, Humide, Très humide. |
| `humidite_niveau` | Humidité (niveau fin) | info / numérique | L'échelle de 1 à 32 des robots récents, quand ils l'exposent. Masquée. |
| `station` | Station | info / texte | Ce que fait la base : lavage, séchage, remplissage… |
| `alerte_eau` | Alerte eau | info / texte | |
| `reservoir_propre` | Réservoir eau propre | info / texte | |
| `reservoir_sale` | Réservoir eau sale | info / texte | |
| `sac` | Sac à poussière | info / texte | |

Sur un robot **sans base de lavage**, ce sont à la place :

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `eau` | Niveau eau | info / numérique | Masquée. |
| `eau_texte` | Niveau eau (texte) | info / texte | Faible, Moyen, Élevé. |

### Ordres

| Identifiant | Nom | Type |
|---|---|---|
| `demarrer` | Démarrer | action |
| `pause` | Pause | action |
| `reprendre` | Reprendre | action |
| `arreter` | Arrêter | action |
| `retour_station` | Retourner à la station | action |
| `localiser` | Localiser | action |
| `acquitter` | Acquitter le message | action |
| `regler_aspiration` | Régler la puissance | action / liste — Silencieux, Standard, Fort, Turbo |
| `nettoyer_pieces` | Nettoyer des pièces | action / message |
| `nettoyer_zone` | Nettoyer une zone | action / message |

Sur un robot **à base de lavage** :

| Identifiant | Nom | Type |
|---|---|---|
| `regler_humidite` | Régler le taux humidité | action / liste — Peu humide, Humide, Très humide |
| `regler_mode` | Régler le mode | action / liste — Aspiration seule, Lavage seul, Aspiration et lavage, Lavage après aspiration |
| `laver_serpillere` | Laver la serpillière | action |
| `secher_serpillere` | Sécher la serpillière | action |
| `arreter_sechage` | Arrêter le séchage | action |

Sur un robot **sans base de lavage**, à la place : `regler_eau`, « Régler le
débit eau », en liste Faible / Moyen / Élevé.

Enfin, `vider_bac` (« Vider le bac ») n'est créée que si le robot a déclaré une
station à vidage automatique.

`demarrer` et `reprendre` envoient le même ordre au robot : c'est ainsi que
fonctionne le protocole, qui reprend une tâche en pause avec la commande de
départ. Les deux commandes existent parce qu'un scénario nommé « reprendre » se
relit mieux qu'un scénario qui démarre ce qui tourne déjà.

### Propriétés secondaires

Ces commandes d'information ne sont créées que si **votre** robot répond à la
propriété correspondante : c'est encore le sondage qui décide. Les cinq
premières changent pendant le travail et sont relues à chaque cycle ; les autres
ne bougent que lorsqu'on les change, et sont relues avec les consommables et les
statistiques.

| Identifiant | Nom | Type | Valeurs |
|---|---|---|---|
| `sechage_progression` | Progression du séchage | info / numérique (%) | |
| `type_tache` | Type de tâche | info / texte | Nettoyage standard, personnalisé, programmé, sur appel, traitement d'une tache, entretien du sol… |
| `localisation` | Localisation | info / texte | Localisé, Localisation en cours, Échec, Réussie |
| `vidage_disponible` | Auto-vidage disponible | info / texte | Indisponible, Disponible, Usage prolongé, Jamais |
| `vidage_etat` | Auto-vidage en cours | info / texte | Au repos, En cours, Non effectué |
| `volume` | Volume des annonces | info / numérique (%) | |
| `dnd` | Ne pas déranger | info / binaire | |
| `temperature_eau` | Température eau | info / texte | Normale, Tiède, Chaude, Très chaude, Maximale |
| `niveau_lavage` | Niveau de lavage | info / texte | Économie d'eau, Quotidien, Profond |
| `duree_sechage` | Durée de séchage | info / numérique (h) | |
| `tapis` | Gestion des tapis | info / texte | Non défini, Évitement, Adaptation, Retrait de la serpillière, et quelques variantes selon la machine |
| `detergent_auto` | Détergent automatique | info / texte | Désactivé, Activé, Absent |
| `eau_chaude` | Eau chaude | info / texte | Désactivée, Activée |
| `detergent` | Détergent | info / texte | Installé, Désactivé, Niveau bas |
| `premier_nettoyage` | Premier nettoyage | info / texte | La date du premier nettoyage du robot. |

### Réglages secondaires

Six de ces propriétés se règlent aussi depuis Jeedom. Chacune n'apparaît que si
le robot a répondu à la propriété correspondante, et met à jour l'information qui
lui fait face sans attendre le prochain cycle lent — sans quoi l'ancienne valeur
resterait affichée une demi-heure, et l'on croirait l'ordre perdu.

| Identifiant | Nom | Type | Valeurs |
|---|---|---|---|
| `regler_volume` | Régler le volume | action / curseur | de 0 à 100 |
| `regler_dnd` | Régler « Ne pas déranger » | action / liste | Désactivé, Activé |
| `regler_temperature` | Régler la température eau | action / liste | Normale, Tiède, Chaude, Très chaude, Maximale |
| `regler_niveau_lavage` | Régler le niveau de lavage | action / liste | Économie d'eau, Quotidien, Profond |
| `regler_tapis` | Régler la gestion des tapis | action / liste | Non défini, Évitement, Adaptation, Retrait de la serpillière |
| `regler_detergent` | Régler le détergent automatique | action / liste | Désactivé, Activé |

### Entretien

Pour chaque consommable que le robot reconnaît, trois commandes sont créées, où
`<nom>` est l'identifiant du consommable :

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `<nom>_wear` | *Libellé* restant | info / numérique (%) | Historisée. |
| `<nom>_left` | *Libellé* (durée) | info / numérique (h ou j) | Masquée. |
| `raz_<nom>` | Remettre à zéro : *libellé* | action | Masquée. |

Les consommables que le plugin sait nommer :

| `<nom>` | Libellé |
|---|---|
| `main_brush` | Brosse principale |
| `side_brush` | Brosse latérale |
| `filter` | Filtre |
| `sensor` | Capteurs |
| `tank_filter` | Filtre du réservoir |
| `mop_pad` | Serpillière |
| `silver_ion` | Module ions argent |
| `detergent` | Détergent |
| `squeegee` | Raclette |
| `deodorizer` | Module désodorisant |
| `wheel` | Roues |
| `scale_inhib` | Anti-calcaire |

Seuls ceux que **votre** robot possède réellement donnent lieu à des commandes :
la raclette n'existe que sur les modèles à rouleau, le module désodorisant et les
roues sur certaines variantes. C'est le sondage qui décide, pas cette liste.

La commande de remise à zéro est créée invisible : elle correspond au geste qu'on
fait dans l'application après avoir changé une pièce, et elle n'a rien à faire à
portée de clic sur un dashboard. Après une remise à zéro, les pourcentages sont
relus au cycle suivant sans attendre l'intervalle habituel.

### Statistiques et historique

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `total_time` | Durée totale | info / numérique (min) | Masquée. |
| `total_area` | Surface totale | info / numérique (m²) | Masquée. |
| `total_count` | Nombre de nettoyages | info / numérique | Masquée. |
| `dernier_nettoyage` | Dernier nettoyage | info / texte | Date et heure. |
| `derniere_duree` | Durée du dernier nettoyage | info / numérique (min) | |
| `derniere_surface` | Surface du dernier nettoyage | info / numérique (m²) | |

Les trois dernières ne sont créées que si l'historique est activé dans la
configuration du plugin.

### Carte et position

Créées seulement si la récupération de la carte est activée :

| Identifiant | Nom | Type | Remarque |
|---|---|---|---|
| `position_x` | Position X | info / numérique (mm) | Masquée. |
| `position_y` | Position Y | info / numérique (mm) | Masquée. |
| `orientation` | Orientation | info / numérique (°) | Masquée. |
| `carte` | Carte | info / texte | L'adresse de l'image de la carte, affichée comme une image sur le dashboard par le widget du plugin. |

### Une commande par pièce

Pour chaque pièce de la carte, une commande d'action `room::<identifiant>`,
nommée **Nettoyer : *nom de la pièce***. Elles apparaissent dès que la carte a
été lue une première fois, et disparaissent d'elles-mêmes quand une pièce est
fusionnée ou supprimée dans l'application : une commande qui échouerait en
silence ne vaut rien.

S'y ajoute une commande d'information `pieces` (« Pièces »), qui rend la liste
des noms de pièces séparés par des virgules. Elle existe pour qu'un scénario
puisse les énumérer sans que leurs noms soient écrits en dur dans son code : le
jour où une pièce est renommée dans l'application, le scénario suit.

## Nettoyer une ou plusieurs pièces

Il y a deux façons de faire, et elles coexistent volontairement.

**Une commande par pièce.** `room::3`, affichée « Nettoyer : Cuisine », lance le
nettoyage de cette seule pièce. C'est ce qu'on veut sur un dashboard et dans un
scénario simple : le nom est lisible, rien à retenir.

**La commande générique `nettoyer_pieces`.** Elle attend une liste séparée par
des virgules, et accepte indifféremment des identifiants ou des noms de pièces :

```
3,5
Cuisine, Salon
```

La comparaison des noms ignore la casse. Un nom inconnu fait échouer la commande
avec le nom en question dans le message : mieux vaut une erreur explicite qu'un
robot qui part nettoyer autre chose. C'est cette commande qu'on utilise quand la
liste des pièces dépend du scénario plutôt que d'être écrite d'avance.

Elle accepte aussi deux paramètres, à la suite de la liste. Le séparateur est
une **barre verticale**, et non une virgule : la liste des pièces en contient
déjà, et il faut bien distinguer l'une de l'autre.

```
Cuisine, Salon          nettoie ces deux pièces une fois, au réglage du robot
Cuisine, Salon | 2      y passe deux fois
Cuisine | 2 | 3         y passe deux fois, en Turbo
```

Le deuxième champ est le nombre de passages, le troisième le niveau
d'aspiration, de 0 (silencieux) à 3 (turbo). Omis, chacun laisse au robot son
propre réglage.

Sans ces paramètres, la puissance d'aspiration et le niveau d'eau appliqués à
chaque pièce sont ceux **réglés pour cette pièce dans l'application DreameHome**,
lus dans la carte. Le plugin ne les impose pas : l'application reste l'endroit où
l'on décide que la cuisine se lave et que la chambre s'aspire. L'ordre de passage
entre les pièces se règle lui aussi dans l'application.

## Nettoyer une zone

`nettoyer_zone` attend un rectangle, en **millimètres dans le repère de la
carte** :

```
x1,y1,x2,y2
```

Un cinquième nombre, facultatif, donne le nombre de passages :

```
-1500,200,-300,1400,2
```

Les coordonnées sont celles du repère de la carte du robot, où l'origine est le
point de départ de la cartographie et non un coin du logement : les valeurs
négatives sont normales. L'ordre des points n'a pas d'importance, le plugin remet
le rectangle à l'endroit. Les commandes `position_x` et `position_y` donnent la
position courante du robot dans ce même repère : promener le robot et relever sa
position reste le moyen le plus simple de délimiter une zone.

**Chaque côté doit dépasser 100 mm.** Un rectangle plus petit est refusé par le
robot sans la moindre explication ; le plugin le refuse donc lui-même, avec une.

## La carte

La carte sert à deux choses, et la première est la plus importante : **c'est le
seul endroit où figurent les noms des pièces**. Ni le protocole MIoT ni le cloud
n'exposent la liste des pièces ; les identifiants, les noms et les réglages par
pièce ne vivent que dans le fichier de carte déposé par le robot. Sans elle, le
nettoyage par pièce est impossible — c'est pourquoi désactiver la carte dans la
configuration désactive aussi les commandes `room::…`.

La seconde est l'image. Le plugin dessine un PNG et publie son adresse dans la
commande info `carte`, sous la forme
`plugins/dreamebe/core/php/map.php?id=<identifiant>&t=<horodatage>`.
L'horodatage change à chaque nouvelle carte : sans lui, le navigateur, voyant
toujours la même adresse, afficherait consciencieusement la carte d'il y a une
heure. On y voit :

- le sol, colorié par pièce, quatre couleurs se répartissant le plan de façon que
  deux pièces voisines n'aient jamais la même ;
- les murs et les bordures de pièces ;
- le robot, avec un trait dans son sens de marche ;
- la station.

On n'y voit **pas** les meubles, les obstacles détectés, les tapis, les zones
interdites ni le trajet parcouru. Ce choix est expliqué plus bas, dans « Choix
techniques ».

Le plan est dessiné sur un **fond clair opaque**, avec des murs sombres, et cela
quel que soit le thème de Jeedom. Sur fond transparent, l'image laissait passer le
gris du tableau de bord, et des murs gris clair s'y confondaient jusqu'à
disparaître. Le widget ajoute par-dessus un liseré discret, qui détache le plan du
reste de la tuile.

Aller chercher une carte demande trois requêtes et quelques centaines de
kilo-octets, pour une information qui ne bouge pas d'une minute à l'autre. Elle
est donc mise en cache sur le disque de Jeedom et retéléchargée seulement à
l'intervalle réglé — un quart d'heure par défaut.

Sur le dashboard, la commande `carte` ne s'affiche pas comme une ligne de texte :
le plugin fournit un widget qui en fait une **image**, sur l'ordinateur comme sur
le mobile. Un clic l'ouvre en grand dans un nouvel onglet. Tant qu'aucune carte
n'a été récupérée, la tuile le dit en toutes lettres plutôt que de montrer une
image cassée, qu'on prendrait pour une panne du robot.

L'image n'est pas servie en accès libre : elle est déposée dans un dossier que
le serveur web interdit, et délivrée par un passe-plat qui exige une session
Jeedom ouverte — sans quoi le plan d'un logement serait accessible à qui en
connaît l'adresse. En contrepartie, un service extérieur — un message Telegram,
un courriel — ne pourra pas la charger seul.

Sur un logement à plusieurs étages, le robot conserve plusieurs cartes ; le
plugin ne retient que celle qui est sélectionnée, puisque c'est la seule qui
décrive ce que la machine va nettoyer maintenant.

## L'entretien

Les pourcentages de consommables et les compteurs cumulés sont relus à part du
cycle principal, toutes les demi-heures par défaut. Ces chiffres avancent d'un
point par semaine : les demander à chaque cycle ne produirait que du trafic.

Un scénario de rappel tient en trois lignes. Déclenché sur la commande
**Filtre restant**, ou simplement programmé une fois par semaine :

```
SI #[Maison][Aspirateur][Filtre restant]# < 10 ALORS
    notification(Filtre de l'aspirateur à changer : #[Maison][Aspirateur][Filtre restant]# %)
FIN
```

Après avoir changé la pièce, la commande `raz_filter` remet le compteur à zéro,
exactement comme le bouton de l'application. Elle est masquée par défaut : il
faut l'afficher dans l'onglet Commandes, ou l'appeler depuis un scénario.

## L'historique

Trois commandes décrivent le dernier nettoyage : sa date, sa durée et sa surface.
Elles sont relues toutes les demi-heures.

Elles viennent d'un chemin détourné, et cela explique ce qu'on peut en attendre.
**Il n'existe pas d'endpoint d'historique** dans le cloud Dreame : ce que le
serveur archive, ce sont les événements de la propriété de statut du robot, dont
chacun porte une photographie des propriétés du moment. Le plugin relit les vingt
derniers, y retrouve la date, la durée, la surface et le fait que le nettoyage
soit allé à son terme, et n'en publie que les trois valeurs qu'un scénario
utilise réellement. Les vingt enregistrements, eux, sont conservés avec
l'équipement et consultables dans l'onglet **Historique** de sa fiche.

Ce que cet historique ne contient pas, c'est la liste des pièces nettoyées :
l'événement archivé ne la porte pas, et aucune requête ne permet de la
reconstituer après coup.

## Quelques scénarios

**Partir quand la maison se vide.** Déclenché par votre capteur de présence ou le
mode absence :

```
SI #[Maison][Présence][Quelqu'un]# == 0 ALORS
    #[Maison][Aspirateur][Nettoyer des pièces]# (message : Cuisine, Salon, Entrée)
FIN
```

**Être prévenu quand le robot se bloque.** Déclenché sur la commande `en_erreur` :

```
SI #[Maison][Aspirateur][En erreur]# == 1 ALORS
    notification(L'aspirateur s'est arrêté : #[Maison][Aspirateur][Erreur]#)
FIN
```

Utiliser `en_erreur` plutôt que `erreur` évite de recevoir une notification pour
un bac plein, qui remonte, lui, dans `en_alerte`.

**Savoir qu'il a fini.** Déclenché sur la commande `en_activite` :

```
SI #[Maison][Aspirateur][En activité]# == 0 ALORS
    notification(Ménage terminé : #[Maison][Aspirateur][Surface du dernier nettoyage]# m² en #[Maison][Aspirateur][Durée du dernier nettoyage]# min)
FIN
```

**Ne pas le lancer pendant la sieste.** Les commandes d'action s'exécutent aussi
bien depuis un scénario que depuis un clic ; c'est donc au scénario de poser la
condition, par exemple en testant un mode de la maison avant d'envoyer
`demarrer`.

## L'actualisation

Le cœur de Jeedom appelle le plugin toutes les minutes ; le plugin, lui, ne
travaille que si l'intervalle réglé est écoulé.

| Ce qui est relu | À quel rythme |
|---|---|
| État, batterie, erreurs, progression | l'**intervalle** réglé, 120 s par défaut, ramené à 60 s pendant un nettoyage |
| Consommables, compteurs cumulés et réglages secondaires | l'intervalle **entretien et statistiques**, 1800 s par défaut |
| Pièces et carte | l'intervalle **carte**, 900 s par défaut |
| Historique des nettoyages | toutes les demi-heures |

Ces valeurs ne sont pas arbitraires. Chaque lecture d'état est une requête au
cloud, qui la relaie au robot et attend son accusé de réception : c'est lent, et
c'est un service gratuit qu'il serait malvenu de marteler. Descendre sous la
minute ne servirait d'ailleurs à rien, le cœur de Jeedom ne déclenchant pas plus
souvent. À l'inverse, pendant un nettoyage, on veut voir la progression avancer :
le plugin resserre alors son rythme de lui-même.

Une économie discrète mérite d'être mentionnée, parce qu'elle explique que
`en_ligne` et `batterie` soient parfois les seules valeurs à bouger : chaque
cycle commence par demander au compte la liste de ses robots, laquelle porte déjà
leur état de connexion et leur niveau de batterie, pour tous, en un seul appel.
Un robot que le cloud donne pour déconnecté n'est alors pas interrogé — lui
parler ne produirait qu'un délai d'attente par cycle.

La page **Santé** du plugin indique si le compte est renseigné, jusqu'à quand la
session est ouverte, et la date de la dernière lecture réussie de chaque robot.

## Limites connues

**Il n'y a pas de temps restant estimé.** Cette donnée n'existe nulle part dans
le protocole : le robot publie un pourcentage d'avancement, jamais une durée
prévisionnelle. L'application l'estime elle-même. Le plugin préfère ne rien
afficher plutôt qu'un chiffre inventé.

**Il n'y a pas de suivi en temps réel.** Le protocole ne pousse ses changements
d'état que par MQTT, sur le courtier du cloud ; le plugin, lui, interroge à
intervalle régulier. Un changement d'état peut donc mettre jusqu'à deux minutes à
apparaître dans Jeedom, une minute pendant un nettoyage. Pour les usages visés —
lancer, savoir où en est le ménage, être prévenu d'un incident — c'est sans
conséquence, mais il faut le savoir avant d'écrire un scénario qui compte les
secondes.

**Le code 80001 du cloud ne veut pas dire « robot hors ligne ».** C'est le
contresens le plus coûteux de cette API : le serveur abandonne son attente au
bout de quelques secondes, alors que le robot exécute l'ordre et publie son
changement d'état juste après. Le plugin traite ce code comme « pas de réponse
cette fois-ci » et se rabat sur l'**image que le cloud garde du robot**, c'est-à-
dire les dernières valeurs qu'il a reçues. Mieux vaut un état vieux de deux
minutes qu'un trou dans l'historique et des widgets qui clignotent. Une valeur
absente n'écrase jamais la précédente.

**Les pièces nettoyées d'un nettoyage passé ne sont pas récupérables.**
L'événement archivé par le cloud porte la date, la durée, la surface et l'issue
du nettoyage, mais pas la liste des pièces. Pour savoir quelles pièces ont été
faites, il faut l'avoir noté au moment où le nettoyage a été lancé — par exemple
dans une variable de scénario.

**Le nombre de tentatives d'authentification par mot de passe est limité côté
Dreame.** C'est la raison pour laquelle le plugin conserve son jeton de session
et le renouvelle plutôt que de se reconnecter : sans cette précaution, chaque
cycle de relève rouvrirait une session, et le compte finirait par être éconduit
sans que rien ne l'explique. C'est aussi pourquoi un mot de passe erroné arrête
le cycle au lieu de le faire réessayer.

**La liste des pièces dépend de la carte.** Un robot qui n'a jamais cartographié,
ou dont la carte est désactivée dans la configuration, n'aura aucune commande
`room::…` et refusera `nettoyer_pieces`.

**Le réglage par pièce n'est lisible que si le nettoyage personnalisé est
activé.** Lorsque cette option est désactivée dans l'application DreameHome, la
carte ne porte plus, pour chaque pièce, que les valeurs par défaut du robot :
l'onglet Pièces affiche donc partout la même aspiration, la même eau et le même
nombre de passages. Ce n'est pas une lacune du plugin, et rien n'est faussé pour
autant — le robot applique bien ces valeurs. Pour régler une pièce
différemment, il faut activer le nettoyage personnalisé dans l'application.

**L'image de la carte est dessinée à partir de la carte sauvegardée.** La carte
que le robot publie en cours de route ne porte ni les noms de pièces ni leur
voisinage, et ses pixels n'encodent pas les identifiants de la même façon : la
dessiner telle quelle donnerait un plan d'un seul tenant, d'une seule couleur, où
aucune pièce ne se distinguerait. Le plugin décode donc la carte sauvegardée que
la carte courante embarque — sans téléchargement supplémentaire — et lui greffe
la position du robot, qui, elle, n'existe que dans la carte courante. Si cette
carte jointe est illisible, l'image est tout de même dessinée, mais sans
distinction de pièces.

## Choix techniques

**Tout est en PHP natif, sans démon ni dépendance.** Pas de Python, pas de paquet
à installer, pas de processus à surveiller. Le protocole est du HTTPS et du JSON :
cURL et les extensions standard de PHP suffisent. Un plugin sans dépendance est un
plugin qui survit à une mise à jour de Jeedom, à un changement de version de
Python et à une réinstallation. Le seul prix à payer est l'absence d'écoute MQTT,
et donc de temps réel — un arbitrage assumé, décrit plus haut.

**La couche cloud ignore Jeedom.** La classe qui parle au serveur Dreame ne
connaît ni `eqLogic`, ni `cmd`, ni `log::add` : elle reçoit des identifiants,
rend des tableaux PHP et signale ses ennuis par des exceptions. Deux bénéfices
concrets. Elle se rejoue hors ligne sur des réponses enregistrées, ce qui permet
de vérifier le décodage sans compte réel ni accès à Internet. Et le jour où
Dreame changera son API — il le fera — la réparation tiendra dans un seul
fichier, qui ne contient que du protocole.

**Le sondage des capacités, plutôt qu'une liste blanche de modèles.** Maintenir
une table « ce modèle a telle propriété » serait à refaire à chaque sortie de
produit, et fausse dès la première variante régionale. Puisque le robot sait
répondre, on lui demande. Une machine inconnue du plugin fonctionne donc, avec
exactement les commandes qui lui correspondent, et un modèle absent de la table
des noms n'est pas bloqué pour autant : il apparaît sous sa référence d'usine.

**Les noms de commandes ne contiennent aucune apostrophe.** Ce n'est pas un choix
de style : `cmd::setName()`, dans le cœur de Jeedom, retire les apostrophes des
noms de commande sans rien dire. « Niveau d'aspiration » s'affichait donc
« Niveau daspiration », et l'on cherchait longtemps du côté de l'encodage. Onze
libellés ont été reformulés pour s'en passer — « Niveau aspiration », « Régler le
débit eau », « Acquitter le message » — et un contrôle du jeu d'essai refuse
désormais tout nom qui en contiendrait. Les identifiants internes, eux, n'ont pas
bougé : les scénarios existants ne sont pas affectés.

**Le rendu de carte est volontairement sobre.** Le rendu complet de
l'implémentation de référence fait plus de quatre mille lignes et s'appuie sur
des dizaines de mégaoctets de ressources graphiques et sur une bibliothèque
d'imagerie que la GD de PHP n'égale pas. Le transposer produirait beaucoup de
code fragile pour une image qu'on regarde trois secondes sur un dashboard. Le
plugin dessine ce à quoi cette image sert réellement — où en est le robot, et
quelle pièce fait-il — et s'arrête là.

## Sources

Le protocole a été relevé sur trois implémentations libres et indépendantes, dont
les relevés concordent :

| Projet | Licence | Ce qu'il a servi à établir |
|---|---|---|
| [Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) (branche `dev`) | MIT | La table MIoT, les énumérations, la chaîne de décodage des cartes |
| [TA2k/ioBroker.dreame](https://github.com/TA2k/ioBroker.dreame) | MIT | Les routes du cloud et le déroulé de l'authentification |
| [sandraschi/dreame-mcp](https://github.com/sandraschi/dreame-mcp) | MIT | Recoupement des appels et des formats de réponse |

**Aucun code n'en a été recopié.** Ce plugin est une réimplémentation en PHP, à
partir de la documentation du protocole que ces projets constituent de fait.
Qu'ils en soient remerciés : sans eux, rien de tout ceci n'aurait été possible.

Ce plugin n'est pas affilié à Dreame. Il est distribué sous licence AGPL v3.
