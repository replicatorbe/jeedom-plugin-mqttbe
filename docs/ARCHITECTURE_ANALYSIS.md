# Analyse préalable — plugin MQTT « mqttbe »

Document d'analyse écrit avant toute ligne de code du plugin. Il établit ce que
le cœur de Jeedom impose, ce que font réellement les plugins MQTT existants, ce
que les protocoles de découverte offrent, et les décisions qui en découlent.

Les affirmations sont vérifiables : chaque fois que c'est possible, le fichier
et la ligne sont cités.

---

## 1. Méthode et sources

| Source | Version / date | Comment elle a été lue |
|---|---|---|
| Cœur Jeedom | 4.6.13, installé sur cette machine | lecture directe de `/var/www/html` |
| PHP local | 8.4.24 (CLI) | `php -v` |
| jMQTT | dépôt `BadWolf42/jMQTT`, dernier commit 6 avril 2026 | cloné et lu |
| MQTT Manager (`mqtt2`) | dépôt `jeedom/plugin-mqtt2` | cloné et lu |
| MQTT Discovery (Mips2648) | documentation publique uniquement | **source non publique** — voir §5 |
| Home Assistant MQTT Discovery | table d'abréviations du dépôt `home-assistant/core` (299 lignes) + documentation officielle | téléchargée |
| Tasmota | `xdrv_12_discovery.ino`, branche `development` | téléchargé et lu |
| Zigbee2MQTT | documentation officielle des topics | lue |
| Shelly Gen1 / Gen2+ | `shelly-api-docs.shelly.cloud` | lue |

Le dépôt historique `domotruc/jMQTT` est figé depuis décembre 2019 : c'est le
fork `BadWolf42/jMQTT` qui porte la version 2 vivante. C'est lui qui a été
analysé.

---

## 2. Ce que le socle Jeedom impose

### 2.1 Les deux objets, et rien d'autre

Un plugin ne dispose que de deux classes persistées : `eqLogic` (l'équipement)
et `cmd` (la commande, `info` ou `action`). Tout modèle de périphérique doit
finir par se projeter là-dessus. Les conséquences pratiques sont mesurables et
contraignantes ; elles viennent de `install/database.json` :

| Colonne | Type | Conséquence pour une création automatique |
|---|---|---|
| `eqLogic.logicalId` | `varchar(127)` | l'identifiant stable d'un périphérique doit tenir en 127 caractères |
| `eqLogic.name` | `varchar(127)` | nom tronqué, et **unique avec `object_id`** |
| `cmd.logicalId` | `varchar(1023)` | confortable : un topic complet y tient |
| `cmd.name` | `varchar(127)` | **unique avec `eqLogic_id`** |
| `cmd.unite` | `varchar(45)` | |
| `cmd.generic_type` | `varchar(255)` | |

Les deux index `unique` sont le principal piège d'une création automatique : un
adapter qui produit deux canaux nommés « Température » sur le même équipement
fait échouer l'enregistrement **de tout l'équipement**, pas seulement de la
commande fautive. La déduplication des noms n'est pas un détail cosmétique,
c'est une condition de fonctionnement.

### 2.2 Les types génériques : la seule sémantique que Jeedom comprend

`core/config/jeedom.config.php` (à partir de la ligne 132) définit 170 types
génériques : `LIGHT_STATE`, `LIGHT_ON`, `LIGHT_SLIDER`, `ENERGY_STATE`,
`FLAP_STATE`, `FLAP_SLIDER`, `THERMOSTAT_SETPOINT`, `POWER`, `CONSUMPTION`,
`VOLTAGE`, `TEMPERATURE`, `HUMIDITY`, `PRESENCE`, `OPENING`, `SMOKE`,
`WATER_LEAK`, `BATTERY`, `BUTTON`, `ONLINE`, `FAN_SPEED`, `LOCK_STATE`…

C'est par eux que passent les widgets automatiques, la vue « Maison », les
résumés d'objet, Alexa/Google, et les plugins qui consomment d'autres plugins.
**Un plugin de découverte qui ne renseigne pas `generic_type` produit des
équipements muets**, corrects sur le papier et inutilisables en pratique. C'est
donc la cible réelle de toute la chaîne d'analyse : partir d'un `device_class`
Home Assistant, d'un `expose` Zigbee2MQTT ou d'un composant Shelly, et arriver
à un couple (`type`, `subType`) **plus** un `generic_type` juste.

### 2.3 Démon : le contrat du cœur

`plugin::deamon_info()` (`core/class/plugin.class.php`, ligne 839) appelle la
méthode statique `deamon_info()` de la classe du plugin et y ajoute lui-même
l'état des dépendances, le mode automatique, l'état des crons et le fait que
Jeedom soit démarré. Le plugin doit donc fournir :

- `deamon_info()` → `array('state' => 'ok|nok', 'launchable' => 'ok|nok', …)` ;
- `deamon_start()`, `deamon_stop()` ;
- `dependancy_info()` / `dependancy_install()` **seulement si** `hasDependency`
  vaut 1 dans `info.json` ;
- un `cron()` (ou `cron5`) qui sert de chien de garde.

Le cœur relance le démon automatiquement (`plugin.class.php` lignes 550 et 594),
protège contre les relances trop rapprochées (45 s) et écrit
`lastDeamonLaunchTime`. Il n'y a rien à réinventer : il faut s'y conformer.

`STRUCTURE-PLUGIN-JEEDOM.md` §8 ajoute une règle que l'expérience a payée : **un
démon ne doit pas charger `core.inc.php`**. Il doit être un processus autonome
qui reçoit sa configuration au lancement et repousse ses données par HTTP.

### 2.4 Les pièges par réflexion, rappelés

Deux conventions non écrites cassent un plugin en silence
(`STRUCTURE-PLUGIN-JEEDOM.md` §8) :

1. toute propriété d'une classe `eqLogic`/`cmd` doit commencer par `_`, sinon
   `DB::save()` la traite comme une colonne ;
2. aucune méthode ne doit s'appeler `set` + une clé du formulaire, et jamais
   `setCmd()` : `utils::a2o()` l'appellerait à chaque enregistrement.

Pour un plugin qui va beaucoup manipuler des commandes par programme, le second
point mérite une convention de nommage explicite dès le départ :
`applyChannel()`, `publishChannel()`, jamais `setCmd()`, `setTopic()` sur
l'eqLogic si `topic` est une clé de formulaire.

### 2.5 Version de PHP visée

`/var/www/html/composer.json` fixe `"platform": {"php": "7.4"}` : le cœur, lui,
reste jouable sur Debian 11. Le workflow d'intégration continue officiel
vérifie la syntaxe en 7.4, 8.0 et 8.2.

Un plugin n'est pas tenu par ce plancher : `requireOsVersion` dans `info.json`
lui permet de fixer le sien, et le cœur **refuse alors proprement
l'installation** en dessous, avec un message explicite, plutôt que de laisser
un plugin cassé s'installer.

**Décision : plancher à Debian 12, donc PHP 8.0** (`requireOsVersion: 12`).
La machine de développement est en Debian 13 / PHP 8.4. Debian 11 a atteint sa
fin de vie ; et surtout, la seule bibliothèque MQTT PHP sérieuse compatible 7.4
est une branche abandonnée (§7). Payer le prix d'un client maison pour couvrir
une distribution en fin de vie serait le mauvais côté de l'échange.

### 2.6 Ce dont on dispose sans rien installer

`php -m` sur l'installation : `sockets`, `pcntl`, `posix`, `curl`, `json`,
`openssl`, `shmop`, `sysvmsg`. Autrement dit, tout ce qu'il faut pour écrire un
démon PHP autonome, multiplexé sur `stream_select()`, capable de TLS et de
signaux — sans une seule dépendance externe.

Il n'y a **pas** d'extension `mosquitto`, **pas** de Node.js installé, et
`vendor/` du cœur n'embarque aucun client MQTT.

---

## 3. jMQTT (fork BadWolf42, version 2)

### 3.1 Architecture réelle

```
Broker ──► jmqttd.py (paho-mqtt, venv Python)
              │  HTTP POST par lots  ──► core/php/callback.php ──► jMQTTComFromDaemon
              ◄── socket TCP 127.0.0.1 ── jMQTTComToDaemon (publish, subscribe, loglevel…)
```

- Démon Python (`resources/jmqttd/`, 1 419 lignes) : `jmqttd.py`, `jMqttClient.py`,
  `JeedomMsg.py`, `jMqttRealTime.py`. Dépendances installées dans un venv
  (`resources/python-requirements/requirements.txt`).
- Aller : le démon poste des lots JSON sur `core/php/callback.php`, authentifiés
  par `jeedom::apiAccess()` et par un `uid` = `pid:port` mémorisé en cache.
- Retour : Jeedom ouvre une socket TCP sur `127.0.0.1:<port>` du démon
  (`jMQTTComToDaemon::send()`).
- Surveillance : battement de cœur toutes les 45 s dans un sens, mort du démon
  déclarée après 300 s sans réception (`jMQTTDaemon::check()`).
- Multi-broker : chaque broker est un `eqLogic` de type broker ; les équipements
  portent `brkId`.

Ce squelette est solide et éprouvé. **C'est le modèle à reprendre**, quel que
soit le langage du démon.

### 3.2 Là où le modèle coûte cher

`jMQTT::brokerMessageCallback()` (ligne 1799 de `jMQTT.class.php`) s'exécute
**dans Jeedom, pour chaque message reçu** :

```php
foreach (self::byBrkId($this->getId()) as $eqpt) {
    if (mosquitto_topic_matches_sub($eqpt->getTopic(), $msgTopic)) $elogics[] = $eqpt;
}
```

Boucle sur tous les équipements du broker, filtrage de topic en PHP, puis
requête SQL par équipement retenu (`jMQTTCmd::byEqLogicIdAndTopic`). Le code
lui-même admet le problème : au-delà de 300 ms de traitement pour **un** message,
il écrit un avertissement `processed in %dms (very long)`.

À cela s'ajoute le coût fixe de `callback.php` : chaque lot rejoue
`core.inc.php`, donc l'autoload, la configuration, la connexion MySQL.

La leçon est nette : **le routage topic → commande ne doit pas se faire dans le
processus web de Jeedom**. C'est le point d'inflexion de notre architecture
(§9, décision 3).

### 3.3 Le modèle d'usage

jMQTT est un excellent outil d'intégrateur : on saisit un topic, on crée des
commandes, éventuellement par JSON path, on applique un « template »
communautaire (138 fichiers dans `core/config/template/` : Shelly, Tasmota,
Zigbee2MQTT, Z-Wave JS UI, Frigate, Nuki, Ring…).

Mais :

- la création automatique de commandes (`autoAddCmd`) crée **une commande par
  topic**, sans type, sans unité, sans `generic_type` — donc du brut à retoucher ;
- aucun support du Home Assistant MQTT Discovery. Le seul endroit où
  `homeassistant` apparaît dans le code est `core/ajax/jMQTT.ajax.php:235`, pour
  **exclure** `homeassistant/#` de l'explorateur temps réel ;
- les templates sont des listes de commandes figées : un Shelly Pro 3EM a son
  fichier, un modèle sorti l'an prochain n'aura rien jusqu'à ce qu'un
  utilisateur écrive son template.

Autrement dit : l'automatisation s'arrête au catalogue.

---

## 4. MQTT Manager (`mqtt2`, Jeedom SAS)

### 4.1 Architecture

```
Broker ──► mqtt2d.js (Node.js, lib « mqtt ») ──► lots HTTP ──► mqtt2::handleMqttMessage()
       ◄── HTTP POST /publish sur le démon ◄── mqtt2::publish()
```

- Démon Node (`resources/mqtt2d/mqtt2d.js`, 177 lignes) ; dépendances :
  `apt install nodejs` + `npm install` (`plugin_info/packages.json`).
- Il s'abonne à `#` **et** `$SYS/#`, sans filtre.
- Chaque topic est transformé en clé `a::b::c` et les messages sont empilés puis
  postés à Jeedom, où `handleMqttMessage()` les redistribue.
- Le plugin sait aussi **installer et gérer un broker Mosquitto** (apt local, ou
  conteneur via le plugin Docker Management), générer des certificats, gérer un
  mot de passe (`installMosquitto()`, ligne 282).
- Il sert de socle à d'autres plugins : `getPluginForTopic()` / `addPluginTopic()`
  permettent à un plugin tiers de réserver un topic.
- Il publie aussi **l'état de Jeedom** vers MQTT sous un `root_topic` (`jeedom`
  par défaut) et accepte des commandes sur `jeedom/cmd/set/<id>`.

### 4.2 Sa découverte automatique, mesurée

Trois chemins, tous partiels :

1. `announce()` (ligne 773) : cas `shellies` (Gen1 seulement, via
   `shellies/announce`) et cas `tasmota` (via la clé `discovery`). Dans les deux
   cas, le modèle annoncé sert à charger un fichier de
   `core/config/devices/<fabricant>/<modele>.json` — 18 fichiers au total. Modèle
   absent du catalogue = équipement créé **vide**.
2. `ha_discovery()` (ligne 855) : ne traite que les messages dont
   `config.dev.mf` vaut exactement `espressif`
   (`if (trim($configuration['config']['dev']['mf']) != 'espressif') continue;`).
   Autrement dit : ESPHome, et rien d'autre. Zigbee2MQTT, Z-Wave JS UI,
   OpenMQTTGateway, Shelly passent à la trappe. Le sous-ensemble traité couvre
   `sensor`, `binary_sensor`, `text`, `button`, `switch`, `number`,
   `device_automation` ; aucun `generic_type` n'est posé, `binary_sensor`
   devient une commande `string`, l'unité n'est reprise que parfois.
3. `jeedom_discovery()` : import d'un autre Jeedom.

### 4.3 Ce qu'il faut lui reprendre

- la gestion du broker local (à ne **pas** refaire : mieux vaut le détecter et le
  réutiliser) ;
- l'idée d'une API inter-plugins par réservation de topic ;
- l'avertissement qu'il crée : `root_topic = jeedom` porte l'état de Jeedom
  lui-même. Un moteur de découverte naïf branché sur `#` **réimporterait les
  équipements de Jeedom dans Jeedom**. Cette exclusion doit être native.

---

## 5. MQTT Discovery (Mips2648)

Le dépôt n'est pas public sous un nom devinable ; seule la documentation
(`mips2648.github.io/jeedom-plugins-docs/MQTTDiscovery/`) a pu être analysée.
Ce qui en ressort :

- il consomme **le Home Assistant MQTT Discovery**, et lui seul ;
- il est autonome (ne dépend ni de jMQTT ni de `mqtt2`), mais reprend la
  configuration de broker de `mqtt2` si présent ;
- il crée équipements et commandes avec min/max, listes de choix, icônes et
  types génériques — c'est, des trois, celui qui vise le plus juste ;
- il demande à l'utilisateur : IP/port/identifiants du broker, et **les topics
  racines à surveiller** ;
- il couvre `alarm_control_panel`, `binary_sensor`, `button`, `climate`, `cover`,
  `light`, `lock`, `number`, `select`, `sensor`, `switch`, `text`, `update`,
  `vacuum` ;
- limite assumée : hors HA Discovery, il ne sait rien faire. Un équipement qui
  ne publie pas de message de découverte n'existe pas pour lui.

C'est le concurrent le plus proche de notre objectif. Notre différence tient en
une phrase : **il attend qu'un périphérique se décrive ; nous irons interroger
ceux qui ne le font pas.** Shelly Gen2+ en est exactement l'illustration (§6.1).

---

## 6. Les protocoles de découverte, et ce qu'ils donnent réellement

### 6.1 Shelly — la cible prioritaire

C'est le cas le plus intéressant, parce que c'est celui où les trois plugins
existants sont les plus faibles et où l'on peut faire beaucoup mieux sans
catalogue.

**Gen1** (Shelly 1, 1PM, 2.5, Plug S, Dimmer 2, H&T, Flood, Button…)

- Topics : `shellies/<id>/relay/0`, `/relay/0/power`, `/relay/0/energy`,
  `/roller/0`, `/roller/0/pos`, `/light/0/status`, `/input/0`,
  `/input_event/0`, `/longpush/0`, `/sensor/temperature`, `/sensor/humidity`,
  `/sensor/battery`.
- Commande : `shellies/<id>/relay/0/command` ← `on|off|toggle`.
- Disponibilité : `shellies/<id>/online` (`true`/`false`, retenu, LWT).
- Découverte : `shellies/announce` (message JSON : `id`, `model`, `mac`, `ip`,
  `fw_ver`, `new_fw`, éventuellement `mode`), et surtout **on peut la
  provoquer** en publiant `announce` sur `shellies/command`. Le parc entier se
  re-présente à la demande.
- L'annonce donne le modèle (`SHSW-25`, `SHPLG-S`, `SHHT-1`…) mais **pas** la
  liste des composants. Il faut donc soit un catalogue modèle → capacités, soit
  une sonde HTTP sur l'`ip` annoncée (`/shelly`, `/settings`, `/status` — API
  locale, souvent sans authentification), qui donne le nombre de relais, le
  mode `relay`/`roller`, la présence d'un compteur d'énergie. La sonde HTTP est
  la voie qui évite le catalogue figé ; le catalogue reste le repli hors ligne.

**Gen2, Gen3, Gen4** (Plus, Pro, Mini, et toute la génération courante)

Même pile logicielle : RPC JSON. Et c'est là que tout se joue.

- Préfixe de topic par défaut : l'identifiant du périphérique, par exemple
  `shellyplus1pm-a8032abc1234`.
- `<prefix>/online` : `true` / `false` (LWT).
- `<prefix>/events/rpc` : notifications `NotifyStatus` / `NotifyEvent`.
  **Activé par défaut** (`rpc_ntf` vaut `true`).
- `<prefix>/status/<composant>:<id>` : état complet d'un composant —
  **désactivé par défaut** (`status_ntf` vaut `false`).
- `<prefix>/rpc` : requêtes RPC entrantes. La réponse est publiée sur
  `<src>/rpc`, où `src` est le champ fourni par l'appelant.
- `<prefix>/command/<composant>:<id>` : contrôle simple, actif par défaut
  (`enable_control`).

Deux conséquences majeures :

1. **Les templates figés des plugins existants sont faux par défaut.** Le
   modèle `Shelly.1PMG4.json` de `mqtt2` écoute `status/switch:0/output` :
   ce topic n'existe que si l'utilisateur a activé `status_ntf` à la main. Le
   plugin promet une découverte automatique et livre un équipement muet.
   Il faut lire `events/rpc`, qui, lui, est actif d'origine.
2. **Un Shelly Gen2+ sait se décrire complètement, à la demande.**
   `Shelly.GetDeviceInfo` donne `id`, `mac`, `model`, `gen`, `ver`, `app`,
   `profile` ; `Shelly.GetStatus` et `Shelly.GetConfig` donnent l'état et la
   configuration de **tous** les composants ; `Shelly.GetComponents` énumère les
   composants (`key` de la forme `switch:0`, avec `status` et `config`, y compris
   les composants virtuels). Tout cela **par MQTT**, sans HTTP, sans
   authentification supplémentaire, sans catalogue.

C'est le cœur de notre proposition de valeur : là où les autres attendent un
message de découverte que Shelly n'envoie pas, on tient une **conversation** de
deux messages avec l'appareil et on en ressort la liste exacte de ses capacités.
Un modèle Gen5 sorti demain sera reconnu le jour même, sans mise à jour du
plugin — c'est ce que « l'infra doit prévoir » exige.

Composants rencontrés à mapper : `switch`, `cover`, `light`, `rgb`, `rgbw`,
`cct`, `input`, `temperature`, `humidity`, `voltmeter`, `pm1`, `em`, `em1`,
`emdata`, `devicepower`, `smoke`, `flood`, `gas`, `ht_ui`, `script`,
`boolean`/`number`/`text`/`enum` (composants virtuels Gen3+), `blutrv` et
`blugw` (passerelle BLU).

### 6.2 Tasmota

- Découverte native : `tasmota/discovery/<MAC>/config` (retenu). Clés relevées
  dans `xdrv_12_discovery.ino` : `ip`, `dn` (nom), `fn[]` (noms conviviaux),
  `hn`, `mac`, `md` (modèle), `ty`, `if`, `cam`, `ofln`, `onln`, `state[]`,
  `sw`, `t` (topic), `ft` (full topic), `tp[]` (préfixes cmnd/stat/tele),
  `rl[]` (relais), `swc[]`, `swn[]`, `btn[]`, `so{}` (SetOptions), `lk`,
  `lt_st`, `bat`, `dslp`, `sho`, `sht`, `ver`.
- Second message : `tasmota/discovery/<MAC>/sensors`, qui contient la structure
  `sn` — la forme exacte du JSON publié ensuite sur `tele/<topic>/SENSOR`.
- `SetOption19 0` (défaut) = découverte native Tasmota ; `SetOption19 1` =
  ancienne découverte façon Home Assistant, retirée des compilations récentes.
- Topics d'exploitation : `cmnd/<t>/POWER`, `stat/<t>/RESULT`,
  `tele/<t>/STATE`, `tele/<t>/SENSOR`, `tele/<t>/LWT` (`Online`/`Offline`),
  le tout recomposé à partir de `ft`, `tp` et `t`.

Tasmota est le cas idéal : un seul message retenu décrit relais, interrupteurs,
boutons, lumière et capteurs, avec le gabarit exact des futurs messages.

### 6.3 Zigbee2MQTT

- `zigbee2mqtt/bridge/devices` (retenu) : **le catalogue complet**, avec pour
  chaque appareil `ieee_address`, `friendly_name`, `type`, et
  `definition.{model, vendor, description, exposes[]}`.
- `exposes[]` est une description de capacités lisible par une machine :
  `type` (`binary`, `numeric`, `enum`, `text`, `composite`, `light`, `switch`,
  `climate`, `cover`, `fan`, `lock`), `name`, `property`, `access` (masque de
  bits : 1 = publié dans l'état, 2 = modifiable par `/set`, 4 = interrogeable
  par `/get`), `unit`, `value_min`/`value_max`/`value_step`,
  `value_on`/`value_off`/`value_toggle`, `values` pour les énumérations, et
  `features[]` pour les types composés.
- État : `zigbee2mqtt/<friendly_name>` (JSON à plat) ; commande :
  `zigbee2mqtt/<friendly_name>/set` ; disponibilité :
  `zigbee2mqtt/<friendly_name>/availability`.
- Événements : `zigbee2mqtt/bridge/event` (`device_joined`, `device_leave`,
  `device_interview`) → découverte incrémentale sans re-scan.
- `ieee_address` est un identifiant stable et mondialement unique : c'est la
  clé d'identité parfaite. Attention : `friendly_name` **change** (l'utilisateur
  le renomme), il ne doit jamais servir d'identité, seulement de nom par défaut
  et de composant de topic — à réécrire sur `bridge/event`.

Z2M publie **aussi** du HA Discovery quand `homeassistant: true`. Le même
appareil peut donc arriver par deux chemins : c'est le cas d'école du doublon
(§9, décision 6).

### 6.4 Home Assistant MQTT Discovery

- Forme historique : `<prefix>/<composant>/[<node_id>/]<object_id>/config`,
  `<prefix>` valant `homeassistant` par défaut.
- Forme « device » (HA 2024.10+) : `<prefix>/device/<object_id>/config`, avec
  `dev` (appareil), `o` (origine) et `cmps` (composants) dans un seul message.
- Abréviations : table officielle de 299 entrées (`abbreviations.py`) —
  `stat_t`, `cmd_t`, `uniq_id`, `dev_cla`, `val_tpl`, `pl_on`, `avty_t`,
  `unit_of_meas`, `bri_cmd_t`… Toute implémentation doit **normaliser** les
  clés avant de raisonner, sinon elle traite deux fois le même champ.
- `dev` : `ids`, `name`, `mf`, `mdl`, `mdl_id`, `sw`, `hw`, `sn`, `cu`, `cns`,
  `sa`, `via_device`.
- Disponibilité : `avty_t`/`avty`, `pl_avail`, `pl_not_avail`, `avty_mode`.
- Composants : `sensor`, `binary_sensor`, `switch`, `light`, `cover`, `climate`,
  `number`, `select`, `button`, `lock`, `fan`, `siren`, `event`,
  `device_automation`, `text`, `update`, `vacuum`, `valve`, `water_heater`…
- Difficulté réelle : `val_tpl` contient du **Jinja2**. Le cas courant
  (`{{ value_json.temperature }}`, `{{ value_json['x'] | round(1) }}`) se
  ramène à un chemin JSON ; le cas général, non. Il faut une reconnaissance des
  motifs fréquents et un repli explicite (canal marqué « non interprétable »,
  proposé en configuration manuelle) plutôt qu'un demi-interpréteur Jinja.

C'est le protocole le plus large (tout l'écosystème : Z2M, Z-Wave JS UI,
ESPHome, OpenMQTTGateway, Theengs, WLED, Frigate…) et le plus hétérogène.

### 6.5 MQTT générique / JSON

Ni identité, ni sémantique, ni contrat. Seule stratégie honnête : observer
(arborescence des topics, forme et stabilité des charges utiles), proposer, et
laisser l'utilisateur valider. C'est le seul cas où l'interface manuelle est
le mode normal et non le repli.

---

## 7. Le choix du client MQTT

### 7.1 Ce qui existe

| Option | À installer sur la machine | PHP | État |
|---|---|---|---|
| Extension PECL `mosquitto` | compilation, non packagée Debian | — | inutilisable en pratique |
| `paho-mqtt` (Python) | `apt` + venv + `pip` | — | choix de jMQTT ; ~50 Mo et un second langage |
| `mqtt` (Node.js) | `apt nodejs` + `npm install` | — | choix de `mqtt2` ; le plus lourd |
| `php-mqtt/client` **v1.8.1** | **rien** (fichiers embarqués) | 7.4+ | **branche figée depuis août 2023** |
| `php-mqtt/client` **v2.3.2** | **rien** (fichiers embarqués) | 8.0+ | vivante : 30 versions, dernier commit juillet 2026 |
| Client écrit pour le plugin | rien | libre | ~800 lignes à écrire, puis à maintenir |

### 7.2 Embarquer n'est pas dépendre

La distinction est décisive, et c'est elle qui tranche le débat :

- **une dépendance à installer** (venv Python, `apt nodejs`, `npm install`)
  s'exécute chez l'utilisateur, au moment le plus fragile, et échoue : venv
  cassé après une montée de Debian, `pip` sans réseau, Node absent sur Atlas,
  conteneur sans `apt`. C'est le premier poste de support des plugins à démon,
  au point que le cœur prévoit un `maxDependancyInstallTime` et un écran d'état
  dédié ;
- **du code tiers versionné dans le dépôt** ne s'installe pas : il arrive avec
  le plugin, comme le reste. Pas d'`apt`, pas de `pip`, pas de `npm`, pas de
  Composer à l'exécution — un autoloader PSR-4 d'une quinzaine de lignes suffit.

L'objectif « zéro dépendance », qui est un vrai objectif, est donc **entièrement
atteint par une bibliothèque embarquée**. `hasDependency: 0` reste vrai.

### 7.3 Ce que vaut `php-mqtt/client` v2.3.2, vérifié dans le code

| Point examiné | Constat |
|---|---|
| Intégration dans une boucle `stream_select` | `stream_set_blocking($socket, false)` et `loopOnce()` : lecture de tout le disponible, analyse, réémission des QoS 1 en attente, ping. Conçu pour un ordonnanceur externe |
| Accès au descripteur pour multiplexer | `protected $socket` → sous-classe de cinq lignes, sans modifier la bibliothèque |
| Drapeau `retain` | remonté au gestionnaire : `($topic, $message, $retained, $matchedWildcards)` |
| Reconnexion | automatique et configurable, avec re-souscription |
| TLS | CA, chemin CA, certificat client, `verifyPeer`, `verifyPeerName`, auto-signé |
| Protocoles | MQTT 3.1 et 3.1.1, QoS 0/1/2, `will` |
| Licence | MIT — comme `psr/log` et `myclabs/php-enum`, compatible avec un plugin AGPL |
| Poids embarqué | 33 fichiers / 4 852 lignes, plus 10 fichiers / 670 lignes de dépendances |
| Tests | 2 372 lignes, dont des tests d'intégration contre un vrai broker |
| PHP 8.4 | `php -l` propre, aucun paramètre nullable implicite |

La branche 1.x, elle, est compatible 7.4 mais figée depuis août 2023 :
l'embarquer reviendrait à en devenir mainteneur, c'est-à-dire à écrire notre
propre client sans en avoir choisi le code.

### 7.4 Décision

**Embarquer `php-mqtt/client` v2.3.2**, avec `psr/log` et `myclabs/php-enum`,
dans `resources/mqttbed/lib/`, derrière une interface de transport interne qui
la rend remplaçable.

Ce que nous aurions écrit nous-mêmes n'est pas la partie difficile : les ~800
lignes ne sont pas le problème, ce sont les défauts qui ne se révèlent qu'en
production — paquet à cheval sur deux segments TCP, `remaining length` sur
quatre octets, retransmission `dup` en QoS 1, course entre keepalive et
reconnexion, particularités de Mosquitto face à EMQX. Cette bibliothèque a sept
ans de rapports de bugs sur exactement ces points.

Et ce n'est pas là qu'est la valeur du plugin : elle est dans la conversation
RPC avec un Shelly, le modèle de périphérique et la table de capacités.
Dépenser le budget « robustesse » à réimplémenter un protocole déjà résolu
serait un mauvais échange.

*Note factuelle : la 2.x n'emploie que quatre constructions propres à PHP 8
(un `match`, trois promotions de constructeur). L'écart avec 7.4 est donc mince
aujourd'hui — mais rien ne garantit qu'il le reste, et le combler à chaque
montée de version serait maintenir un fork.*

## 8. Contraintes de compatibilité retenues

| Contrainte | Valeur | Origine |
|---|---|---|
| Syntaxe PHP | 8.0 | §2.5 et §7.4 |
| `requireOsVersion` | 12 (Debian 12 ; développement sur Debian 13 / PHP 8.4) | §2.5 |
| `require` (cœur minimal) | 4.4 | cohérent avec les autres plugins de l'auteur |
| Dépendances à installer | aucune (`hasDependency: 0`) — bibliothèque embarquée | §7.2 |
| Plateformes | rpi, docker, diy, smart, atlas | pas de binaire compilé, pas de Node, pas de venv |
| Démon | PHP autonome, sans `core.inc.php` | `STRUCTURE-PLUGIN-JEEDOM.md` §8 |
| Identifiant plugin | `mqttbe` | dossier `jeedom-plugin-mqttbe`, convention de l'auteur |
| Classes | `mqttbe extends eqLogic`, `mqttbeCmd extends cmd` | obligation du cœur |
| Propriétés d'instance | préfixées `_` | `DB::save()` |
| Méthodes interdites | `setCmd`, `setTopic`, `setConfiguration`… | `utils::a2o()` |
| Exceptions | `catch (Throwable)` | PHP 8 |
| Licences embarquées | MIT (compatible AGPL) | §7.3 |

## 9. Conclusions — les décisions que cette analyse impose

1. **Démon PHP autonome, zéro dépendance à installer.** Client MQTT fourni par
   `php-mqtt/client` v2.3.2, embarqué dans le dépôt avec ses deux dépendances
   (MIT, ~5 500 lignes) derrière une interface de transport interne.
   `hasDependency: 0`, `hasOwnDeamon: 1`, `requireOsVersion: 12`. On supprime la
   première cause de panne des plugins concurrents — l'installation de
   dépendances — sans réécrire un protocole déjà résolu (§7).

2. **Reprendre le squelette de démon de jMQTT** — callback HTTP authentifié par
   clé d'API pour le sens démon → Jeedom, socket locale pour Jeedom → démon,
   double battement de cœur, état en cache, chien de garde par `cron()`.
   C'est l'idiome Jeedom, il est éprouvé, il n'y a rien à gagner à s'en écarter.

3. **Inverser le routage.** La table `topic → commande` vit **dans le démon**,
   poussée par Jeedom à chaque changement. Jeedom ne reçoit que des couples
   (identifiant de commande, valeur) déjà résolus, par lots. On supprime la
   boucle sur tous les équipements que jMQTT exécute pour chaque message.

4. **Deux plans distincts.** Plan chaud (valeurs, optimisé, sans écriture en
   base hors `cmd::event`) et plan froid (découverte, rare, coûteux, sans
   contrainte de latence). Les mélanger est ce qui rend les plugins existants
   lents *et* peu automatiques.

5. **La découverte est une conversation, pas une lecture.** Un adapter doit
   pouvoir publier (`shellies/command` → `announce`, `<prefix>/rpc` →
   `Shelly.GetComponents`) et attendre une réponse. Toute architecture qui ne
   prévoit que « recevoir un message retenu et le parser » exclut d'emblée
   Shelly Gen2+, c'est-à-dire la totalité du catalogue Shelly actuel.

6. **Identité avant création.** Clé d'identité stable par périphérique (MAC,
   IEEE, identifiant Shelly), jeu d'alias, arbitrage entre adapters par
   priorité, empreinte de découverte pour rendre l'opération idempotente. Sans
   cela, Zigbee2MQTT (qui publie deux fois) et Tasmota (native + HA) créent des
   doublons.

7. **Ne pas gérer de broker.** Le détecter (127.0.0.1:1883, configuration de
   `mqtt2`, de jMQTT), proposer de l'installer si vraiment aucun n'existe, mais
   ne jamais en prendre la responsabilité : `mqtt2` le fait déjà, et le faire à
   deux plugins casse l'installation.

8. **Exclure d'office les topics de Jeedom** (`jeedom/#` par défaut, réglable)
   et se doter d'un `clientId` distinct : sans quoi la découverte réimporte
   Jeedom dans Jeedom.

9. **Le `generic_type` est le livrable.** Chaque capacité du modèle doit sortir
   avec son type générique, son unité et son gabarit de widget. Un équipement
   créé sans type générique est un échec fonctionnel, même s'il remonte des
   valeurs.

10. **Shelly d'abord, mais par l'infrastructure, pas par le cas particulier.**
    Les adapters Tasmota, Zigbee2MQTT et Home Assistant doivent pouvoir
    s'ajouter sans toucher au noyau : même interface, même modèle de
    périphérique, même fabrique Jeedom.
