# Plugin MQTT BE

Ce plugin relie Jeedom à un broker MQTT et découvre tout seul les équipements
qui s'y annoncent. Vous n'avez rien à décrire : ni topic à recopier, ni modèle
d'équipement à écrire. Le plugin écoute ce qui circule réellement sur le broker
et en déduit les équipements, leurs commandes, leurs unités et leurs types
génériques — ceux qui permettent à Jeedom de savoir qu'une valeur est une
température, une puissance ou l'état d'une prise.

## Ce que le plugin sait faire aujourd'hui

**Les Shelly de première génération sont découverts et créés automatiquement.**
Vous saisissez l'adresse de votre broker, vous démarrez le démon, et les Shelly
présents apparaissent seuls dans Jeedom, avec l'ensemble de leurs commandes :
l'état de chaque relais et les trois actions qui le pilotent (allumer, éteindre,
basculer), la puissance et la consommation quand l'appareil dispose d'un vrai
wattmètre, les entrées, la température interne, les sondes externes de
température et d'humidité, et l'état de connexion. Sur le parc qui a servi à la
mise au point, 22 appareils ont produit 198 commandes sans une seule saisie.

Le plugin ne se fie pas à une fiche technique recopiée dans un catalogue : il
demande à chaque appareil de se présenter, puis lit ce que celui-ci publie
vraiment. C'est pourquoi un Shelly 1 équipé de deux sondes de température est
découvert avec ses deux sondes, alors qu'un Shelly 1 nu, qui porte pourtant
exactement le même code de modèle, est découvert sans elles.

**Tout le reste se crée à la main**, et le plugin s'y prête : n'importe quel
appareil qui publie sur MQTT peut devenir un équipement Jeedom complet, à
condition d'indiquer soi-même les topics. La marche à suivre est décrite plus
bas.

## Ce qui n'est pas encore là

Autant le dire tout de suite, pour que personne n'attende en vain des
équipements qui ne viendront pas :

- **les Shelly Gen2, Gen3 et Gen4** (les gammes Plus, Pro et Mini) ne seront pas
  découverts. C'est prévu, l'essentiel du travail est fait autour, mais la
  découverte de ces appareils repose sur un dialogue avec eux, et il faut du
  matériel sous tension pour la valider : tant que ce n'est pas vérifié en
  conditions réelles, elle n'est pas livrée ;
- **Tasmota**, **Zigbee2MQTT** et le protocole d'annonce de **Home Assistant**
  viendront ensuite.

D'ici là, ces appareils restent utilisables dans Jeedom par la création
manuelle : ils ne se créeront simplement pas tout seuls.

## Le premier quart d'heure

1. **Installez le plugin** depuis le Market, puis activez-le. Il n'y a aucune
   dépendance à installer : ni Python, ni Node, rien à télécharger. La page du
   plugin s'ouvre immédiatement.
2. **Ouvrez sa configuration** — le bouton en forme de roue dentée, en haut de
   la page du plugin.
3. **Saisissez l'adresse du broker** dans le champ *Adresse* : l'adresse IP ou
   le nom de la machine qui fait tourner Mosquitto, par exemple `192.168.1.10`.
   C'est le seul réglage indispensable. Le port est déjà rempli avec 1883, le
   port d'usage d'un broker en clair. Si votre broker demande un identifiant et
   un mot de passe, renseignez-les ; beaucoup de brokers domestiques acceptent
   les connexions anonymes, et il n'y a alors rien à saisir.
4. **Cliquez sur « Tester la connexion »**, en bas de la page. Le bouton ouvre
   une vraie connexion vers le broker, avec ce qui est actuellement à l'écran —
   vous pouvez donc tester avant d'enregistrer. Une adresse injoignable met une
   quinzaine de secondes à échouer : c'est le délai du réseau, pas un blocage du
   plugin.
5. **Sauvegardez**, puis **démarrez le démon**. Le bloc de gestion du démon se
   trouve en haut de cette même page de configuration ; s'il vous échappe, le
   démon est aussi relancé tout seul dans la minute qui suit par la tâche de
   surveillance du plugin.
6. **Revenez sur la page du plugin.** La pastille *Démon* passe à « En marche »,
   la pastille *Broker* à « Connecté », et les Shelly Gen1 apparaissent dans les
   secondes qui suivent — au démarrage, le plugin demande à toute la génération 1
   de se présenter. Les équipements se créent sans qu'il soit nécessaire de
   recharger la page ; un bandeau vert vous dit combien en sont arrivés.

Si rien n'apparaît alors que vos Shelly fonctionnent, c'est presque toujours le
même cas : un appareil connecté depuis des semaines ne s'annonce plus
spontanément. Le bouton **« Relancer la découverte »**, dans la configuration du
plugin, redemande à tout le parc de se présenter. C'est le geste à faire après
avoir installé le plugin sur une installation déjà en service.

## Deux choses à savoir avant de démarrer le démon

### La découverte crée les équipements toute seule

Dès le premier démarrage, un appareil reconnu devient un équipement Jeedom sans
rien demander. Sur un parc fourni, cela fait beaucoup d'équipements d'un coup.

Si vous préférez regarder avant de laisser faire, **décochez « Créer les
équipements »** dans la configuration, section *Découverte*, avant de démarrer le
démon. La découverte continue de reconnaître les appareils, mais elle ne crée
rien : la page du plugin affiche alors combien d'appareils ont été vus et
lesquels. Vous recochez la case quand le résultat vous convient.

La case voisine, **« Découverte automatique »**, est plus radicale : décochée,
plus rien n'est reconnu du tout, et seules les commandes que vous avez saisies à
la main restent écoutées.

### Les équipements créés n'ont pas d'objet parent

**C'est le point qui surprend le plus.** Le plugin ne range jamais un équipement
dans une pièce : le découpage de la maison vous appartient, et il serait présomptueux
de deviner qu'un Shelly s'appelant « Salon » va dans l'objet « Salon ».

Conséquence : **un équipement fraîchement découvert n'apparaît pas sur le
Dashboard.** Il existe, il reçoit ses valeurs, ses commandes fonctionnent — mais
Jeedom n'affiche sur le Dashboard que ce qui est rattaché à un objet. Tant que
vous ne l'avez pas rangé, vous le trouverez uniquement sur la page du plugin.

Pour le voir : ouvrez l'équipement, choisissez un **objet parent** dans la liste,
et sauvegardez. À faire une fois par équipement ; la découverte ne touchera plus
jamais à ce choix.

## La page du plugin

En haut, quatre vignettes : l'ajout d'un équipement, l'état du démon, l'état du
broker, et l'accès à la configuration.

Les deux pastilles d'état ne disent pas la même chose. **Démon** indique si le
processus qui parle au broker tourne. **Broker** indique si ce processus est
effectivement connecté. Un démon en marche avec un broker déconnecté est le cas
courant d'une mauvaise adresse, d'un mauvais port ou d'identifiants refusés : le
motif du refus s'affiche en survolant la pastille. Les deux se mettent à jour
toutes seules, sans recharger la page.

En dessous, vos équipements. Chaque vignette porte son origine : **« Découvert »**
suivi du nom de la famille d'appareils, ou **« Créé à la main »**. La distinction
compte, parce qu'elle explique la suite : un équipement découvert verra sa
plomberie remise à jour à chaque nouvelle découverte, un équipement manuel ne
sera jamais touché.

Un mot sur cette mise à jour, car c'est une inquiétude légitime : **vos retouches
survivent.** Une commande renommée, une unité corrigée, un affichage masqué, un
historique activé — rien de tout cela n'est réécrit. La découverte ne rafraîchit
que ce qui relève de la tuyauterie : le topic écouté et le chemin de la valeur
dans le message reçu.

## Les autres réglages

La configuration en propose davantage, mais leurs valeurs conviennent telles
quelles. N'y touchez que si vous savez pourquoi.

**Intervalle de maintien** : le silence que le broker tolère avant de considérer
la connexion morte. Soixante secondes par défaut.

**Port des ordres** : le port local par lequel Jeedom transmet ses instructions
au démon. Il n'écoute que sur la machine elle-même. À changer uniquement si ce
port est déjà pris.

**Topics ignorés** : un motif par ligne, avec les jokers MQTT `+` et `#`. Ce que
Jeedom publie lui-même y figure d'office — le réabsorber reviendrait à découvrir
ses propres équipements — ainsi que les topics internes du broker.

### Un mot sur le chiffrement

MQTT transmet le mot de passe en clair quand TLS n'est pas activé. Sur un réseau
domestique fermé, cela n'a rien de dramatique. Sur un réseau partagé, activez
TLS — et pensez à changer le port, un broker en TLS écoute rarement sur 1883.

Si le certificat de votre broker n'est pas signé par une autorité publique,
indiquez le chemin du certificat d'autorité dans le champ prévu ; laissé vide, ce
sont les autorités reconnues par le système qui font foi.

L'option **Ne pas vérifier le certificat** laisse le chiffrement actif mais cesse
de contrôler l'identité du broker : n'importe quel serveur peut alors se faire
passer pour lui. Elle existe pour le certificat auto-signé d'un broker
domestique, pas pour se débarrasser d'une erreur que l'on n'a pas lue.

## Créer un équipement à la main

C'est le moyen de faire entrer dans Jeedom un appareil que la découverte ne sait
pas encore reconnaître : un Shelly Gen2, un Tasmota, un capteur fait maison, tout
ce qui publie sur MQTT.

1. Sur la page du plugin, cliquez sur **« Ajouter un équipement »** et donnez-lui
   un nom.
2. Renseignez son **topic de base**, par exemple
   `shellies/shelly1pm-D8BFC01A0805`. Ce champ ne fait rien par lui-même : il
   sert seulement à préremplir le topic des commandes que vous ajouterez
   ensuite. C'est le topic de chaque commande, et lui seul, qui décide de ce qui
   est écouté ou publié.
3. Choisissez un **objet parent**, sans quoi l'équipement n'ira pas sur le
   Dashboard — le même piège que pour les équipements découverts.
4. Passez à l'onglet **Commandes**.

Une **information** lit un topic, une **action** publie sur un topic.

Pour une information, indiquez le topic écouté. Si l'appareil publie du JSON —
c'est-à-dire un message de la forme `{"emeter":[{"power":128.4}]}` plutôt qu'un
simple nombre — le champ **Chemin** dit quelle valeur en extraire. Ce chemin
s'écrit avec des points : `emeter.0.power` désigne, dans l'exemple ci-dessus, la
puissance du premier compteur. Un segment qui est un nombre désigne un rang dans
une liste, le premier portant le numéro 0. Laissez le chemin vide pour prendre le
message tel qu'il arrive.

Renseignez ensuite l'**unité** — `W`, `°C`, `%` — qui s'affichera à côté de la
valeur, et cochez l'historisation si vous voulez en garder une courbe.

Pour une action, indiquez le topic sur lequel publier et le **message publié**.
Dans ce message, `#slider#` est remplacé par la valeur du curseur, `#message#`
par le texte saisi et `#color#` par la couleur choisie : c'est ainsi qu'une
commande à curseur transmet sa position.

Le cas courant — un topic, une valeur — n'a besoin de rien d'autre. Les
**réglages fins**, repliés derrière un bouton sur chaque ligne, servent aux cas
qui sortent de l'ordinaire :

- la **correspondance** traduit le message reçu, caractère pour caractère, en la
  valeur de votre choix : c'est elle qui transforme un `on` en 1 et un `off` en
  0, pour qu'une prise s'affiche comme allumée plutôt que comme le texte « on » ;
- l'**échelle** multiplie la valeur, le **décalage** lui ajoute une constante, et
  l'**arrondi** limite le nombre de décimales — de quoi passer de milliwatts à
  des watts, ou de dixièmes de degré à des degrés ;
- l'**intervalle minimum** impose une durée entre deux enregistrements, pour un
  capteur qui parle trop ;
- le **rappel** réémet une valeur inchangée au bout d'un certain temps. Sans lui,
  la « dernière communication » d'un capteur parfaitement stable vieillit
  indéfiniment et finit par ressembler à une panne.

Une commande qui porte des réglages fins le signale, pour qu'on n'aille pas
chercher ailleurs l'explication d'une valeur inattendue.

### Le nom de vos appareils

Un équipement découvert s'appelle d'abord par son identifiant technique —
« Shelly 1 55670C » — qui a le mérite d'être unique, et l'inconvénient de ne
rien dire. Le plugin va donc lire, sur l'appareil lui-même, le nom que vous lui
avez donné dans son application, et l'ajoute : **« Shelly 1 55670C chaudiere »**.

Si l'appareil ne répond pas, demande une authentification ou n'a pas de nom, le
nom technique reste, exactement comme avant : un échec ne dégrade jamais ce qui
existe. Et le jour où vous renommez un équipement vous-même, la découverte ne
touche plus jamais à ce nom — elle continue seulement à tenir à jour la
plomberie, c'est-à-dire les topics écoutés.

Cette lecture suppose une requête vers l'appareil. Si vous préférez que Jeedom
ne sollicite rien sur votre réseau, décochez « Lire le nom dans l'appareil »
dans la configuration du plugin.

### L'adresse de vos appareils

Chaque équipement découvert affiche l'adresse de l'appareil, cliquable, sur sa
vignette et dans son panneau. C'est le moyen le plus court de savoir lequel on
tient : on ouvre sa page, on le reconnaît, et on revient le nommer dans Jeedom
en connaissance de cause. L'adresse est tenue à jour à chaque découverte — un
nouveau bail DHCP ne laisse pas un lien mort derrière lui.

## En cas de problème

**Deux journaux**, tous deux consultables depuis **Analyse → Logs** :

- `mqttbe` — le plugin lui-même : création des équipements, envoi de la table de
  routage, échanges avec le démon ;
- `mqttbed` — le démon : connexion au broker, souscriptions, messages reçus,
  découverte en cours.

Quand la découverte semble ne rien faire, c'est `mqttbed` qu'il faut lire en
premier : il y inscrit chaque appareil reconnu, avec son nom, son identité et le
nombre de commandes déduites.

La page **Analyse → Santé** donne en trois lignes l'essentiel : le broker est-il
configuré, le démon tourne-t-il, a-t-il parlé récemment.

**Si la pastille du broker reste rouge**, vérifiez dans l'ordre l'adresse, le
port, puis les identifiants ; le motif du refus s'affiche en survolant la
pastille. Depuis une console sur la machine Jeedom, un client MQTT tranche la
question en une commande :

```bash
mosquitto_sub -h 192.168.1.10 -p 1883 -t '#' -v
```

Si cette commande ne reçoit rien, le plugin ne recevra rien non plus, et ce n'est
pas de son côté qu'il faut chercher. Si elle affiche vos Shelly alors que le
plugin reste vide, c'est là que le journal `mqttbed` devient utile.

**Si un équipement manque**, ou si un appareil est arrivé après le démarrage du
démon, utilisez **« Relancer la découverte »** dans la configuration du plugin.
La demande part vers tout le parc, qui se re-présente dans la seconde ; la
création, elle, prend quelques secondes de plus. Attention : le bouton fait
travailler le démon avec la configuration **enregistrée** — si vous venez de
réactiver la découverte, sauvegardez d'abord.

**Si un équipement n'apparaît pas sur le Dashboard** alors qu'il existe bien sur
la page du plugin, il lui manque son objet parent. Voir plus haut : c'est de loin
la cause la plus fréquente.
