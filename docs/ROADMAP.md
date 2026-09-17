# Feuille de route — plugin mqttbe

Ordre de développement retenu après l'analyse, et **priorité explicite à
Shelly** (Gen1, Gen2, Gen3, Gen4). Tasmota, Zigbee2MQTT et Home Assistant
Discovery viennent ensuite, sans modification du noyau : c'est la condition que
l'architecture doit vérifier, et le jalon 5 sert précisément à la prouver.

Chaque jalon a un critère d'acceptation vérifiable. Un jalon n'est pas terminé
tant que son critère ne passe pas sur du vrai matériel et un vrai broker.

**État au 17 septembre 2026 : jalons 0, 1 et 2 terminés et éprouvés** sur un
Mosquitto 1.5.7 et un parc Shelly réel. Le jalon 3 (découverte Shelly Gen2+ par
RPC) est le prochain.

---

## Jalon 0 — Socle du dépôt

- Squelette de plugin conforme (`plugin_info/info.json` avec `id: mqttbe`,
  `hasDependency: 0`, `hasOwnDeamon: 1`, `require: 4.4`,
  `requireOsVersion: 12`), `LICENSE`, `.gitignore`, `.deployignore`, workflow
  d'intégration continue (lint PHP 8.0 à 8.4).
- `resources/mqttbed/lib/` : `php-mqtt/client` v2.3.2, `psr/log` et
  `myclabs/php-enum` embarqués (MIT), avec un autoloader PSR-4 minimal et un
  fichier `VENDOR.md` notant les versions figées et la procédure de montée.
- Dépôt git, branche `beta` par défaut, `master` comme canal stable.
- `tests/run.php` et le contrôle de conformité Jeedom par réflexion
  (propriétés soulignées, pas de méthode `set` + clé de formulaire, classe
  `mqttbeCmd` présente).
- Déploiement vers `/var/www/html/plugins/mqttbe` par
  `tools/deploy-plugin.sh`.

**Acceptation** : le plugin s'installe depuis GitHub, apparaît au menu, la page
principale s'ouvre vide sans erreur dans `/var/www/html/log/http.error`.

---

## Jalon 1 — Client MQTT et démon

- Transport : intégration de la bibliothèque embarquée derrière l'interface
  interne `MqttTransport`, sous-classe exposant le descripteur pour le
  multiplexage, réglages de connexion (TLS, CA, certificat client, `will`,
  keepalive, `clientId` unique), reconnexion et re-souscription.
- Démon `mqttbed.php` : boucle `stream_select`, fichier PID, signaux,
  journalisation par niveau.
- Canaux : POST par lots vers `core/php/callback.php` (clé d'API par l'entrée
  standard), socket de commande locale en retour.
- `deamon_info` / `deamon_start` / `deamon_stop` / `cron()` chien de garde.
- Page de configuration : adresse du broker, port, TLS, identifiants, test de
  connexion. Détection automatique d'un broker local et reprise de la
  configuration de MQTT Manager s'il est installé.
- Tests du transport : coupure de broker, reconnexion, messages en attente,
  et rejeu d'une capture garantissant qu'une montée de version de la
  bibliothèque ne change pas le comportement observable.
**Acceptation** : connexion à Mosquitto (clair et TLS), 24 h sans fuite ni
déconnexion non rattrapée, coupure du broker et reprise automatique, démon tué
de force et relancé par le cron en moins de 60 s.

---

## Jalon 2 — Modèle, capacités, fabrique, routage

- `DeviceModel` et `Channel`, sérialisation JSON versionnée.
- `capabilities.json` : vocabulaire de capacités et correspondance vers
  type/subType/`generic_type`/unité/widget/visibilité/historisation.
- `mqttbeFactory` : création et mise à jour idempotentes, déduplication des
  noms avant écriture, respect des champs modifiés par l'utilisateur,
  empreinte de découverte.
- `mqttbeRouting` : construction de la table topic → canal et poussée au démon
  à l'enregistrement, au démarrage et à la demande.
- Plan chaud complet : sélecteurs (brut, chemin JSON), transformations
  (échelle, arrondi, correspondance), filtre de répétition, lots.
- Un équipement créé à la main de bout en bout fonctionne (info et action).

**Acceptation** : 10 000 messages rejoués, chaque valeur arrive sur la bonne
commande ; latence médiane mesurée et inscrite au journal ; aucune écriture en
base sur re-réception d'une découverte identique.

---

## Jalon 3 — Shelly Gen2 / Gen3 / Gen4 *(le cœur du sujet)*

- Adapter `shelly.gen2` : écoute de `+/online` et `+/events/rpc` — les deux
  seuls topics actifs par défaut sur un Shelly moderne.
- Conversation RPC sur MQTT : `Shelly.GetDeviceInfo`, puis
  `Shelly.GetComponents` (paginé, avec `status` et `config`), repli
  `Shelly.GetStatus` + `Shelly.GetConfig` pour les firmwares plus anciens.
- Modélisation des composants : `switch`, `cover`, `light`, `rgb`, `rgbw`,
  `cct`, `input`, `pm1`, `em`, `em1`, `emdata`, `temperature`, `humidity`,
  `voltmeter`, `devicepower`, `smoke`, `flood`, `gas`, composants virtuels
  (`boolean`, `number`, `text`, `enum`). Le `profile` du périphérique tranche
  `switch` contre `cover`.
- Lecture par `NotifyStatus`, écriture par RPC (`Switch.Set`, `Switch.Toggle`,
  `Cover.GoToPosition`, `Light.Set`…), disponibilité par `<prefix>/online`.
- `NotifyEvent` → commandes d'événement (`btn_down`, `single_push`,
  `double_push`, `long_push`) en `BUTTON`.
- Catalogue Shelly **décoratif** : nom commercial, icône, ordre — jamais
  nécessaire à la découverte.
- Captures de conversation réelles dans `tests/fixtures/shelly/gen2/`.

**Acceptation** : un Shelly Plus/Pro/Mini branché sur le broker apparaît dans
Jeedom **sans aucune action de l'utilisateur et sans modifier la configuration
du Shelly** (`status_ntf` reste à sa valeur d'usine), avec toutes ses
commandes, leurs types génériques et leurs unités. Un modèle absent du
catalogue est découvert aussi complètement qu'un modèle connu — c'est le test
qui valide toute l'approche.

---

## Jalon 4 — Shelly Gen1

- Adapter `shelly.gen1` : `shellies/announce`, `shellies/+/online`, et
  provocation d'annonce par `shellies/command`.
- Capacités : sonde HTTP facultative sur l'IP annoncée (`/shelly`,
  `/settings`) pour le nombre de relais, le mode `relay`/`roller` et la
  présence d'un compteur ; sinon catalogue Gen1 ; sinon observation des topics
  reçus, avec un modèle `probable` soumis à l'adoption.
- Identité `shelly:<mac>`, commune à Gen1 et Gen2+ : un parc mixte ne produit
  jamais de doublon.
- Modèles couverts en priorité : `SHSW-1`, `SHSW-PM`, `SHSW-25` (relais et
  volet), `SHPLG-S`, `SHDM-2`, `SHHT-1`, `SHWT-1`, `SHBTN-2`, `SHEM`,
  `SHRGBW2`.
- **Ne jamais supposer qu'un topic Gen1 existe.** Constaté sur le parc d'essai :
  `shellies/<id>/online` n'est publié que par les micrologiciels 1.6 et suivants —
  deux appareils sur trois l'avaient, le Shelly EM non. Un canal déclaré d'après
  le modèle mais jamais alimenté produit une commande muette, que l'utilisateur
  prendra pour une panne du plugin. La disponibilité d'un Gen1 doit donc être
  déduite de ce qu'il publie réellement, pas de ce que son modèle laisse espérer.

**Acceptation** : un parc mixte Gen1 + Gen2+ se découvre entièrement, un
Shelly 2.5 en mode volet produit un volet (et non deux relais), aucun appareil
n'apparaît deux fois.

---

## Jalon 5 — Explorateur, adoption, reprise en main

- Mode scan borné en durée et en mémoire, arbre de topics en direct
  (valeur, `retain`, fréquence).
- Création d'une commande depuis une feuille de JSON : chemin déduit, type,
  unité et capacité proposés.
- File d'adoption pour les candidats `probable` et `guess`, avec
  prévisualisation du modèle avant création.
- Marquage des champs modifiés par l'utilisateur : jamais réécrits par une
  re-découverte.
- Export d'un équipement en modèle réutilisable.

**Acceptation** : un appareil MQTT quelconque, non pris en charge, devient un
équipement complet en moins de dix clics ; une re-découverte ne perd aucune
retouche manuelle.

---

## Jalon 6 — Tasmota

`tasmota/discovery/+/config` et `/sensors`, recomposition des topics à partir
de `ft`, `tp` et `t`, relais, interrupteurs, boutons, lumière et capteurs.
Identité par `mac`.

**Acceptation** : un Tasmota est découvert complètement sans intervention ;
**aucune ligne du noyau, du modèle ou de la fabrique n'a été modifiée** pour
l'ajouter. C'est le test de l'extensibilité promise par l'architecture.

---

## Jalon 7 — Zigbee2MQTT

`bridge/devices` (catalogue complet via `definition.exposes`), `bridge/event`
(ajout, retrait, renommage à chaud), disponibilité, `/set` et `/get`.
Traduction du masque `access` et des types composés (`light`, `switch`,
`climate`, `cover`, `fan`, `lock`). Identité par `ieee_address` ; le
`friendly_name` n'est jamais une identité.

**Acceptation** : un parc Zigbee complet apparaît avec ses types génériques ;
renommer un appareil dans Zigbee2MQTT ne casse ni ne duplique l'équipement
Jeedom.

---

## Jalon 8 — Home Assistant Discovery

Les deux formes de topic (par composant et « device »), normalisation des 299
abréviations, `dev`/`o`/`cmps`, disponibilité, reconnaissance des motifs Jinja2
courants et marquage explicite des `value_template` non interprétables.
Arbitrage de priorité avec les adapters natifs — cas de test principal :
Zigbee2MQTT publiant simultanément `bridge/devices` et du HA Discovery.

**Acceptation** : ESPHome, Z-Wave JS UI, OpenMQTTGateway et Theengs découverts ;
aucun doublon avec l'adapter Zigbee2MQTT.

---

## Jalon 9 — JSON générique

Observation de la stabilité des clés, proposition de canaux, validation humaine
obligatoire. Aucun équipement créé automatiquement à ce niveau de confiance.

---

## Jalon 10 — Version 1.0

- Documentation utilisateur `docs/fr_FR/index.md` et `docs/en_US/index.md`,
  changelog.
- Traductions `core/i18n/en_US.json`.
- Sauvegarde et restauration, page de santé, diagnostic
  (broker joignable, démon vivant, dernier message reçu par adapter).
- Test d'endurance : 7 jours, parc réel, mesures de mémoire et de latence
  publiées dans la documentation.
- Publication au Market (canal beta d'abord).

---

## Hors périmètre de la version 1

MQTT 5 et les souscriptions partagées, le transport WebSocket, QoS 2 à
l'émission, l'administration d'un broker, la publication de l'état de Jeedom
vers MQTT, l'interprétation générale de Jinja2, le rôle de socle MQTT pour
d'autres plugins.

Ces points sont écartés parce qu'ils ne servent pas la priorité n°1 — la
simplicité pour l'utilisateur —, pas parce qu'ils seraient difficiles. Le
client MQTT et le contrat d'adapter sont écrits pour qu'ils restent des ajouts.
