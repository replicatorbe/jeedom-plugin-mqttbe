# Architecture du plugin mqttbe

Ce document décrit l'architecture retenue pour le plugin, et répond aux
questions posées avant le développement. Il s'appuie sur
[`ARCHITECTURE_ANALYSIS.md`](ARCHITECTURE_ANALYSIS.md), qui en justifie chaque
décision.

Priorités, dans l'ordre : **simplicité utilisateur**, compatibilité Jeedom,
robustesse, extensibilité, performance, maintenance.

---

## 1. Le principe directeur

> L'utilisateur donne l'adresse d'un broker. Le plugin fait le reste.

Ce qui s'en déduit, et qui gouverne tous les choix qui suivent :

- **aucune dépendance à installer** — pas de Python, pas de Node, pas d'`apt` ;
- **aucun catalogue obligatoire** — interroger l'appareil vaut mieux que
  reconnaître son modèle ;
- **aucun réglage de l'appareil à modifier** — on lit ce qu'il publie déjà par
  défaut, on ne demande pas à l'utilisateur d'activer une option dans son
  Shelly ;
- **rien n'est créé deux fois**, quel que soit le nombre de chemins par
  lesquels un périphérique se présente ;
- **tout ce qui est deviné reste réversible et corrigeable à la main.**

---

## 2. Vue d'ensemble

```
                         Broker MQTT
                              │
        ┌─────────────────────┴──────────────────────┐
        │   démon mqttbed (PHP autonome, un process) │
        │                                            │
        │   MqttClient  (3.1.1, TLS, reconnexion)    │
        │        │                                   │
        │        ├──────────── plan chaud ───────────┼──► Router
        │        │              (valeurs)            │     table topic→canal
        │        │                                   │     poussée par Jeedom
        │        │                                   │           │
        │        └──────────── plan froid ───────────┼──► DiscoveryEngine
        │                       (découverte)         │           │
        │                                            │    ┌──────┴───────┬────────┬─────┐
        │                                            │  Shelly       Tasmota    Z2M    HA
        │                                            │  Gen1/Gen2+   (adapters, même contrat)
        │                                            │    └──────┬───────┴────────┴─────┘
        │                                            │           ▼
        │                                            │     DeviceModel  (JSON, zéro Jeedom)
        └────────────────────┬───────────────────────┘           │
       lots HTTP (clé d'API) │                                   │
                             ▼                                   ▼
                   core/php/callback.php  ────────────►  DeviceFactory
                             │                          (capabilities.json)
                             ▼                                   │
                   cmd::event / checkAndUpdateCmd                 ▼
                                                        eqLogic + cmd (+ generic_type)
```

Deux chemins, volontairement séparés :

| | Plan chaud | Plan froid |
|---|---|---|
| Objet | valeurs des commandes | découverte et modélisation |
| Fréquence | continue, jusqu'à des centaines de messages/minute | rare, par rafales |
| Latence visée | < 100 ms de bout en bout | quelques secondes acceptables |
| Travail dans Jeedom | `cmd::event()`, rien d'autre | création/mise à jour d'objets |
| Travail dans le démon | résolution topic → canal, extraction, filtrage | conversation avec l'appareil, modélisation |

Les plugins existants confondent les deux : c'est ce qui les rend à la fois
lents (routage en PHP à chaque message) et peu automatiques (découverte réduite
à ce qu'un message retenu contient).

---

## 3. La règle de dépendance

```
resources/mqttbed/            démon — ne connaît RIEN de Jeedom
  lib/                        php-mqtt/client + psr/log + php-enum (embarqués, MIT)
  mqtt/                       MqttTransport : la seule frontière avec lib/
  core/                       boucle, routeur, transport vers Jeedom
  discovery/                  moteur + adapters + modèle de périphérique
  ──────────────────────────────────────────────────────────────────
core/class/                   Jeedom — connaît le modèle, pas les protocoles
  mqttbe.class.php            eqLogic
  mqttbeCmd.class.php         cmd
  mqttbeDaemon.class.php      cycle de vie du démon
  mqttbeFactory.class.php     DeviceModel → eqLogic + cmd
  mqttbeRouting.class.php     eqLogic + cmd → table de routage
core/config/capabilities.json le seul fichier qui traduit capacité → Jeedom
```

Une seule direction de dépendance : `discovery/` ne cite jamais `eqLogic`,
`cmd`, `generic_type` ni `log::add()`. Trois bénéfices immédiats :

1. les adapters sont testables hors ligne, sur des fichiers de messages
   capturés, sans Jeedom, sans broker et sans base de données ;
2. un adapter écrit pour un protocole ne peut pas casser un autre protocole ;
3. changer la correspondance vers Jeedom (nouveau type générique, nouveau
   widget) ne touche aucun adapter.

C'est aussi ce que permet le choix du PHP pour le démon : le même code d'adapter
sert au démon **et** à l'interface web du plugin (rejouer une découverte,
prévisualiser un modèle avant création) sans duplication ni pont entre langages.

---

## 4. Le client MQTT

**Décision : embarquer `php-mqtt/client` v2.3.2** (MIT), avec `psr/log` et
`myclabs/php-enum`, dans `resources/mqttbed/lib/`. Aucune installation, aucun
Composer à l'exécution : un autoloader PSR-4 d'une quinzaine de lignes.

Justification détaillée en [analyse §7](ARCHITECTURE_ANALYSIS.md#7-le-choix-du-client-mqtt).
En deux points :

- **embarquer n'est pas dépendre.** Ce qui casse chez l'utilisateur, c'est le
  venv Python et le `npm install`, pas un fichier PHP versionné dans le dépôt.
  L'objectif « zéro dépendance » est atteint, et `hasDependency` reste à 0 ;
- **le fil binaire MQTT n'est pas notre valeur ajoutée.** Elle est dans la
  conversation RPC avec un Shelly, le modèle de périphérique et la table de
  capacités. Une bibliothèque de sept ans, 30 versions et 2 372 lignes de tests
  — dont des tests contre un vrai broker — est plus robuste que ce que nous
  écririons, sur un problème déjà résolu.

Ce que la bibliothèque couvre et que nous n'avons donc pas à écrire :
MQTT 3.1 et 3.1.1, QoS 0/1/2, `will`, TLS complet (CA, certificat client,
vérification du pair, auto-signé), reconnexion automatique avec
re-souscription, ping et keepalive, retransmission des messages non confirmés.

### 4.1 Les deux points d'adaptation

1. **Multiplexage.** Le démon doit surveiller en même temps la socket du
   broker, la socket de commande locale et ses minuteries. La bibliothèque
   travaille déjà en non bloquant (`stream_set_blocking($socket, false)`) et
   expose `loopOnce()`, faite pour un ordonnanceur externe. Le descripteur
   étant `protected`, une sous-classe de cinq lignes l'expose à notre
   `stream_select` :

   ```php
   final class mqttbeTransport extends \PhpMqtt\Client\MqttClient {
       public function stream() { return $this->socket; }
   }
   ```

   La bibliothèque n'est ni modifiée, ni forkée : elle reste remplaçable par
   une simple montée de version.

2. **Interface interne.** Tout le reste du démon parle à une interface
   `MqttTransport` (`connect`, `subscribe`, `publish`, `onMessage`, `tick`),
   dont la bibliothèque n'est qu'une implémentation. Si elle devait un jour
   être abandonnée — c'est ce qui est arrivé à sa branche 1.x — le remplacement
   se ferait en un point, sans toucher au routeur ni aux adapters.

Le drapeau `retain`, exposé par la bibliothèque au gestionnaire de réception,
est propagé jusqu'aux adapters : c'est lui qui distingue « état reconstitué à
la connexion » de « événement qui vient de se produire ». Un moteur de
découverte qui l'ignore fabrique de faux événements à chaque démarrage.

Hors périmètre v1, comme pour la bibliothèque : MQTT 5 et le transport
WebSocket.

## 5. Le démon

### 5.1 Cycle de vie

Contrat Jeedom classique (`info.json` : `hasOwnDeamon: 1`, `hasDependency: 0`) :

- `mqttbe::deamon_info()` → état lu dans le fichier PID et le cache ;
- `mqttbe::deamon_start()` → lance `php resources/mqttbed/mqttbed.php` en
  arrière-plan, journal redirigé vers `log::getPathToLog('mqttbed')` ;
- `mqttbe::deamon_stop()` → SIGTERM, puis SIGKILL après un délai ;
- `mqttbe::cron()` (toutes les minutes) → chien de garde : démon vivant,
  battement de cœur récent, brokers connectés.

Paramètres passés au lancement : URL de rappel, fichier PID, port de la socket
de commande, niveau de journal. **La clé d'API passe par l'entrée standard**,
jamais par la ligne de commande — `ps` est lisible par tout utilisateur local
(convention déjà retenue dans le plugin Dahua VTO de ce dossier).

### 5.2 Les deux canaux

| Sens | Transport | Contenu |
|---|---|---|
| démon → Jeedom | POST HTTP sur `core/php/callback.php`, authentifié par `jeedom::apiAccess()` | lots JSON : valeurs, découvertes, changements d'état |
| Jeedom → démon | socket TCP sur `127.0.0.1:<port>`, message JSON signé par la clé d'API | configuration broker, table de routage, publication, ordre de scan |

Ce schéma est celui de jMQTT, éprouvé depuis des années sur des milliers
d'installations ; le reprendre est un choix de robustesse, pas un manque
d'imagination. Ce qui change est ce qui **transite** : pas des messages MQTT
bruts, mais des couples déjà résolus.

Surveillance croisée : le démon envoie un battement toutes les 45 s s'il n'a
rien d'autre à dire ; Jeedom déclare le démon mort au-delà de 300 s sans
réception et le relance. Le démon se suicide s'il ne parvient plus à joindre
Jeedom (Apache arrêté, Jeedom en sauvegarde) plutôt que d'accumuler.

### 5.3 Le plan chaud, en détail

La table de routage est calculée par Jeedom (`mqttbeRouting::build()`) et
poussée au démon à chaque enregistrement d'équipement, à chaque démarrage, et
sur demande. Une entrée ressemble à :

```json
{
  "topic": "shellyplus1pm-a8032abc1234/events/rpc",
  "targets": [
    { "cmdId": 1842, "selector": {"type":"shelly.notify","component":"switch:0","field":"output"},
      "map": {"true":"1","false":"0"}, "repeat": "onchange" },
    { "cmdId": 1843, "selector": {"type":"shelly.notify","component":"switch:0","field":"apower"},
      "round": 1, "repeat": "onchange" }
  ]
}
```

Le démon indexe ces entrées dans un arbre de topics (exact d'abord, puis
jokers `+` et `#`), applique le sélecteur (chemin JSON, notification Shelly,
valeur brute), la transformation (échelle, arrondi, table de correspondance),
puis le filtre de répétition, et pousse `{cmdId, value, ts}` dans la file de
sortie.

Jeedom, côté `callback.php`, ne fait plus qu'une chose : `cmd::byId()` puis
`event()`. Plus de boucle sur les équipements, plus de correspondance de topic,
plus de requête par message.

Budgets visés (à vérifier par mesure, pas à supposer) :

| Mesure | Cible |
|---|---|
| Messages traités par le démon | ≥ 2 000/s sur un Raspberry Pi 4 |
| Latence message → `cmd::event()` | < 100 ms au 95ᵉ centile |
| Travail dans le processus web par message | 1 `cmd::byId()` en cache mémoire + 1 `event()` |
| Empreinte mémoire du démon | < 60 Mo avec 200 équipements |

### 5.4 Le mode scan

L'exploration (`#`) n'est **pas** le mode normal : elle est coûteuse en réseau
et en mémoire, et sur un broker partagé elle capte tout le trafic de la maison.
Elle est activée à la demande, pour une durée bornée (3 minutes par défaut),
alimente un tampon circulaire borné en mémoire, et sert à l'explorateur de
topics (§10). En fonctionnement normal, le démon n'est abonné qu'aux topics
dont il a besoin : ceux de la table de routage, plus ceux réclamés par les
adapters activés.

---

## 6. Le modèle de périphérique

Structure de données pure, sans aucune notion Jeedom, produite par les adapters
et consommée par la fabrique. Versionnée (`schema`) pour pouvoir évoluer.

```json
{
  "schema": 1,
  "identity": {
    "adapter": "shelly.gen2",
    "uid": "shelly:a8032abc1234",
    "aliases": ["mac:a8032abc1234", "topic:shellyplus1pm-a8032abc1234"],
    "confidence": "certain"
  },
  "meta": {
    "name": "Lampe salon",
    "manufacturer": "Shelly",
    "model": "SNSW-001P16EU",
    "model_name": "Shelly Plus 1PM",
    "generation": 2,
    "firmware": "1.4.4",
    "ip": "192.168.1.42",
    "config_url": "http://192.168.1.42",
    "battery_powered": false
  },
  "availability": {
    "topic": "shellyplus1pm-a8032abc1234/online",
    "payload_on": "true",
    "payload_off": "false"
  },
  "channels": [
    {
      "key": "switch:0.output",
      "capability": "switch.state",
      "name": "État",
      "source": {
        "topic": "shellyplus1pm-a8032abc1234/events/rpc",
        "selector": {"type": "shelly.notify", "component": "switch:0", "field": "output"}
      },
      "value": {"type": "bool", "true": "1", "false": "0"}
    },
    {
      "key": "switch:0.on",
      "capability": "switch.on",
      "name": "On",
      "sink": {
        "topic": "shellyplus1pm-a8032abc1234/rpc",
        "payload": {"method": "Switch.Set", "params": {"id": 0, "on": true}},
        "encoding": "shelly.rpc"
      },
      "links": {"state": "switch:0.output"}
    },
    {
      "key": "switch:0.apower",
      "capability": "power.active",
      "name": "Puissance",
      "unit": "W",
      "source": {
        "topic": "shellyplus1pm-a8032abc1234/events/rpc",
        "selector": {"type": "shelly.notify", "component": "switch:0", "field": "apower"}
      },
      "value": {"type": "number", "round": 1}
    }
  ],
  "fingerprint": "sha1:…"
}
```

Points de conception :

- `uid` **tient en 127 caractères** : c'est la limite de `eqLogic.logicalId`
  (voir analyse §2.1). Forme retenue : `<famille>:<clé naturelle>`.
- `aliases` porte toutes les autres clés par lesquelles ce même appareil peut
  se présenter (MAC, IEEE, préfixe de topic, `unique_id` HA). C'est
  l'anti-doublon (§9).
- `confidence` (`certain`, `probable`, `guess`) décide si la création est
  automatique ou passe par la file d'adoption.
- `channels[].key` est stable et **unique par périphérique** : il devient le
  `logicalId` de la commande. Le nom, lui, peut changer sans casser le lien.
- `links` exprime le lien action → info que Jeedom attend (`cmd.value`).
- `fingerprint` rend la découverte idempotente : empreinte identique = aucune
  écriture en base. Indispensable, puisque les messages retenus sont rejoués à
  chaque démarrage du démon.

---

## 7. Les capacités

Vocabulaire fermé, documenté, versionné : c'est le contrat entre les adapters et
Jeedom. Un adapter ne choisit jamais un type Jeedom ; il choisit une capacité.

Extrait de `core/config/capabilities.json` :

| Capacité | Jeedom `type`/`subType` | `generic_type` | Unité | Widget |
|---|---|---|---|---|
| `switch.state` | info / binary | `ENERGY_STATE` | | `core::prise` |
| `switch.on` / `switch.off` | action / other | `ENERGY_ON` / `ENERGY_OFF` | | `core::prise` |
| `light.state` | info / binary | `LIGHT_STATE` | | |
| `light.brightness` | action / slider | `LIGHT_SLIDER` | % | |
| `cover.position` | action / slider | `FLAP_SLIDER` | % | |
| `cover.state` | info / numeric | `FLAP_STATE` | % | |
| `sensor.temperature` | info / numeric | `TEMPERATURE` | °C | |
| `sensor.humidity` | info / numeric | `HUMIDITY` | % | |
| `power.active` | info / numeric | `POWER` | W | |
| `energy.total` | info / numeric | `CONSUMPTION` | kWh | |
| `energy.voltage` | info / numeric | `VOLTAGE` | V | |
| `contact.open` | info / binary | `OPENING` | | |
| `presence.detected` | info / binary | `PRESENCE` | | |
| `alarm.smoke` | info / binary | `SMOKE` | | |
| `alarm.water_leak` | info / binary | `WATER_LEAK` | | |
| `battery.level` | info / numeric | `BATTERY` | % | |
| `button.event` | info / string | `BUTTON` | | |
| `connectivity.online` | info / binary | `ONLINE` | | |
| `lock.state` / `lock.open` / `lock.close` | info+action | `LOCK_STATE` / `LOCK_OPEN` / `LOCK_CLOSE` | | |
| `thermostat.setpoint` | action / slider | `THERMOSTAT_SET_SETPOINT` | °C | |
| `generic.value` | info / string | `GENERIC_INFO` | | dernier recours |

Chaque entrée porte aussi les valeurs par défaut de `isVisible`,
`isHistorized` (la puissance oui, l'horodatage non), l'icône et l'ordre
d'affichage. C'est **le seul fichier** à modifier quand Jeedom ajoute un type
générique, et le seul endroit où une décision de présentation est prise.

Les 170 types génériques du cœur (analyse §2.2) sont la cible : une capacité
sans type générique est un défaut à corriger, pas un cas normal.

---

## 8. Les adapters

### 8.1 Le contrat

```php
interface mqttbeAdapter {
    public function id();            // 'shelly.gen2'
    public function priority();      // 100 = natif constructeur, 50 = HA, 10 = générique
    public function subscriptions(); // topics à écouter pour découvrir
    public function onMessage($message, $ctx);  // observe, sonde, ou produit un modèle
    public function onTick($ctx);               // relances, expirations
}
```

`$ctx` est ce qui rend la découverte **active** plutôt que passive. Il offre à
l'adapter :

- `publish($topic, $payload)` — provoquer une annonce, émettre un appel RPC ;
- `subscribe($topic)` — s'abonner en cours de route (topic de réponse) ;
- `expect($key, $timeout, $callback)` — attendre une réponse corrélée ;
- `remember($key, $data)` — état de candidat entre deux messages ;
- `emit($deviceModel)` — publier un modèle terminé vers Jeedom.

Sans ce contexte, Shelly Gen2+ est hors de portée : l'appareil ne publie aucun
message de découverte, il **répond** quand on l'interroge (analyse §6.1).

### 8.2 Le cycle d'un candidat

```
  vu ──► sondé ──► modélisé ──► proposé/créé ──► vivant
   │        │          │
   │        │          └─ empreinte identique → rien à faire
   │        └─ pas de réponse après N essais → dégradé vers un adapter moins prioritaire
   └─ topic exclu / appartient à Jeedom → ignoré
```

### 8.3 Shelly Gen2, Gen3, Gen4 — la référence

Séquence complète, sans aucune intervention de l'utilisateur et **sans modifier
la configuration de l'appareil** :

1. abonnement à `+/online`, `+/events/rpc` — les deux seuls topics actifs par
   défaut sur un Shelly moderne ;
2. un préfixe inconnu apparaît → l'adapter publie sur `<prefix>/rpc` :
   `{"id":1,"src":"mqttbe/<jeton>","method":"Shelly.GetDeviceInfo"}` ;
3. réponse sur `mqttbe/<jeton>/rpc` → `id`, `mac`, `model`, `gen`, `ver`,
   `app`, `profile` ;
4. second appel `Shelly.GetComponents` (`dynamic_only: false`, avec `status` et
   `config`), paginé si nécessaire ; repli `Shelly.GetStatus` +
   `Shelly.GetConfig` pour les firmwares qui ne connaissent pas la méthode ;
5. chaque composant (`switch:0`, `cover:0`, `light:1`, `input:0`, `pm1:0`,
   `em:0`, `temperature:0`, `devicepower:0`, composants virtuels…) devient un
   ou plusieurs canaux, avec :
   - lecture par `NotifyStatus` sur `<prefix>/events/rpc` (actif par défaut),
   - écriture par RPC sur `<prefix>/rpc` (`Switch.Set`, `Cover.GoToPosition`,
     `Light.Set`…),
   - le `profile` du périphérique tranche les cas ambigus (`switch` contre
     `cover` sur un 2PM) ;
6. `mac` fournit l'identité (`shelly:a8032abc1234`), le préfixe de topic et
   l'identifiant deviennent des alias.

Ce que cela donne : **un Shelly Gen5 inconnu du plugin sera découvert
complètement le jour de sa sortie**, parce qu'on lui demande ce qu'il sait
faire au lieu de chercher sa fiche dans un catalogue. C'est la différence de
fond avec les templates de jMQTT et de MQTT Manager.

Un catalogue reste présent, mais dégradé à son rôle utile : embellir. Il porte
les noms commerciaux (`SNSW-001P16EU` → « Shelly Plus 1PM »), l'icône, l'ordre
d'affichage et quelques corrections ponctuelles. Son absence n'empêche jamais
la découverte.

### 8.4 Shelly Gen1

1. abonnement à `shellies/announce` et `shellies/+/online` ;
2. au démarrage et à la demande : publication de `announce` sur
   `shellies/command` — tout le parc Gen1 se re-présente immédiatement ;
3. l'annonce donne `id`, `model`, `mac`, `ip`, `fw_ver`, parfois `mode` ;
4. les capacités viennent, dans l'ordre :
   - d'une sonde HTTP facultative sur l'`ip` annoncée (`/shelly`, `/settings`)
     qui donne le nombre exact de relais, le mode `relay`/`roller`, la présence
     d'un compteur — c'est la voie qui évite le catalogue figé,
   - à défaut, du catalogue de modèles Gen1 (`SHSW-1`, `SHSW-25`, `SHPLG-S`,
     `SHDM-2`, `SHHT-1`, `SHWT-1`, `SHBTN-2`…),
   - à défaut encore, de l'observation des topics reçus, qui produit un modèle
     `probable` soumis à l'adoption.
5. identité : `shelly:<mac>` — la **même** que pour Gen2+. Un parc mixte ne
   produit jamais deux fois le même appareil.

### 8.5 Tasmota, Zigbee2MQTT, Home Assistant, JSON générique

Prévus par la même interface, planifiés après Shelly (voir
[`ROADMAP.md`](ROADMAP.md)) :

- **Tasmota** (priorité 100) : `tasmota/discovery/+/config` et `/sensors`. Un
  message retenu décrit tout ; le topic d'exploitation se recompose à partir de
  `ft`, `tp` et `t`. `mac` fait l'identité.
- **Zigbee2MQTT** (priorité 100) : `bridge/devices` donne le catalogue complet
  avec `definition.exposes` — la description de capacités la plus riche de
  l'écosystème ; le masque `access` distingue lecture, écriture et
  interrogation. `bridge/event` tient la liste à jour sans re-scan. Identité :
  `ieee_address`. `friendly_name` n'est **jamais** une identité : il change.
- **Home Assistant Discovery** (priorité 50) : les deux formes de topic,
  normalisation des 299 abréviations, forme « device » avec `cmps`. Les
  `value_template` Jinja2 sont ramenés à un chemin JSON pour les motifs
  courants ; le reste est marqué non interprétable et proposé en configuration
  manuelle, sans jamais inventer une valeur.
- **JSON générique** (priorité 10) : observation de la stabilité des clés,
  proposition de canaux, validation humaine obligatoire.

La priorité sert à trancher quand plusieurs adapters revendiquent le même
appareil : le natif constructeur l'emporte sur HA Discovery, qui l'emporte sur
le générique (§9).

---

## 9. Éviter les doublons

Quatre mécanismes, du plus large au plus fin.

**1. Identité et alias.** Un `uid` unique, un jeu d'alias. Un index
`alias → uid` est tenu côté Jeedom. Un modèle entrant dont un alias est déjà
connu est une **mise à jour**, jamais une création. C'est ce qui traite le cas
réel où Zigbee2MQTT publie à la fois `bridge/devices` et du HA Discovery, et
celui d'un Shelly vu par MQTT et par HA Discovery.

**2. Arbitrage par priorité.** L'équipement mémorise l'adapter propriétaire.
Un modèle issu d'un adapter moins prioritaire ne remplace rien : il peut
seulement enrichir les métadonnées manquantes (nom commercial, firmware, IP).
Un adapter plus prioritaire, lui, prend la main et migre les canaux en
conservant les identifiants de commande — donc l'historique et les scénarios.

**3. Empreinte de découverte.** Le `fingerprint` du modèle est stocké sur
l'équipement. Identique = zéro écriture en base. Sans cela, chaque redémarrage
du démon rejouerait toutes les découvertes retenues et réécrirait tout le parc.

**4. Unicité SQL, traitée en amont.** `cmd (eqLogic_id, name)` et
`eqLogic (name, object_id)` sont uniques (analyse §2.1) : la fabrique
déduplique les noms (`Température`, `Température 2`) **avant** d'enregistrer,
et tronque à 127 caractères. Un conflit de noms non traité fait échouer
l'enregistrement de l'équipement entier, pas seulement de la commande fautive.

**5. Ne pas se découvrir soi-même.** `jeedom/#` (le `root_topic` de MQTT
Manager, qui porte l'état de Jeedom) et le topic de réponse RPC du plugin sont
exclus par défaut. Sans cette exclusion, le moteur réimporte Jeedom dans
Jeedom.

---

## 10. La configuration manuelle

Elle n'est pas un pis-aller : c'est le mode normal du MQTT générique, et le
filet de sécurité de tous les autres cas.

- **Explorateur de topics** — arbre en direct alimenté par le mode scan :
  topic, dernière valeur, drapeau `retain`, fréquence. Le JSON est exploré
  nœud par nœud.
- **Créer une commande depuis une valeur** — on clique sur la feuille d'un
  JSON, le chemin est déduit, le type et l'unité sont proposés à partir de la
  valeur observée, la capacité est suggérée par son nom (`temperature` → °C,
  `TEMPERATURE`). Une commande, deux clics.
- **Adoption** — les candidats en `probable` ou `guess` attendent dans une
  file avec leur modèle prévisualisé ; l'utilisateur crée, ignore ou corrige.
- **Reprise en main** — tout champ modifié par l'utilisateur est marqué comme
  tel et n'est plus jamais écrasé par une re-découverte. C'est la condition
  pour qu'automatique ne veuille pas dire imprévisible.
- **Modèles utilisateur** — un équipement mis au point à la main s'exporte en
  modèle de périphérique réutilisable, applicable à un autre topic racine, et
  partageable.
- **Équipement entièrement manuel** — topic, JSON path, type, unité : le mode
  jMQTT reste disponible pour ceux qui le veulent.

---

## 11. Cohabiter avec jMQTT et MQTT Manager

MQTT est un protocole de diffusion : plusieurs clients peuvent écouter les mêmes
topics sans se gêner. Les conflits réels sont ailleurs, et se traitent :

| Risque | Traitement |
|---|---|
| `clientId` identique → déconnexions mutuelles en boucle | `clientId` unique `jeedom-mqttbe-<hachage de l'installation>` |
| Double création du même appareil dans deux plugins | détection de jMQTT/`mqtt2`/MQTT Discovery installés et actifs, avertissement explicite à la première découverte, liste des topics déjà couverts |
| Double publication d'une commande (deux plugins pilotent le même relais) | aucun risque technique, mais l'avertissement ci-dessus le signale |
| Réimport de l'état de Jeedom publié par `mqtt2` | exclusion par défaut de son `root_topic` |
| Gestion du broker en double | **on ne gère pas de broker** ; on lit sa configuration si `mqtt2` est présent (mode, port, identifiants) pour pré-remplir la nôtre |
| Messages retenus écrasés | le plugin ne publie jamais en `retain` sur un topic qu'il n'a pas créé |

Le plugin est **lecteur par défaut** : il n'écrit sur le broker que pour piloter
un équipement et pour les sondes de découverte explicitement listées
(`shellies/command`, `<prefix>/rpc`), jamais pour « organiser » l'espace de
topics.

---

## 12. Arborescence du dépôt

```
plugin_info/     info.json (hasDependency 0, hasOwnDeamon 1), install.php, configuration.php
core/
  class/         mqttbe, mqttbeCmd, mqttbeDaemon, mqttbeFactory, mqttbeRouting, mqttbeBroker
  ajax/          mqttbe.ajax.php
  php/           callback.php   (le seul point d'entrée du démon)
  config/        mqttbe.config.ini, capabilities.json, catalog/shelly/*.json
  i18n/          en_US.json
desktop/
  php/ js/       page principale, explorateur de topics, file d'adoption
  modal/         explorateur, prévisualisation d'un modèle, journal
resources/
  mqttbed/
    mqttbed.php          point d'entrée du démon
    lib/                 php-mqtt/client v2.3.2 + psr/log + myclabs/php-enum (MIT, embarqués)
    mqtt/                MqttTransport (interface) + sous-classe exposant le descripteur
    core/                Loop, Router, JeedomLink, Config
    discovery/           Engine, DeviceModel, Channel, Context
    discovery/adapters/  ShellyGen1, ShellyGen2, Tasmota, Zigbee2mqtt, HomeAssistant, GenericJson
tests/
  run.php                suite hors ligne, sans Jeedom ni broker
  fixtures/              captures réelles de messages par protocole
docs/                    ARCHITECTURE_ANALYSIS.md, ARCHITECTURE.md, ROADMAP.md, fr_FR/, en_US/
```

---

## 13. Tests

Trois niveaux, tous exécutables sans Jeedom :

1. **Transport** — la bibliothèque embarquée a ses propres tests ; les nôtres
   portent sur ce que nous ajoutons : la sous-classe de multiplexage, le
   comportement en coupure de broker (reconnexion, re-souscription, messages
   en attente), la montée de version de la bibliothèque (une capture rejouée
   doit produire exactement les mêmes appels).

2. **Adapters sur captures réelles** — `tests/fixtures/<protocole>/*.json`
   contient des messages capturés sur du vrai matériel ; le test rejoue la
   conversation (y compris les réponses RPC Shelly) et compare le modèle produit
   à un modèle attendu. Ajouter un modèle d'appareil = ajouter une capture.
3. **Conformité Jeedom** — reprise du contrôle par réflexion décrit dans
   `STRUCTURE-PLUGIN-JEEDOM.md` §8 : aucune propriété sans souligné, aucune
   méthode `set` + clé de formulaire, classe `mqttbeCmd` présente, longueurs de
   `logicalId` compatibles avec `varchar(127)`.

Un test d'endurance (rejeu d'une capture longue à débit élevé contre un
Mosquitto local) vérifie les budgets du §5.3 avant chaque version.

---

## 14. Ce que le plugin ne fera pas

Décisions assumées, pour garder le périmètre tenable :

- **installer et administrer un broker** — MQTT Manager le fait ; on le détecte
  et on le réutilise ;
- **remplacer l'interface native d'une passerelle** — appairer un Zigbee,
  configurer un Shelly, cela se fait dans Zigbee2MQTT et dans l'application
  Shelly ;
- **interpréter Jinja2 en général** — les motifs courants oui, un interpréteur
  complet non ;
- **servir de socle MQTT à d'autres plugins** — c'est le rôle de `mqtt2` ;
- **publier l'état de Jeedom vers MQTT** — hors sujet, et source de boucles.

---

## 15. Réponses, en bref

| Question | Réponse |
|---|---|
| Quelle architecture ? | Démon PHP autonome ; deux plans (chaud/froid) ; routage dans le démon ; adapters produisant un modèle de périphérique neutre ; une seule fabrique vers Jeedom |
| Quelle bibliothèque MQTT ? | `php-mqtt/client` v2.3.2 embarquée dans le dépôt (MIT), derrière une interface de transport interne : rien à installer, et le fil binaire n'est pas réécrit |
| Comment gérer le démon ? | Contrat Jeedom (`deamon_info`/`start`/`stop` + cron), callback HTTP authentifié, socket locale en retour, double battement de cœur, clé d'API par l'entrée standard |
| Comment détecter les équipements ? | Écoute des topics de découverte **et** interrogation active (RPC Shelly, `shellies/command`), avec repli sur catalogue puis sur observation |
| Comment représenter un périphérique ? | `DeviceModel` JSON versionné : identité + alias, métadonnées, disponibilité, canaux, empreinte — sans aucune notion Jeedom |
| Comment gérer les capacités ? | Vocabulaire fermé de capacités ; `capabilities.json` seul responsable de la traduction vers type/subType/`generic_type`/unité/widget |
| Comment implémenter les adapters ? | Interface à cinq méthodes + contexte autorisant publication, abonnement et attente de réponse ; priorité pour l'arbitrage |
| Comment éviter les doublons ? | `uid` + alias, arbitrage par priorité, empreinte idempotente, déduplication des noms avant écriture, exclusion des topics de Jeedom |
| Configuration manuelle ? | Explorateur de topics en direct, création de commande depuis une valeur JSON, file d'adoption, champs modifiés jamais écrasés, modèles utilisateur |
| Cohabitation ? | `clientId` distinct, aucune gestion de broker, lecture seule par défaut, détection des plugins concurrents, exclusion de leurs topics de service |
