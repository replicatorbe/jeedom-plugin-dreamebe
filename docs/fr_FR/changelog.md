# Changelog

## 0.1 — 18/09/2026

Première version.

Elle relie Jeedom aux aspirateurs robots Dreame récents par le cloud DreameHome,
sans démon, sans dépendance et sans passerelle locale. La cible est le L40 Ultra
et ses variantes ; les anciens modèles rattachés à Mi Home relèvent d'un autre
protocole et ne sont pas gérés.

**Le compte et les robots**

- Un seul compte DreameHome à renseigner : adresse, mot de passe et région. Le
  mot de passe n'est présenté qu'à la première connexion, ensuite le plugin
  renouvelle un jeton de session — le nombre de tentatives d'authentification est
  limité côté Dreame.
- Le bouton **Tester le compte** ouvre une session et affiche les robots trouvés
  sans rien créer dans Jeedom ; **Découvrir les robots** crée un équipement par
  robot, y compris ceux qui vous ont été partagés.
- Plusieurs robots sur un compte donnent autant d'équipements indépendants.
- Si le serveur signale que le compte vit dans une autre région, le plugin
  corrige le réglage tout seul.

**Ce que le robot sait faire**

- Au premier contact, le plugin interroge le robot sur toutes les propriétés du
  dictionnaire et ne crée que les commandes auxquelles il a répondu. Il n'existe
  aucune table de propriétés par modèle : le même dictionnaire couvre toute la
  gamme, et c'est la machine qui tranche à l'exécution.
- Un robot à base de lavage reçoit ainsi le réglage d'humidité, le mode de
  nettoyage et les ordres de lavage et de séchage de la serpillière ; un robot
  sans base reçoit un niveau d'eau. Aucune machine ne se voit affubler d'un
  réglage qu'elle n'a pas.

**Piloter**

- Démarrer, mettre en pause, reprendre, arrêter, renvoyer à la station,
  localiser, acquitter une alerte, vider le bac.
- Régler l'aspiration, et selon la machine l'humidité et le mode, ou le niveau
  d'eau.
- Nettoyage par pièce, de deux façons : une commande **Nettoyer : *pièce*** par
  pièce de la carte, et une commande générique qui accepte des identifiants ou
  des noms séparés par des virgules.
- « Nettoyer des pièces » accepte deux paramètres après la liste, séparés par une
  barre verticale puisque les noms de pièces contiennent déjà des virgules :
  `Cuisine, Salon | 2` y passe deux fois, `Cuisine | 2 | 3` deux fois en Turbo.
- Nettoyage par zone, en millimètres dans le repère de la carte, avec un nombre
  de passages facultatif. Une zone trop petite est refusée avec une explication,
  là où le robot la refuserait sans rien dire.
- Six réglages secondaires quand le robot les expose : volume des annonces (de 0
  à 100), « Ne pas déranger », température de l'eau, niveau de lavage, gestion
  des tapis et détergent automatique. Chacun met à jour l'information qui lui
  fait face sans attendre le prochain cycle lent.

**Suivre**

- État, statut, batterie, charge, erreurs. Les avertissements de la station sont
  distingués des pannes du robot : un bac plein ne déclenche pas la même
  commande qu'une roue bloquée.
- Durée, surface et progression du nettoyage en cours.
- Pourcentages d'usure des consommables réellement présents, avec une commande de
  remise à zéro par consommable.
- Date, durée et surface du dernier nettoyage, reconstituées depuis les
  événements que le cloud archive — il n'existe pas d'endpoint d'historique.
- Quinze propriétés secondaires quand le robot y répond : progression du séchage,
  type de tâche, localisation, disponibilité et état de l'auto-vidage, volume des
  annonces, « Ne pas déranger », température de l'eau, niveau de lavage, durée de
  séchage, gestion des tapis, détergent automatique, eau chaude, état du
  détergent et date du premier nettoyage.
- Une commande « Pièces » qui rend la liste des noms de pièces séparés par des
  virgules, pour qu'un scénario les énumère sans les écrire en dur.
- Des types génériques Jeedom sur les commandes principales — batterie, batterie
  en charge, vitesse de ventilateur et son état, retour à la base et état de la
  base — que les widgets, les résumés d'objet et les assistants vocaux savent
  reconnaître.
- Un tableau de bord qui va à l'essentiel : dix-neuf commandes visibles sur un
  robot à six pièces — état, batterie, erreur, station, carte, les cinq ordres du
  quotidien, les trois réglages qu'on change avant de lancer un nettoyage, et une
  commande par pièce. Le reste est créé masqué, sans rien perdre : ces commandes
  restent tenues à jour et utilisables dans les scénarios. Chaque tuile porte son
  nom, parce qu'une icône sans libellé ne dit pas qu'« Arrêter » interrompt le
  nettoyage sur place quand « Retourner à la station » renvoie le robot se
  recharger.
- Une page Santé qui dit si le compte est renseigné, jusqu'à quand la session est
  ouverte et quand chaque robot a été lu pour la dernière fois.

**La carte**

- Décodage complet des cartes du robot : c'est le seul endroit où figurent les
  noms des pièces, et donc la condition du nettoyage par pièce.
- Rendu d'une image PNG volontairement sobre — sol par pièce, murs, robot et
  station — mise en cache sur le disque et servie aux seuls utilisateurs
  authentifiés de Jeedom. Le dossier des cartes est fermé par `Require all
  denied` : l'ancienne règle `Deny from all` cédait devant celle du `.htaccess`
  racine de Jeedom, qui autorise tous les PNG, et laissait les plans lisibles
  sans session. La carte d'un robot est effacée avec son équipement.
- Position et orientation du robot dans le repère de la carte.

**Sous le capot**

- Tout est en PHP natif : aucune dépendance, aucun démon, aucun processus à
  surveiller.
- La couche cloud ignore Jeedom et se rejoue hors ligne sur des réponses
  enregistrées, ce qui permet de vérifier le décodage sans compte réel.
- Un défaut d'accusé de réception du robot n'est pas traité comme une
  déconnexion : le plugin se rabat sur l'image que le cloud garde de la machine,
  et une valeur absente n'écrase jamais la précédente.
- Jetons, mots de passe et en-têtes d'autorisation sont masqués dans le journal.
- Aucun nom de commande ne contient d'apostrophe : `cmd::setName()` les retire
  sans prévenir, ce qui affichait « Niveau daspiration ». Onze libellés ont été
  reformulés, et le jeu d'essai refuse désormais tout nom qui en contiendrait.
  Les identifiants internes n'ont pas changé.

Protocole relevé sur Tasshack/dreame-vacuum, TA2k/ioBroker.dreame et
sandraschi/dreame-mcp, tous trois sous licence MIT. Aucun code n'en a été
recopié.
