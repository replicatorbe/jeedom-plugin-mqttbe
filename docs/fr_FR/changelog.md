# Changelog

## 0.6 — 20 septembre 2026

### La file d'adoption ne garde plus les passants

Une passerelle Bluetooth voit passer le téléphone d'un visiteur, la montre d'un
voisin, l'autoradio d'une voiture arrêtée au feu. Ces appareils n'ont rien à
faire dans Jeedom, et la file d'adoption est là pour qu'on les écarte — encore
faut-il qu'elle se vide d'elle-même.

- **Un candidat qu'on ne voit plus s'efface en quelques heures** au lieu d'une
  semaine. Le délai existait déjà, mais il ne pouvait rien trier : la date qu'il
  comparait était celle du dernier CHANGEMENT du candidat, pas de sa dernière
  apparition — et un appareil bien présent ne change pas. Sur une installation
  réelle, la file était pleine de cinquante candidats vus pour la dernière fois
  soixante-six heures plus tôt, sans une place pour un appareil du jour.
- **Le démon dit maintenant ce qu'il voit encore.** Chaque candidat toujours à
  portée donne signe de vie par quart d'heure, qu'il ait changé ou non. C'est ce
  qui donne enfin un sens à la date de dernière vue, et ce qui permet au délai
  d'être court. Un appareil adopté, lui, n'a rien à prouver : son équipement
  existe.
- **La file reste consultable après l'arrêt du démon** : ce qu'elle contient
  demeure adoptable sans lui, et six heures laissent le temps de décider.

## 0.5 — 20 septembre 2026

### Une passerelle qui ne se présente pas est désormais nommée

Une passerelle Bluetooth est reconnue par son adresse matérielle, qu'elle publie
sur son topic d'identité — et elle seule. Sans ce message, aucun équipement de
passerelle ne peut être créé : c'est un choix, mieux vaut une passerelle absente
de la liste qu'un équipement fantôme qui réapparaîtrait sous un autre nom à
chaque redémarrage.

- **Mais le plugin le dit maintenant.** Une passerelle qui publie ses trames
  Bluetooth depuis une demi-heure sans s'être présentée laisse un avertissement
  au journal, avec son préfixe et le topic qu'on attend d'elle. Sans cela, on
  voyait ses balises remonter, on cherchait la passerelle dans la liste, on ne
  l'y trouvait pas — et rien nulle part ne disait pourquoi. La demi-heure n'est
  pas de la prudence gratuite : l'intervalle entre deux annonces se règle sur la
  passerelle, et un délai plus court accusait une passerelle parfaitement
  vivante dont l'annonce venait simplement plus tard. L'avertissement se répète
  au plus une fois par heure.

Le cas se rencontre avec les passerelles émulées par script : un appareil qui ne
publie son identité qu'au démarrage l'aura tenté avant d'être connecté au
broker, et n'essaiera plus jamais. Une passerelle OpenMQTTGateway, elle, republie
ce message périodiquement.

## 0.4 — 20 septembre 2026

### Un appareil peut avoir deux rôles

Un Shelly qui fait tourner un script de passerelle Bluetooth est deux choses à
la fois : un relais, et une passerelle. Les deux rôles publient sur des topics
différents, mais ils portent la même adresse matérielle — et c'est par elle que
le plugin reconnaît un appareil déjà connu.

- **Les deux rôles donnent désormais deux équipements.** Ils se disputaient le
  même : le journal en garde dix reprises, « repris par l'adapter omg », puis
  « repris par l'adapter shelly.gen2 » deux secondes plus tard. À chaque
  bascule, celui qui prenait la main ne connaissait pas les commandes de
  l'autre et les éteignait comme disparues — quatorze sur dix-sept. Une
  adresse ou un topic ne fait plus reconnaître un appareil au-delà de sa
  propre famille ; son identifiant, lui, le fait toujours, et un appareil qui
  change de chemin reste reconnu comme avant.
- **Les commandes éteintes par ces bascules se rallument**, à la mise à jour du
  plugin. Sur ces équipements-là, « le canal a disparu » était faux : le canal
  existait, c'est l'autre adapter qui ne le connaissait pas. Une commande que
  vous avez masquée vous-même n'est pas touchée.

## 0.3 — 20 septembre 2026

Cette version corrige un défaut qui remplissait Jeedom tout seul : sur une
installation réelle, cent quatre-vingt-dix équipements créés en trois jours,
trois par heure, jour et nuit, aucun n'ayant jamais reçu la moindre valeur.

### Les balises Bluetooth qui n'en étaient pas

- **Un format d'annonce n'est plus pris pour un appareil.** Une passerelle
  OpenMQTTGateway sait lire des formats standards — iBeacon et ses semblables —
  qu'émettent les téléphones, les montres et les autoradios. Elle les nomme tout
  en disant qu'elle ne sait pas de quel appareil il s'agit : le plugin lisait le
  nom et ignorait l'aveu. Il faut désormais que la passerelle ait reconnu une
  marque — et une mesure ne rattrape pas une marque générique, car le décodage
  d'un format d'annonce produit des valeurs de fantaisie : 10,9 V de tension
  pour une balise. Ces balises-là sont proposées dans la file d'adoption plutôt
  que créées : celui qui reconnaît la sienne l'adopte d'un clic. Le jour où la
  passerelle reconnaît vraiment l'appareil, l'équipement se crée tout seul,
  comme avant.
- **Une adresse Bluetooth qui tourne ne fabrique plus un équipement par
  rotation.** Un téléphone change d'adresse toutes les vingt minutes environ :
  c'était un équipement neuf à chaque fois, muet dès sa création puisque
  l'adresse avait déjà changé. Une adresse aléatoire doit maintenant avoir duré
  une heure avant d'exister pour Jeedom. Un traceur, dont l'adresse ne tourne
  pas, est donc créé au bout d'une heure — sans que vous ayez rien à faire ; un
  téléphone ne franchit jamais ce seuil.
- **Un bouton pour nettoyer ce qui a déjà été créé**, dans la configuration du
  plugin. Le premier clic n'écrit rien : il affiche la liste de ce qui partirait,
  avec le nombre de commandes et de relevés concernés. Sont épargnés tout ce qui
  n'est pas une balise Bluetooth, tout ce que la passerelle a su nommer, tout ce
  qui a reçu quoi que ce soit dans les dernières vingt-quatre heures, tout ce que
  vous avez renommé ou complété d'une commande, et tout ce qui est utilisé dans
  un scénario, une vue ou un design.

Ce défaut menaçait plus que la lisibilité : le plafond d'équipements découverts
était sur le point d'être atteint, et au-delà la découverte cesse de créer — y
compris pour un Shelly.

## 0.2 — 17 septembre 2026

Cette version apporte la découverte des Shelly de deuxième génération et
suivantes — les gammes Plus, Pro et Mini, en Gen2, Gen3 et Gen4.

### Les Shelly Gen2, Gen3 et Gen4

- **Tout passe par le broker, et rien d'autre.** Un Shelly moderne ne publie pas
  ses valeurs sur des topics séparés comme le fait la génération 1 : il tient une
  conversation. Le plugin lui demande son identité, puis la liste de ses
  composants, et il répond — le tout en MQTT, sans qu'aucune requête ne parte
  vers l'appareil par un autre chemin.
- **Rien à régler sur l'appareil**, hormis activer MQTT. Les réglages dont la
  découverte dépend sont actifs en sortie d'usine, et le plugin n'en modifie
  aucun. D'autres intégrations vont retourner le réglage « notifications de
  statut » sur l'appareil ; celle-ci s'y refuse, et fait sans.
- **Un appareil est découvert quel que soit son préfixe de topic**, y compris un
  préfixe personnalisé à plusieurs niveaux comme `maison/salon/prise`. Le plugin
  se signale à tout le parc, écoute les appareils qui se présentent d'eux-mêmes,
  et reconnaît aussi ceux qu'il n'a fait qu'entendre passer.
- **Le nom que vous avez donné à l'appareil est repris**, ainsi que celui de
  chaque sortie quand il y en a plusieurs — « Prise bureau » plutôt que
  « Sortie 1 ». Contrairement à la génération 1, aucune requête n'est nécessaire
  pour l'obtenir : l'appareil le publie lui-même.
- **Les capteurs sur pile ne sont jamais interrogés.** Ils dorment ; on attend
  qu'ils poussent leur état complet, ce qu'ils font à chaque réveil. Ils ne
  reçoivent pas non plus d'indicateur « connecté » : leur liaison est coupée à
  chaque sommeil, et l'afficher les déclarerait en panne presque en permanence.
- **Un volet est un volet.** Un Shelly 2PM configuré en mode volet produit un
  volet avec sa position, ses trois boutons et son curseur, et pas deux
  interrupteurs qui ne commanderaient rien. Le curseur n'apparaît que si
  l'appareil est calibré et sait donc s'y rendre.
- **Les énergies sont converties.** Un Shelly moderne compte en watt-heures ; la
  commande annonce des kWh et divise. Sans cela, un compteur afficherait mille
  fois sa vraie valeur.
- **Les micrologiciels anciens sont pris en charge** : ceux qui ne savent pas
  énumérer leurs composants sont interrogés autrement, et découverts tout aussi
  complètement.
- **Composants reconnus** : sorties de relais, volets, éclairages (blanc,
  couleur, voie blanche, température de couleur), entrées dans leurs quatre
  modes, wattmètres, compteurs mono et triphasés avec leurs bases d'énergie,
  sondes de température et d'humidité, luminosité, voltmètres, alimentation sur
  pile, détecteurs de fumée et de fuite d'eau, zones de présence, composants
  virtuels créés par l'utilisateur, et l'état du boîtier lui-même.
- **Un parc mixte ne produit aucun doublon.** Un appareil porte la même identité
  quelle que soit la génération qui l'a découvert.

### Ce qui reste à faire, et qui est dit franchement

Cette découverte est écrite d'après la documentation officielle du constructeur
et vérifiée sur des conversations reconstituées : aucun appareil Gen2 n'était
disponible au moment de l'écrire. Les contrôles établissent que le plugin fait
ce qu'on a voulu, pas qu'un Shelly réel répond ainsi. La validation sur matériel
reste à faire.

Deux limites connues, décrites dans la documentation : les valeurs ne sont pas
rafraîchies au redémarrage de Jeedom tant que l'appareil n'a rien à annoncer, et
les appuis sur les boutons ne sont pas séparés entrée par entrée.

### Corrections

- **Un Shelly moderne n'est plus pris pour un Shelly de première génération.**
  Les deux générations partagent un topic d'annonce, et l'adapter Gen1 acceptait
  ce que les Gen2 y publiaient : il en faisait un équipement aux topics
  inexistants, muet pour toujours, qui occupait de surcroît la place du vrai.
- Le vocabulaire des capacités gagne l'état binaire générique, la puissance
  apparente, la fréquence, la voie blanche d'un éclairage et l'écriture d'un
  texte.
- Une clé de configuration déclarée deux fois a été nettoyée.

## 0.1 — 17 septembre 2026

Première version. Elle apporte la liaison avec le broker, la découverte
automatique des Shelly de première génération et des passerelles
OpenMQTTGateway — avec les capteurs et traceurs Bluetooth qu'elles entendent —,
une file d'adoption pour ce que la découverte voit sans le reconnaître, et la
création manuelle d'équipements pour tout le reste.

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
  les appareils sans rien créer, et les dépose dans la file d'adoption, le temps
  de regarder ce qu'elle trouve.
- Les équipements découverts ne reçoivent pas d'objet parent : le rangement dans
  les pièces vous appartient, et il faut le faire pour les voir sur le Dashboard.

### Les passerelles OpenMQTTGateway et le Bluetooth

- Les passerelles OpenMQTTGateway sont découvertes et créées, avec leur version,
  leur adresse, leur mémoire libre, leur temps de fonctionnement, le nombre
  d'appareils entendus, et deux actions : redémarrer la passerelle, couper sa
  radio Bluetooth.
- Les capteurs Bluetooth que la passerelle décode deviennent chacun un
  équipement, avec les mesures qu'ils publient réellement : température,
  humidité, pression, niveau de pile.
- Un traceur, qui ne mesure rien, reçoit la puissance du signal telle que chaque
  passerelle l'entend, la passerelle qui l'entend le mieux — c'est-à-dire, en
  pratique, la pièce où il se trouve — et sa présence, qui passe à absent quand
  plus aucune passerelle ne l'a entendu depuis un délai réglable.
- Un même appareil entendu par plusieurs passerelles reste un seul équipement :
  les passerelles sont des points d'écoute, pas des propriétaires.
- Cette lecture ne dépend d'aucun logiciel tiers. OpenMQTTGateway publie sur ses
  propres topics, et c'est eux que le plugin lit : un Home Assistant peut tourner
  à côté, ou pas du tout, cela ne change rien.

### La file d'adoption

- Ce que la découverte voit sans le reconnaître n'est ni créé ni jeté : il est
  mis en attente, et la page du plugin propose de l'examiner. C'est ce qui permet
  d'écouter une passerelle Bluetooth sans que le téléphone d'un visiteur devienne
  un équipement.
- Chaque candidat est présenté avec ce qui permet de trancher : le type
  d'adresse — une adresse aléatoire change toutes les quinze minutes, une adresse
  publique jamais —, depuis combien de temps on le voit, combien de passerelles
  l'entendent, et le nombre de commandes que la création produirait.
- Écarter un appareil est réversible : les appareils écartés sont listés sous
  leur nom, avec la date du refus, et un bouton les remet dans le circuit.
- La file s'entretient seule : un candidat qui ne s'est plus manifesté depuis une
  semaine en sort, et quand elle déborde, ce sont les passants qui partent — un
  appareil vu depuis longtemps, avec une adresse stable, garde sa place.
- Un plafond d'équipements issus de la découverte évite qu'un appareil bavard,
  ou malveillant, remplisse Jeedom : au-delà, les appareils sont proposés au lieu
  d'être créés.

### Le nom et l'adresse des appareils

- Un équipement découvert ne s'appelle plus seulement par son identifiant
  technique : le plugin lit sur l'appareil le nom que son propriétaire lui a
  donné et l'ajoute — « Shelly 1 55670C chaudiere ». Si l'appareil ne répond pas,
  demande une authentification ou n'a pas de nom, le nom technique reste seul.
- Un nom changé à la main n'est plus jamais réécrit par une découverte
  ultérieure.
- Cette lecture peut être désactivée pour que Jeedom ne sollicite rien sur le
  réseau.
- L'adresse de chaque appareil découvert s'affiche, cliquable, sur sa vignette et
  dans son panneau : c'est le moyen le plus court de savoir lequel on tient. Elle
  est tenue à jour à chaque découverte, pour qu'un nouveau bail DHCP ne laisse pas
  un lien mort.

### Ce qui n'est pas encore là

Les Shelly Gen2, Gen3 et Gen4 — les gammes Plus, Pro et Mini — ne sont pas encore
découverts : leur découverte repose sur un dialogue avec l'appareil, qui demande
du matériel sous tension pour être validée. Tasmota, Zigbee2MQTT et le protocole
d'annonce de Home Assistant viendront ensuite. En attendant, ces appareils
s'utilisent dans Jeedom par la création manuelle.
