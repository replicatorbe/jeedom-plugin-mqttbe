# Feuille de route — plugin mqttbe

Ordre de développement retenu après l'analyse, et **priorité explicite à
Shelly** (Gen1, Gen2, Gen3, Gen4). Tasmota, Zigbee2MQTT et Home Assistant
Discovery viennent ensuite, sans modification du noyau : c'est la condition que
l'architecture doit vérifier, et le jalon 6 sert précisément à la prouver. Le
jalon 4 bis, écrit après coup et absent du plan d'origine, l'a déjà montré une
première fois.

Chaque jalon a un critère d'acceptation vérifiable. Un jalon n'est pas terminé
tant que son critère ne passe pas sur du vrai matériel et un vrai broker.

**État au 17 septembre 2026 : jalons 0, 1, 2 et 4 terminés et éprouvés** sur un
Mosquitto 1.5.7 et un parc Shelly réel.

Deux choses ont bougé depuis, et l'ordre ci-dessous n'en rendait plus compte :

- **un adapter OpenMQTTGateway existe, qui n'était prévu nulle part.** Ces
  passerelles Bluetooth n'apparaissaient qu'au détour du critère d'acceptation
  du jalon 8, comme un sous-produit du Home Assistant Discovery. Elles publient
  en réalité sur leurs propres topics, lisibles sans qu'aucun Home Assistant ne
  tourne, et les traiter par le HA Discovery aurait attaché la découverte d'un
  parc Bluetooth à un logiciel tiers que l'utilisateur a justement le droit de
  supprimer. D'où le jalon 4 bis ci-dessous, écrit après coup ;
- **la file d'adoption du jalon 5 existe et tourne.** Elle n'a pas attendu le
  reste du jalon parce que les passerelles Bluetooth la rendaient nécessaire :
  une passerelle voit tout ce qui passe, et sans file d'adoption il n'y avait
  que deux issues, tout créer ou ne rien créer. L'explorateur de topics, lui,
  reste à écrire.

**Le jalon 3 (Shelly Gen2+) reste à faire, et il est repoussé volontairement.**
Aucun appareil Gen2+ n'était joignable au moment de l'écrire : sur les deux
présents, l'un était hors tension et l'autre, alimenté par pile, dort la plupart
du temps — son `online: true` retenu datait de sa dernière connexion. Or tout le
jalon repose sur une conversation RPC avec l'appareil : sans appareil qui
réponde, on peut l'écrire mais pas le prouver, et son critère d'acceptation est
précisément qu'un Shelly moderne apparaisse tout seul.

Il sera fait dès qu'un Gen2, Gen3 ou Gen4 pourra être mis sous tension. À
défaut, il le sera contre un Shelly émulé écrit d'après l'API officielle — avec
la réserve que cela suppose : un émulateur encode la compréhension qu'on a de
l'API et peut donc confirmer une erreur qu'on partagerait avec lui. La
validation sur matériel réel resterait à faire dans ce cas.

**Le jalon 4 (Gen1) est traité avant lui**, le parc Gen1 étant nombreux et
vivant, donc entièrement vérifiable.

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
- Capacités : **lues dans `shellies/<id>/info`**, que l'annonce déclenche elle
  aussi. Constaté sur un parc de 22 appareils : cette charge utile porte l'état
  complet — `relays[]`, `meters[]`, `emeters[]`, `inputs[]`, `temperature`,
  `ext_temperature[]`, `ext_humidity[]`, `has_update`, `wifi_sta.rssi`. Elle
  suffit donc à énumérer les capacités, et la sonde HTTP que ce jalon prévoyait
  devient inutile : un appareil qui parle déjà MQTT n'a pas à être interrogé par
  un second protocole.
- Une nuance qui compte : un SHSW-1, dépourvu de wattmètre, publie tout de même
  un `meters[]` réduit à `{"power":0,"is_valid":true}`. Un compteur réel porte
  en plus `total`, `counters` et `timestamp`. C'est cette différence qui décide,
  pas le nombre d'entrées — sinon la moitié du parc se retrouverait avec une
  commande de puissance bloquée à zéro.
- **Reste à faire sur les lampes Gen1** : les commandes de couleur ne sont pas
  encore émises. Le verrou côté plugin est levé — `#red#`, `#green#` et `#blue#`
  sont désormais substitués à partir de la couleur choisie dans Jeedom, qui la
  donne en hexadécimal alors que le Gen1 attend trois entiers — mais l'adapter
  ne produit pas encore les canaux correspondants, faute d'avoir pu les éprouver
  sur du matériel réel : aucun RGBW2 ni Duo n'est présent sur le parc d'essai.
- Le catalogue de modèles ne sert donc qu'à embellir (nom commercial, icône) et
  à corriger les rares cas où `info` ment. Son absence n'empêche jamais la
  découverte.
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

## Jalon 4 bis — Passerelles Bluetooth (OpenMQTTGateway) *(fait, hors plan)*

Ce jalon n'existait pas : il est écrit après coup, à l'endroit où il a
réellement été fait.

- Adapter `omg`, reconnaissant les passerelles à la **forme** de leurs topics —
  `<préfixe>/SYStoMQTT` et `<préfixe>/BTtoMQTT/<adresse>`, préfixe de un à trois
  niveaux — et non à un préfixe fixe : celui-ci se change dans l'interface de la
  passerelle, espaces compris.
- **Aucune dépendance à un autre système domotique.** La passerelle publie aussi
  des messages de découverte Home Assistant ; l'adapter ne les lit pas, ne s'y
  abonne pas et ne les nomme pas. Décocher « Auto discovery » dans
  OpenMQTTGateway, ou supprimer Home Assistant, ne fait rien perdre à Jeedom.
- La passerelle elle-même devient un équipement : température interne, mémoire
  libre, signal Wi-Fi, durée de fonctionnement, adresse, version, version
  disponible, état de la détection Bluetooth et ses deux durées de scan, plus
  quatre actions — redémarrer, reprendre la détection, l'arrêter, forcer un
  scan. Aucune écriture ne porte `save: true` : un clic malheureux sur « arrêter
  la détection » ne doit pas survivre au redémarrage de la passerelle.
- **L'adapter ne décode aucune trame.** Ni catalogue de balises, ni identifiant
  de constructeur, ni lecture de `manufacturerdata` : c'est la passerelle qui
  décode, et l'adapter traduit les noms de champs qu'elle produit en capacités
  (`tempc`, `hum`, `batt`, `volt`, `lux`, `pres`, `co2`, `moi`, `motion`,
  `open`…). Un champ inconnu n'est pas jeté : il devient une commande générique.
  Conséquence directe, et c'est tout l'intérêt : mettre à jour la passerelle
  fait apparaître de nouveaux capteurs sans toucher au plugin.
- Identité d'une balise par sa seule MAC (`ble:<mac>`) : **une balise vue par
  plusieurs passerelles reste un seul équipement**, avec un signal par
  passerelle et une passerelle la plus proche, élue au meilleur signal avec une
  marge de 6 dB — sans quoi une balise à mi-chemin entre deux passerelles
  changerait de pièce plusieurs fois par seconde.
- Présence déduite du silence (`discovery::bleAwayDelay`, 300 s par défaut), et
  état republié au démarrage du démon depuis sa propre branche retenue : sans
  cela, Jeedom affirmerait éternellement qu'un traceur parti depuis trois jours
  est au salon.
- Tri entre ce qui devient un équipement et ce qui part en file d'adoption : une
  balise que la passerelle sait décoder **et** dont on peut montrer qu'elle est
  chez soi — signal supérieur à -85 dBm, ou vue au moins trois fois sur plus de
  cinq minutes — est créée ; tout le reste attend une décision. Une adresse
  aléatoire n'entre même pas dans la file avant vingt minutes d'existence : une
  adresse de téléphone tourne toutes les quinze minutes et remplirait la file de
  candidats déjà morts.
- Captures anonymisées d'un parc de cinq passerelles dans `tests/fixtures/omg`,
  rejouées par `tests/check-omg.php`.

**Acceptation** : sur le parc de captures, les cinq passerelles et leurs balises
décodées apparaissent seules ; le RSSI, qui change à chaque trame, ne provoque
aucune réécriture en base ; la passerelle au préfixe dupliqué ne crée pas
d'équipement fantôme ; une balise vue par trois passerelles est un équipement et
non trois ; une balise silencieuse est déclarée absente au bout du délai réglé.
**Aucune ligne du noyau, du modèle ou de la fabrique n'a été modifiée** pour
ajouter cet adapter — c'est, par anticipation, le test que le jalon 6 devait
faire passer.

---

## Jalon 5 — Explorateur, adoption, reprise en main *(à moitié fait)*

**Fait**, parce que les passerelles Bluetooth du jalon 4 bis ne pouvaient pas
s'en passer :

- File d'adoption pour les candidats `guess` — un candidat `probable` reste
  créé d'office : c'est « je sais ce que c'est, je ne le connais pas encore tout
  à fait », le cas d'un Shelly annoncé dont l'état complet n'est pas arrivé.
  La file est bornée à cinquante entrées et périme au bout d'une semaine ;
  quand elle déborde, elle est coupée au plus intéressant et non au plus
  récent — durée de présence, augmentée d'une heure fictive pour une adresse
  qui n'est pas aléatoire.
- Fenêtre d'adoption (`desktop/modal/adoption.php`) : ce qu'on sait de chaque
  candidat, depuis quand on le voit, sa dernière trame, le nombre de commandes
  que sa création produirait, et deux boutons — créer, écarter.
- Les refus vivent en configuration et non en cache : une décision doit
  survivre à un vidage de cache et partir dans les sauvegardes. Un refus vaut
  aussi contre la création automatique, sans quoi l'appareil écarté serait créé
  d'office dès que sa confiance monte. Revenir sur un refus est toujours
  possible.
- Plafond global de création (`discovery::maxDevices`, 250 par défaut) : au-delà,
  la découverte cesse d'écrire et met en attente. La découverte se fonde sur ce
  qui circule sur le broker, et rien n'oblige ce qui circule à être honnête.
- Marquage des champs modifiés par l'utilisateur : jamais réécrits par une
  re-découverte.

**Reste à faire** :

- Mode scan borné en durée et en mémoire, arbre de topics en direct
  (valeur, `retain`, fréquence).
- Création d'une commande depuis une feuille de JSON : chemin déduit, type,
  unité et capacité proposés.
- Prévisualisation du modèle avant création, dans la fenêtre d'adoption : on y
  lit aujourd'hui le nombre de commandes, pas leur liste.
- Export d'un équipement en modèle réutilisable.

**Acceptation** : un appareil MQTT quelconque, non pris en charge, devient un
équipement complet en moins de dix clics ; une re-découverte ne perd aucune
retouche manuelle. La seconde moitié du critère passe ; la première attend
l'explorateur.

---

## Jalon 6 — Tasmota

`tasmota/discovery/+/config` et `/sensors`, recomposition des topics à partir
de `ft`, `tp` et `t`, relais, interrupteurs, boutons, lumière et capteurs.
Identité par `mac`.

**Acceptation** : un Tasmota est découvert complètement sans intervention ;
**aucune ligne du noyau, du modèle ou de la fabrique n'a été modifiée** pour
l'ajouter. C'est le test de l'extensibilité promise par l'architecture — et le
jalon 4 bis l'a déjà fait passer une première fois, sur un protocole que rien
n'avait prévu.

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

**OpenMQTTGateway sort de ce jalon** : il y figurait comme un sous-produit, et
il est traité nativement depuis le jalon 4 bis. C'était une erreur de le ranger
ici. Faire dépendre la découverte d'un parc Bluetooth du protocole d'annonce de
Home Assistant, c'est la faire dépendre d'une case à cocher dans la passerelle
et d'un logiciel tiers que l'utilisateur a le droit de désinstaller. Le jalon
gagne en revanche un cas d'arbitrage de plus : une passerelle qui publie à la
fois ses propres topics et du HA Discovery ne doit produire qu'un équipement,
et c'est l'adapter natif qui l'emporte.

**Acceptation** : ESPHome, Z-Wave JS UI et Theengs découverts ; aucun doublon
avec l'adapter Zigbee2MQTT ni avec l'adapter OpenMQTTGateway.

---

## Jalon 9 — JSON générique

Observation de la stabilité des clés, proposition de canaux, validation humaine
obligatoire. Aucun équipement créé automatiquement à ce niveau de confiance.

---

## Jalon 10 — Version 1.0

- Documentation utilisateur `docs/fr_FR/index.md` et `docs/en_US/index.md`,
  changelog. Elle suit les livraisons plutôt que d'attendre la 1.0 : elle sert
  le bouton « Documentation » du plugin, et une fonctionnalité livrée sans une
  ligne écrite n'a pour toute source que les trois paragraphes de sa fenêtre.
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
