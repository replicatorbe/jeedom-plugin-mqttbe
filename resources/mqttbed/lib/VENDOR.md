# Bibliothèques embarquées

Le démon `mqttbed` parle MQTT en PHP. Plutôt que d'exiger une installation de
dépendances chez l'utilisateur (ni `apt`, ni `pip`, ni `npm`, ni `composer`),
les trois bibliothèques nécessaires sont copiées ici et chargées par
`autoload.php`, un chargeur PSR-4 de quelques lignes.

Seul le strict nécessaire à l'exécution est copié : le dossier `src/` et le
fichier de licence. Ni tests, ni `composer.json`, ni `.github`, ni documentation.
Les sources ne sont **jamais** modifiées : toute rustine locale serait perdue à
la montée de version suivante et invisible à la relecture.

## php-mqtt/client

| | |
|---|---|
| version | **v2.3.2** (commit `97df2f5`, 28 mars 2026) |
| licence | MIT — `php-mqtt/client/LICENSE.md` |
| dépôt | https://github.com/php-mqtt/client |
| embarqué | `php-mqtt/client/src/` + `LICENSE.md` |
| espace de noms | `PhpMqtt\Client\` |

C'est le client MQTT proprement dit : connexion, souscriptions, boucle de
réception, QoS 0 à 2. Il exige PHP ≥ 8.0, ce qui correspond à la cible du
plugin (Jeedom ≥ 4.4, `requireOsVersion: 12`).

**On est volontairement sur la branche 2.x.** La branche 1.x est figée depuis
le 10 août 2023 (dernière version v1.8.1) : elle ne reçoit plus ni correctif ni
mise en conformité avec les versions récentes de PHP. Plusieurs plugins Jeedom
en circulation embarquent encore cette 1.x ; leur code ne peut pas servir de
modèle ici, les signatures ayant changé entre les deux branches (notamment
`ConnectionSettings`, devenue immuable et construite par appels chaînés).

Non embarqué et à ne pas réintroduire : `RedisRepository` n'existe plus dans la
2.x (le dépôt mémoire suffit au démon), et l'extension `ext-redis` n'est donc
pas une dépendance.

## psr/log

| | |
|---|---|
| version | **3.0.2** (commit `f16e1d5`, 11 septembre 2024) |
| licence | MIT — `psr/log/LICENSE` |
| dépôt | https://github.com/php-fig/log |
| embarqué | `psr/log/src/` + `LICENSE` |
| espace de noms | `Psr\Log\` |

Interfaces de journalisation PSR-3, exigées par `php-mqtt/client`. La série 3.x
est celle qui demande PHP ≥ 8.0 et porte les types de retour natifs ; les séries
1.x et 2.x ne servent qu'aux projets restés en PHP 7. Le démon fournit sa propre
implémentation de `LoggerInterface`, qui recopie vers le journal Jeedom.

## myclabs/php-enum

| | |
|---|---|
| version | **1.8.5** (commit `e7be269`, 14 janvier 2025) |
| licence | MIT — `myclabs/php-enum/LICENSE` |
| dépôt | https://github.com/myclabs/php-enum |
| embarqué | `myclabs/php-enum/src/Enum.php` + `LICENSE` |
| espace de noms | `MyCLabs\Enum\` |

Énumérations d'avant PHP 8.1, exigées par `php-mqtt/client` pour sa seule classe
`MessageType`. Deux fichiers du dépôt amont sont écartés :

- `src/PHPUnit/Comparator.php` : intégration PHPUnit, sans objet à l'exécution,
  et qui référence des classes absentes du plugin ;
- `stubs/Stringable.php` : bouchon pour PHP < 8.0, inutile sur notre cible.

## Monter une bibliothèque de version

1. Récupérer le dépôt amont et se placer sur l'étiquette visée
   (`git clone … && git checkout <tag>`), hors du dépôt du plugin.
2. Remplacer **en entier** le dossier concerné :
   `rm -rf <éditeur>/<nom>/src` puis copier le `src/` amont et le fichier de
   licence. Un remplacement partiel laisse traîner des fichiers supprimés en
   amont, que le chargeur trouvera quand même.
3. Réappliquer les exclusions ci-dessus (`src/PHPUnit/` pour php-enum).
4. Mettre à jour, dans ce fichier, la version, le commit, la date, et
   `LICENSES.md` si la licence amont a changé.
5. Vérifier les contraintes de version : le `composer.json` amont de
   `php-mqtt/client` indique les séries de `psr/log` et `myclabs/php-enum`
   acceptées, et la version de PHP exigée doit rester ≤ 8.0.
6. Contrôler :

   ```bash
   find resources/mqttbed/lib -name '*.php' -exec php -l {} \;
   ```

   puis charger effectivement les classes (aucune sortie hors du `OK` final) :

   ```bash
   php -d error_reporting=E_ALL -r '
     require "resources/mqttbed/lib/autoload.php";
     new PhpMqtt\Client\MqttClient("127.0.0.1", 1883, "t", PhpMqtt\Client\MqttClient::MQTT_3_1_1);
     (new PhpMqtt\Client\ConnectionSettings())->setKeepAliveInterval(60);
     PhpMqtt\Client\MessageType::PUBLISH();
     echo "OK\n";'
   ```

7. Si un espace de noms ou un chemin `src/` change en amont, corriger la table
   des préfixes en tête d'`autoload.php` — c'est le seul endroit à toucher.
8. Essai réel contre un broker avant publication : le lint ne dit rien d'un
   changement de signature, et le démon ne consomme qu'une petite part de l'API.
