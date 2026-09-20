# Faire d'un Shelly une passerelle Bluetooth

Un Shelly de deuxième génération ou plus récent porte une radio Bluetooth et
sait exécuter un script. Il peut donc rapporter les balises qu'il entend, comme
le ferait une passerelle OpenMQTTGateway — sans acheter de matériel de plus, et
en profitant du fait qu'il y en a déjà un dans chaque pièce.

Le plugin ne fait aucune différence entre les deux : il ne connaît pas les
appareils, il lit des topics.

## Ce que le plugin attend

Deux topics, sous un préfixe que vous choisissez.

**L'identité**, sur `<préfixe>/SYStoMQTT`. C'est la seule source de l'adresse
matérielle, et l'adresse matérielle est ce qui fait l'équipement. Sans ce
message, rien n'est créé — le plugin préfère une passerelle absente de la liste
à un équipement fantôme qui reviendrait sous un autre nom à chaque redémarrage.

```json
{"mac":"D0CF13C4FC68","ip":"192.168.30.185","version":"2.0.0",
 "env":"Shelly","uptime":7355,"freemem":146228,"rssi":-66,"tempc":57.8}
```

Seule la `mac` est obligatoire. Les autres champs deviennent chacun une
commande : adresse IP, version, durée de fonctionnement, mémoire libre, signal
Wi-Fi, température interne.

**Les trames**, sur `<préfixe>/BTtoMQTT/<adresse de la balise>`, une par balise
entendue. Le plugin y lit ce qui s'y trouve ; il ne décode rien lui-même.

```json
{"id":"d3:46:4c:e6:95:4b","rssi":-62,"brand":"Tile",
 "model":"Smart Tracker","model_id":"TILE","type":"TRACK"}
```

## L'annonce doit être répétée, et ce n'est pas un détail

**C'est le piège de cette configuration, et il coûte cher à diagnostiquer.**

Au démarrage de l'appareil, le script s'exécute **avant** que la connexion MQTT
ne soit établie. Un script qui publie son identité une seule fois, au
démarrage, la publie donc dans le vide — et ne réessaie jamais. Le scan
Bluetooth, lui, démarre juste après et trouve la connexion prête quand les
premières balises passent.

Le résultat est trompeur au possible : **les balises remontent parfaitement,
mais la passerelle n'existe nulle part**. On cherche un équipement manquant
alors que tout semble fonctionner.

Sur trois appareils portant le même script, deux avaient été découverts et le
troisième jamais — la seule différence étant l'ordre de démarrage. Le plugin
signale désormais ce cas au journal, au bout d'une demi-heure :

> la passerelle « Mini1G3Vestiaire » publie des trames Bluetooth mais n'a pas
> publié son identité sur « Mini1G3Vestiaire/SYStoMQTT » depuis le démarrage du
> démon

Une passerelle OpenMQTTGateway republie son identité toutes les deux minutes.
Faites-en autant : c'est une ligne, et elle rend la passerelle redécouvrable
après n'importe quel incident — broker redémarré, messages retenus effacés,
démon relancé.

## Le script

Une seule ligne change d'un appareil à l'autre : `MQTT_BASE`. Collez le tout
dans l'éditeur de script du Shelly, **enregistrez**, puis **arrêtez et
relancez** le script — l'enregistrement seul ne recharge pas le code en cours
d'exécution.

```js
// ============================================================
//  Shelly -> passerelle Bluetooth (émulation OpenMQTTGateway)
//  Publie les balises Tile vues par la radio BLE de l'appareil.
//
//  À ADAPTER SUR CHAQUE APPAREIL : MQTT_BASE, et lui seul.
// ============================================================

let MQTT_BASE = "ShellySalon";

// Réannonce de la passerelle. L'annonce d'identité ne peut pas partir une
// seule fois : au démarrage de l'appareil, le script s'exécute AVANT que MQTT
// ne soit connecté, et l'annonce se perd sans que rien ne la rejoue. Deux
// minutes, c'est ce que fait une vraie passerelle OpenMQTTGateway.
let GATEWAY_INTERVAL_MS = 120000;

// true pour voir chaque trame dans la console. À laisser à false en service :
// le scan est continu et la console devient illisible.
let DEBUG = false;

let DEVICE = Shelly.getDeviceInfo();

function toHex(data) {
    if (data === undefined || data === null) {
        return "";
    }

    let result = "";

    for (let i = 0; i < data.length; i++) {
        let h = data.charCodeAt(i).toString(16);

        if (h.length === 1) {
            h = "0" + h;
        }

        result = result + h;
    }

    return result.toUpperCase();
}

function cleanMac(mac) {
    let result = "";

    for (let i = 0; i < mac.length; i++) {
        if (mac.charAt(i) !== ":") {
            result = result + mac.charAt(i);
        }
    }

    return result.toUpperCase();
}

function publishGateway() {

    if (!MQTT.isConnected()) {
        // Pas de message, pas d'abandon : le timer repassera dans deux minutes.
        return;
    }

    let msg = {
        mac: DEVICE.mac,
        ip: "",
        version: DEVICE.ver,
        env: "Shelly",
        uptime: 0
    };

    // Durée de fonctionnement et mémoire libre.
    let sys = Shelly.getComponentStatus("sys");

    if (sys !== undefined && sys !== null) {
        if (sys.uptime !== undefined) {
            msg.uptime = sys.uptime;
        }
        if (sys.ram_free !== undefined) {
            msg.freemem = sys.ram_free;
        }
    }

    // Adresse IP (elle donne le lien « ouvrir l'interface » côté Jeedom)
    // et niveau de signal Wi-Fi.
    let wifi = Shelly.getComponentStatus("wifi");

    if (wifi !== undefined && wifi !== null) {
        if (wifi.sta_ip !== undefined && wifi.sta_ip !== null) {
            msg.ip = wifi.sta_ip;
        }
        if (wifi.rssi !== undefined) {
            msg.rssi = wifi.rssi;
        }
    }

    // Température interne, quand l'appareil en expose une.
    let sw = Shelly.getComponentStatus("switch:0");

    if (sw !== undefined && sw !== null && sw.temperature !== undefined) {
        if (sw.temperature.tC !== undefined) {
            msg.tempc = sw.temperature.tC;
        }
    }

    let json = JSON.stringify(msg);

    print("GATEWAY: " + json);

    MQTT.publish(
        MQTT_BASE + "/SYStoMQTT",
        json,
        0,
        true
    );
}

function publishTile(result, tileData) {

    let mac = result.addr;

    let msg = {
        id: mac,
        rssi: result.rssi,
        servicedatauuid: "0xfeed",
        servicedata: toHex(tileData),
        brand: "Tile",
        model: "Smart Tracker",
        model_id: "TILE",
        type: "TRACK",
        device: "Tile Tracker"
    };

    let json = JSON.stringify(msg);

    if (DEBUG) {
        print("TILE: " + json);
    }

    if (MQTT.isConnected()) {
        MQTT.publish(
            MQTT_BASE + "/BTtoMQTT/" + cleanMac(mac),
            json
        );
    }
}

function onScan(event, result) {

    if (event !== BLE.Scanner.SCAN_RESULT) {
        return;
    }

    if (result.service_data === undefined ||
        result.service_data === null) {
        return;
    }

    let tileData = result.service_data["feed"];

    if (tileData === undefined) {
        return;
    }

    publishTile(result, tileData);
}

print("================================");
print("Shelly OpenMQTTGateway emulator");
print("Name : " + DEVICE.name);
print("MAC  : " + DEVICE.mac);
print("Model: " + DEVICE.model);
print("FW   : " + DEVICE.ver);
print("Base : " + MQTT_BASE);
print("================================");

// Découverte de la passerelle : tout de suite, puis sans relâche.
publishGateway();
Timer.set(GATEWAY_INTERVAL_MS, true, publishGateway);

// Scan BLE
BLE.Scanner.Start(
    {
        duration_ms: BLE.Scanner.INFINITE_SCAN,
        active: false,
        interval_ms: 240,
        window_ms: 80
    },
    onScan
);

print("Tile scanner started");
```

Vous saurez que le bon script tourne à la première ligne `GATEWAY:` de la
console : **une seule ligne**, contenant l'adresse IP, `freemem`, `rssi` et
`tempc`. La passerelle apparaît dans Jeedom dans les deux minutes.

## Ce que ce script fait, et ce qu'il ne fait pas

**Il ne rapporte que les traceurs Tile.** C'est ce que fait `onScan` : il ne
regarde qu'une seule donnée de service et ignore tout le reste. Une vraie
passerelle OpenMQTTGateway embarque Theengs et reconnaît des centaines de
capteurs — thermomètres, hygromètres, capteurs de plante, pèse-personnes. Pour
en rapporter d'autres ici, il faudrait écrire leur décodage soi-même, dans le
script. **Ce montage complète une passerelle OpenMQTTGateway, il ne la remplace
pas.**

**Trois commandes resteront vides**, et c'est normal :

- *Connectée* dépend du testament MQTT, un message que le broker publie à votre
  place quand l'appareil disparaît. Il se règle à la connexion, pas depuis un
  script. Publier « connectée » depuis le script donnerait une commande bloquée
  sur cette valeur pour toujours — pire que vide ;
- *Dernière version disponible* vient d'un topic que ce script ne publie pas ;
- *Intervalle entre scans* et *Durée de scan* n'ont pas de sens avec un scan en
  mode continu.

De même, les boutons **Redémarrer** et **couper la détection Bluetooth** ne font
rien : ils publient des ordres sur des topics auxquels le script ne s'abonne
pas.

## Deux choses à savoir

**Votre Shelly devient deux équipements**, et c'est voulu : le relais d'un côté,
la passerelle de l'autre. Ils portent la même adresse matérielle mais des rôles
et des topics différents. Ne cherchez pas à les fusionner.

**Le message d'identité est publié retenu** (le dernier paramètre `true` du
`MQTT.publish`). C'est ce qui rend la passerelle robuste : le broker le rejoue à
chaque abonnement. Mais cela a un revers — si vous supprimez l'équipement
passerelle dans Jeedom, **il reviendra au prochain redémarrage du démon**, tant
que le message retenu est sur le broker. Pour décommissionner une passerelle
pour de bon, effacez aussi ce topic retenu.
