# Jeux d'essai

`device-list.json` reprend la forme exacte d'une réponse `device/listV2` du cloud
DreameHome, telle qu'elle a été capturée par les implémentations libres du
protocole. Les identifiants, les adresses et les noms ont été remplacés ; la
structure, les clés et leurs types sont conservés à l'identique — y compris les
deux particularités qui font échouer une lecture naïve : le champ `property` est
une chaîne contenant du JSON, et `bindDomain` porte dans son premier label le
préfixe de la route d'envoi de commande.

Trois fiches y figurent volontairement : un robot en ligne avec un nom
personnalisé, un robot partagé et hors ligne dont le nom doit retomber sur le
libellé du modèle, et une fiche sans `did` qui doit être écartée sans faire
échouer la lecture des deux autres.

`history.json` reprend la forme des événements archivés par le cloud. Les quatre
entrées couvrent les cas rencontrés : horodatage en secondes, horodatage en
millisecondes, événement sans date propre qui doit retomber sur celle de
l'archive, et charge utile illisible qui doit être ignorée sans rien casser.

La carte, elle, n'est pas un fichier : `tests/run.php` la fabrique, chiffre et
comprime comme le fait un robot, puis la relit. Une carte réelle est le plan d'un
logement, et n'a pas sa place dans un dépôt public.
