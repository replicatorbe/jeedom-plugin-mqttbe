# Plugin Jeedom — MQTT BE

Relie Jeedom à un broker MQTT et découvre tout seul les équipements qui s'y
annoncent. L'utilisateur ne saisit que l'adresse du broker : ni topic, ni modèle
d'équipement à écrire.

Le principe directeur — celui dont découlent tous les choix d'architecture — est
qu'on **interroge l'appareil plutôt que de reconnaître son modèle** : un
catalogue n'est jamais nécessaire à la découverte, il ne sert qu'à embellir.
`docs/ARCHITECTURE.md` le détaille, `docs/ROADMAP.md` donne l'ordre des travaux
et les critères d'acceptation.

## État d'avancement

| Jalon | Contenu | État |
|---|---|---|
| 0 | Socle du dépôt, conformité Jeedom, intégration continue | fait |
| 1 | Client MQTT, démon, page de configuration, test de connexion | fait |
| 2 | Modèle de périphérique, capacités, fabrique, routage, équipements manuels | fait |
| 3 | Shelly Gen2 / Gen3 / Gen4 | éprouvé sur Gen3 ; volets et lumières non éprouvés |
| 4 | Shelly Gen1 | fait |
| 4 bis | Passerelles OpenMQTTGateway et balises Bluetooth | fait, hors plan initial |
| 5 | Explorateur de topics, adoption, reprise en main | file d'adoption faite, explorateur à venir |
| 6 à 8 | Tasmota, Zigbee2MQTT, Home Assistant Discovery | à venir |
| 9 à 10 | JSON générique, version 1.0 | à venir |

Les jalons 0, 1, 2 et 4 ont été éprouvés sur un Mosquitto 1.5.7 et un parc Shelly
Gen1 réel : 22 appareils, 198 commandes créées sans saisie. Le jalon 4 bis l'a
été sur des captures anonymisées d'un parc de cinq passerelles — dont une au
préfixe dupliqué — rejouées hors ligne par `tests/check-omg.php`.

**Le jalon 3 a été écrit sans matériel, et cela s'entend.** Aucun Gen2, Gen3 ou
Gen4 n'était joignable — l'un hors tension, l'autre sur pile et endormi — et le
4 a donc été traité avant lui, le parc Gen1 étant nombreux, vivant et
entièrement vérifiable. L'adapter Gen2+ existe pourtant, écrit d'après la
documentation officielle du constructeur, puis **relu ligne par ligne contre
elle une seconde fois** : transport MQTT, méthodes d'énumération, composants
champ par champ, notifications. Cette relecture a trouvé ce que trente contrôles
hors ligne ne pouvaient pas trouver — ils partageaient les hypothèses de
l'adapter —, dont deux commandes que l'appareil aurait refusées et une voie de
découverte entière déclarée impossible à tort.

**Puis du matériel est apparu.** Trois Shelly 1 Mini Gen3 en micrologiciel 2.0.0
se sont révélés joignables sur le broker de production — ils y étaient depuis le
début, personne ne les avait cherchés. Ils ont été découverts seuls, sans qu'un
réglage ne soit touché chez eux, avec quinze commandes chacun. Leur conversation
est conservée, anonymisée, dans `tests/fixtures/shelly/gen2/capture-reelle.json`
et rejouée octet pour octet par le banc d'essai : **c'est la seule capture du
dépôt dont on puisse dire qu'un Shelly réel répond ainsi.** Elle a notamment
montré que `Shelly.GetComponents` livre onze composants sur quatorze à la
première page — ce qu'aucune lecture de la documentation ne laissait deviner.

Le critère d'acceptation est donc atteint **pour ce modèle**. Volets, lumières,
compteurs d'énergie et capteurs sur pile éveillés restent écrits d'après la
documentation seule : `docs/ROADMAP.md` dit ce qui est éprouvé et ce qui ne
l'est pas.

**Le jalon 4 bis n'était prévu nulle part.** OpenMQTTGateway n'apparaissait
qu'au détour du critère d'acceptation du jalon 8, comme un sous-produit du Home
Assistant Discovery. Or ces passerelles publient sur leurs propres topics —
`<préfixe>/SYStoMQTT` et `<préfixe>/BTtoMQTT/<adresse>` — parfaitement lisibles
sans qu'aucun Home Assistant ne tourne. L'adapter natif a donc été écrit en
avance, sans toucher une ligne du noyau, du modèle ni de la fabrique : c'est
exactement le test que le jalon 6 devait faire passer, et il passe déjà.

**Le jalon 5 n'est fait qu'à moitié**, et c'est la moitié dont les passerelles
Bluetooth avaient besoin : la file d'adoption, sa fenêtre, les refus qui
survivent au vidage du cache, et le retour sur un refus. L'explorateur de
topics, la création d'une commande depuis une feuille de JSON et l'export d'un
équipement en modèle réutilisable restent à écrire.

## Structure

```
plugin_info/        info.json, install.php, configuration.php, icône
core/class/         mqttbe.class.php       eqLogic, cmd, pilotage du démon
                    mqttbeDaemon.class.php lancement, surveillance, socket de commande
                    mqttbeFactory.class.php création et mise à jour des équipements découverts
                    mqttbeRouting.class.php table topic → commande, poussée au démon
core/php/           callback.php — le démon y pousse ses messages
core/ajax/          mqttbe.ajax.php — actions de l'interface
core/config/        mqttbe.config.ini    valeurs par défaut, section [mqttbe]
                    capabilities.json    vocabulaire de capacités → type Jeedom
                    catalog/             noms commerciaux, décoratif uniquement
core/i18n/          traductions en_US
desktop/php/        page principale du plugin
desktop/modal/      adoption.php — la file d'adoption
desktop/js/         mqttbe.js — page, file d'adoption, états en direct
resources/mqttbed/  le démon, processus autonome
  core/             boucle, journal, configuration, liaison Jeedom, routeur
  mqtt/             interface de transport et son implémentation
  discovery/        moteur de découverte, modèle, canaux, sondes de nom
    adapters/       ShellyGen1.php, ShellyGen2.php, OpenMqttGateway.php
  lib/              php-mqtt/client, psr/log, myclabs/php-enum (MIT, figés)
tests/              contrôles hors ligne et banc de routage
docs/               ARCHITECTURE.md, ROADMAP.md, documentation fr_FR et en_US
```

`docs/fr_FR/` et `docs/en_US/` sont la documentation **utilisateur** : elles sont
déployées et servent le bouton « Documentation » du plugin dans Jeedom. Ce ne
sont pas des notes de développement, et elles ne doivent jamais promettre au
présent ce qui n'est pas livré.

## Tests

Aucun prérequis : ni Jeedom, ni base de données, ni broker.

```bash
php tests/run.php          # contrôles hors ligne, code de retour non nul si échec
php tests/bench-routage.php # banc de routage : 10 000 messages, exactitude puis latence
```

`tests/run.php` regroupe dix sections : conformité aux conventions du cœur,
contrat démon ↔ Jeedom, clés de configuration, vocabulaire des capacités, moteur
de découverte, sondes de nom, découverte Shelly Gen1, découverte
OpenMQTTGateway, écriture en base de la fabrique, table de routage. Chacune est
aussi exécutable seule — `php tests/check-omg.php`, par exemple.

Ce qu'elles attrapent n'a rien d'exotique — une propriété sans souligné, un nom
de commande écrit différemment de part et d'autre du démon, un type générique
inventé — mais aucun de ces défauts ne se voit à la relecture ni au `php -l`, et
tous se manifestent bien plus tard sous la forme d'un symptôme qui ne désigne
pas sa cause.

Deux sections méritent qu'on dise ce qu'elles couvrent, parce qu'un banc d'un
seul appareil ne les couvrirait pas :

- **les sondes de nom** rejouent le vrai moteur devant un exécuteur HTTP de
  papier et une horloge qu'on avance à la main. Elles vérifient qu'aucune
  requête n'est faite sur le chemin d'un message — vingt-deux appareils éteints,
  ce sont vingt-deux expirations pendant lesquelles plus rien n'est routé —,
  qu'un appareil muet n'est pas resondé en boucle, et qu'un nom déjà obtenu
  n'est jamais effacé par un échec ultérieur ;
- **la découverte OpenMQTTGateway** rejoue des captures anonymisées d'un parc de
  cinq passerelles (`tests/fixtures/omg`). Elle vérifie que le RSSI, qui change
  à chaque trame, ne provoque aucune réécriture en base ; qu'un champ `name`
  présent une trame sur deux ne fait pas osciller le modèle ; que l'inventaire
  du démon est plafonné et périmé, faute de quoi il enfle toute la nuit ; que la
  passerelle au préfixe dupliqué ne crée pas d'équipement fantôme ; et qu'une
  balise silencieuse finit par être déclarée absente au lieu d'affirmer
  éternellement qu'un objet parti depuis trois jours est au salon.

Un contrôle dont les fichiers n'existent pas encore est annoncé « non vérifiable »
et ne fait pas échouer la suite.

L'intégration continue ajoute le workflow officiel Jeedom (lint PHP 8.0 à 8.4,
structure, `info.json`) sur `beta` et sur les demandes de fusion vers `beta` et
`master`.

## Le démon

Processus PHP autonome, lancé par le plugin :

```bash
php resources/mqttbed/mqttbed.php --callback <url> --pid <fichier> \
    --socketport <n> --loglevel <niveau>
```

La clé d'API lui est passée **sur STDIN**, jamais en argument : `ps` est lisible
par tout utilisateur local de la machine.

Il ne charge pas `core.inc.php` — un démon qui le fait suit les mises à jour du
cœur et s'appuie sur un cache de configuration jamais invalidé. Il reçoit sa
configuration par sa socket de commande (`127.0.0.1:daemon::socketport`, une
connexion TCP par message) et repousse ce qu'il reçoit du broker par lots HTTP
sur `core/php/callback.php`.

Les deux surveillances sont croisées : chacun envoie `hb` à l'autre après 45
secondes de silence, le démon se termine s'il ne joint plus Jeedom pendant 300
secondes, et `mqttbe::cron()` le déclare mort au-delà du même délai sans
réception.

## Ajouter un adapter

Le contrat tient en cinq méthodes (`id`, `priority`, `subscriptions`,
`onMessage`, `onTick`) et le contexte fourni à l'adapter lui permet de publier,
de s'abonner en cours de route, d'attendre une réponse corrélée, de retenir un
état de candidat et d'émettre un modèle terminé. Un adapter ne choisit jamais un
type Jeedom : il choisit une **capacité**, et `core/config/capabilities.json`
seul décide de la traduction vers `type`, `subType`, `generic_type`, unité,
widget, visibilité et historisation.

Le critère de réussite de l'architecture est explicite : ajouter Tasmota (jalon 6)
ne doit modifier **aucune ligne** du noyau, du modèle ou de la fabrique.
`OpenMqttGateway.php` en est la première preuve : deuxième adapter, écrit après
coup, il n'a demandé aucune retouche du contrat. Les deux réglages qui lui sont
propres — délai d'absence et adoption de toutes les balises — lui parviennent
par l'ordre `discovery`, que le démon reçoit déjà, et non par une lecture de la
configuration du cœur : le démon ne le charge pas.

## Trois pièges à ne pas réintroduire

1. Toute propriété d'instance de `mqttbe` ou `mqttbeCmd` commence par `_` :
   `DB::save()` traite les autres comme des colonnes SQL.
2. Aucune méthode nommée `set` + clé de formulaire — jamais `setCmd()` :
   `utils::a2o()` les appelle à l'enregistrement, et l'erreur est fatale avant
   toute écriture.
3. La section de `core/config/mqttbe.config.ini` est `[mqttbe]`, pas
   `[default]`, sinon le fichier est totalement inerte.

Aucun des trois ne produit d'erreur visible dans le journal du plugin : c'est
`log/http.error` de Jeedom qu'il faut lire. Le document de référence sur la
structure d'un plugin Jeedom, section 8, détaille ces cas avec les lignes du cœur
qui les imposent.

## Développement

Le dépôt est la source de vérité ; `/var/www/html/plugins/mqttbe` n'en est qu'une
copie, écrasée à chaque déploiement. Le déploiement se fait par l'outil
`deploy-plugin.sh` du dossier de développement, qui lit l'identifiant du plugin
dans `plugin_info/info.json` et en déduit la destination ; `--simulation` montre
ce qui serait copié sans rien écrire.

Branche `beta` par défaut, `master` comme canal stable.

## Licence

AGPL v3.
