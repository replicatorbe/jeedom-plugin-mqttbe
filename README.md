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
| 3 | Shelly Gen2 / Gen3 / Gen4 | repoussé |
| 4 | Shelly Gen1 | fait |
| 5 | Explorateur de topics, adoption, reprise en main | à venir |
| 6 à 8 | Tasmota, Zigbee2MQTT, Home Assistant Discovery | à venir |
| 9 à 10 | JSON générique, version 1.0 | à venir |

Les jalons 0, 1, 2 et 4 ont été éprouvés sur un Mosquitto 1.5.7 et un parc Shelly
Gen1 réel : 22 appareils, 198 commandes créées sans saisie.

**Le jalon 3 est repoussé volontairement**, et le 4 traité avant lui. La
découverte des Shelly Gen2+ repose entièrement sur une conversation RPC avec
l'appareil ; aucun Gen2, Gen3 ou Gen4 n'était joignable au moment de l'écrire —
l'un hors tension, l'autre sur pile et endormi. On peut écrire l'adapter, on ne
peut pas le prouver, et son critère d'acceptation est précisément qu'un Shelly
moderne apparaisse tout seul. Le parc Gen1, lui, est nombreux et vivant, donc
entièrement vérifiable : il est passé devant.

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
desktop/            page principale et son JS
resources/mqttbed/  le démon, processus autonome
  core/             boucle, journal, configuration, liaison Jeedom, routeur
  mqtt/             interface de transport et son implémentation
  discovery/        moteur de découverte, modèle, canaux
    adapters/       ShellyGen1.php — le seul adapter existant à ce jour
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

`tests/run.php` regroupe six sections : conformité aux conventions du cœur,
contrat démon ↔ Jeedom, clés de configuration, vocabulaire des capacités, moteur
de découverte, découverte Shelly Gen1. Ce qu'elles attrapent n'a rien d'exotique —
une propriété sans souligné, un nom de commande écrit différemment de part et
d'autre du démon, un type générique inventé — mais aucun de ces défauts ne se voit
à la relecture ni au `php -l`, et tous se manifestent bien plus tard sous la forme
d'un symptôme qui ne désigne pas sa cause.

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
