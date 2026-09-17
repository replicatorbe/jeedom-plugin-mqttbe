# Changelog

## 0.1 — 17 septembre 2026

Première version. Elle apporte la liaison avec le broker, la découverte
automatique des Shelly de première génération, et la création manuelle
d'équipements pour tout le reste.

### La liaison avec le broker

- Un démon autonome se connecte au broker MQTT, tient la connexion et se
  reconnecte tout seul quand elle tombe. Il ne demande aucune dépendance à
  installer : ni Python, ni Node, rien à télécharger.
- La configuration ne réclame que l'adresse du broker. Le port, le chiffrement
  TLS, l'authentification et les topics ignorés sont réglables, mais leurs
  valeurs par défaut conviennent.
- Un bouton **Tester la connexion** ouvre une vraie connexion vers le broker et
  rend son verdict avec ce qui est à l'écran, sans obliger à enregistrer une
  configuration dont on doute encore et sans attendre le démarrage du démon.
- La page du plugin montre deux états distincts, celui du démon et celui du
  broker, mis à jour sans recharger la page. Un démon en marche et un broker
  déconnecté désignent une adresse, un port ou des identifiants à revoir, et le
  motif du refus s'affiche en survolant la pastille.
- L'identifiant client présenté au broker est engendré une fois à l'installation
  puis mémorisé : deux clients de même identifiant se déconnecteraient l'un
  l'autre en boucle.
- Une page de santé répond aux trois questions qu'on se pose quand rien ne
  remonte : le broker est-il configuré, le démon tourne-t-il, a-t-il parlé
  récemment.

### Les équipements et leurs commandes

- Un équipement se crée à la main, avec ses informations et ses actions : une
  information lit un topic, une action publie sur un topic.
- Quand un appareil publie du JSON, un chemin par points — `emeter.0.power` —
  désigne la valeur à extraire du message.
- Des réglages fins, repliés et facultatifs, couvrent les cas qui sortent de
  l'ordinaire : correspondance entre un message et une valeur, échelle,
  décalage, arrondi, intervalle minimum entre deux enregistrements, et rappel
  d'une valeur inchangée pour qu'un capteur stable ne finisse pas par ressembler
  à une panne.
- Les valeurs reçues sont regroupées avant d'être remises à Jeedom : un broker
  bavard produit des dizaines de messages par seconde, et les traiter un par un
  coûterait une requête à chacun.

### La découverte des Shelly Gen1

- Les Shelly de première génération présents sur le broker sont découverts et
  créés automatiquement, avec leurs commandes, leurs unités et leurs types
  génériques. Aucun topic à saisir.
- Sont reconnus : l'état de chaque relais et les trois actions qui le pilotent,
  la puissance et la consommation quand l'appareil dispose d'un vrai wattmètre,
  les mesures des compteurs d'énergie, les entrées, la température interne, les
  sondes externes de température et d'humidité, et l'état de connexion.
- La découverte interroge chaque appareil au lieu de recopier une fiche : un
  Shelly 1 équipé de deux sondes est découvert avec ses deux sondes, alors qu'un
  Shelly 1 nu, qui porte pourtant le même code de modèle, l'est sans elles. Un
  modèle inconnu du plugin est donc découvert aussi bien qu'un modèle connu.
- Un appareil sans wattmètre n'obtient pas de commande de puissance, même s'il
  publie un compteur de façade : une commande figée à zéro passe pour une panne.
- Un bouton **Relancer la découverte** redemande à tout le parc de se présenter.
  Il est indispensable sur une installation déjà en service : un appareil
  connecté depuis des semaines ne s'annonce plus de lui-même et resterait
  invisible alors qu'il publie ses valeurs en continu.
- Une re-découverte ne crée jamais de doublon, et ne réécrit jamais vos
  retouches : un nom changé, une unité corrigée, un affichage masqué restent en
  place. Seule la plomberie — topic écouté et chemin de la valeur — est remise à
  jour.
- La création automatique peut être désactivée : la découverte reconnaît alors
  les appareils et les signale sur la page du plugin sans rien créer, le temps de
  regarder ce qu'elle trouve.
- Les équipements découverts ne reçoivent pas d'objet parent : le rangement dans
  les pièces vous appartient, et il faut le faire pour les voir sur le Dashboard.

### Ce qui n'est pas encore là

Les Shelly Gen2, Gen3 et Gen4 — les gammes Plus, Pro et Mini — ne sont pas encore
découverts : leur découverte repose sur un dialogue avec l'appareil, qui demande
du matériel sous tension pour être validée. Tasmota, Zigbee2MQTT et le protocole
d'annonce de Home Assistant viendront ensuite. En attendant, ces appareils
s'utilisent dans Jeedom par la création manuelle.
