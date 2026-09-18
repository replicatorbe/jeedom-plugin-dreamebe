# Plugin Jeedom — Dreame

Pilotage des aspirateurs robots Dreame récents depuis Jeedom, par le cloud
**DreameHome**. Un compte à renseigner, et chaque robot devient un équipement
Jeedom : état, batterie, erreurs, ordres de nettoyage, nettoyage par pièce et par
zone, réglages d'aspiration et d'eau, usure des consommables, carte du logement.

Tout est en PHP natif : aucune dépendance à installer, aucun démon à surveiller.

## Pour quels robots

La cible est le **Dreame L40 Ultra** et ses variantes, dont la variante AE, sur
laquelle le protocole a été relevé. Les autres modèles récents de la gamme
fonctionnent dans la mesure où ils répondent au même dictionnaire de propriétés :
le plugin le vérifie robot par robot plutôt que de le supposer.

Le plugin passe par le compte de l'application **DreameHome**. Les anciens Dreame
rattachés à **Mi Home** parlent le protocole MiIO de Xiaomi, qui n'a rien à voir :
un compte Mi Home sera refusé à la connexion.

## Ce que le plugin fait

- **Découverte** des robots du compte, y compris ceux qui vous ont été partagés.
  Plusieurs robots donnent autant d'équipements Jeedom indépendants.
- **Sondage des capacités** : le plugin demande au robot ce qu'il sait faire et ne
  crée que les commandes correspondantes. Il n'existe aucune table de propriétés
  par modèle — le même dictionnaire couvre toute la gamme, et c'est la machine
  qui tranche à l'exécution.
- **Ordres** : démarrer, pause, reprendre, arrêter, retour à la station,
  localiser, acquitter une alerte, vider le bac, laver et sécher la serpillière.
- **Nettoyage par pièce**, soit par une commande dédiée à chaque pièce, soit par
  une commande générique qui accepte des identifiants ou des noms de pièces.
- **Nettoyage par zone**, en millimètres dans le repère de la carte.
- **Suivi** : état, statut, batterie, erreurs — avec les avertissements de la
  station distingués des pannes du robot — durée, surface et progression du
  nettoyage en cours, usure des consommables, date, durée et surface du dernier
  nettoyage.
- **Carte** : décodée pour en tirer les noms des pièces, ce qui est la condition
  du nettoyage par pièce, et rendue en une image PNG sobre — sol par pièce, murs,
  robot et station — servie aux seuls utilisateurs authentifiés de Jeedom.

## Installation

1. Installer le plugin depuis le Market, puis l'activer.
2. Ouvrir sa configuration et renseigner l'adresse, le mot de passe et la région
   du compte DreameHome. Attention à la région : un compte n'existe que dans une
   seule, et se tromper donne « identifiants refusés », pas « mauvaise région ».
3. Cliquer sur **Tester le compte**, puis sur **Découvrir les robots**.

La documentation complète se trouve dans [`docs/fr_FR/index.md`](docs/fr_FR/index.md)
([English](docs/en_US/index.md)).

## Sources

Le protocole a été relevé sur trois implémentations libres et indépendantes, dont
les relevés concordent :

- [Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) (branche
  `dev`) — licence MIT ;
- [TA2k/ioBroker.dreame](https://github.com/TA2k/ioBroker.dreame) — licence MIT ;
- [sandraschi/dreame-mcp](https://github.com/sandraschi/dreame-mcp) — licence MIT.

Aucun code n'en a été recopié : ce plugin est une réimplémentation en PHP, à
partir de la documentation du protocole que ces projets constituent de fait.
Qu'ils en soient remerciés.

Ce plugin n'est pas affilié à Dreame.

## Licence

AGPL v3.
