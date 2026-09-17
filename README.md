# Plugin Jeedom — MQTT BE

Relie Jeedom à un broker MQTT et découvre tout seul les équipements qui s'y
annoncent. L'utilisateur ne saisit que l'adresse du broker : ni topic, ni modèle
d'équipement à écrire.

## État d'avancement

| Jalon | Contenu | État |
|---|---|---|
| 0 | Le plugin s'installe, s'affiche, ne casse rien | en cours |
| 1 | Le démon se connecte au broker, tient la liaison, se reconnecte, et l'interface montre son état | en cours |
| 2 | Découverte des Shelly (Gen1 et Gen2+), création des équipements et des commandes | à venir |
| 3 | Tasmota, Zigbee2MQTT, Home Assistant Discovery | à venir |

Aucun équipement n'est créé avant le jalon 2.

## Structure

```
plugin_info/     info.json, install.php, configuration.php, icône
core/class/      mqttbe.class.php — eqLogic, cmd et pilotage du démon
core/php/        callback.php — le démon y pousse ses messages
core/ajax/       mqttbe.ajax.php — actions de l'interface
core/config/     mqttbe.config.ini — valeurs par défaut, section [mqttbe]
desktop/         page principale et son JS
resources/mqttbed/  le démon, processus autonome
docs/            documentation fr_FR et en_US
```

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

## Trois pièges à ne pas réintroduire

1. Toute propriété d'instance de `mqttbe` ou `mqttbeCmd` commence par `_` :
   `DB::save()` traite les autres comme des colonnes SQL.
2. Aucune méthode nommée `set` + clé de formulaire — jamais `setCmd()` :
   `utils::a2o()` les appelle à l'enregistrement, et l'erreur est fatale avant
   toute écriture.
3. La section de `core/config/mqttbe.config.ini` est `[mqttbe]`, pas
   `[default]`, sinon le fichier est totalement inerte.

Voir `/home/smug/dev/STRUCTURE-PLUGIN-JEEDOM.md`, section 8.

## Développement

Le dépôt est la source de vérité ; `/var/www/html/plugins/mqttbe` n'en est
qu'une copie, écrasée à chaque déploiement :

```bash
/home/smug/dev/tools/deploy-plugin.sh
```

## Licence

AGPL v3.
