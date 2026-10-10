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
entendue, au format d'OpenMQTTGateway. Un traceur Tile :

```json
{"id":"d3:46:4c:e6:95:4b","rssi":-62,"brand":"Tile",
 "model":"Smart Tracker","model_id":"TILE","type":"TRACK"}
```

Toute autre balise, ici un Shelly BLU H&T :

```json
{"id":"C0:2C:ED:42:9C:14","mac_type":0,"name":"SBHT-203C",
 "manufacturerdata":"a90b0101000b11000a149c42ed2cc0","rssi":-60,
 "servicedata":"40000a01642e3b45d300","servicedatauuid":"0xfcd2"}
```

`mac_type` n'est pas un détail : 0 pour une adresse publique, 1 pour une adresse
aléatoire. Sans lui, le plugin prend toute adresse pour fixe, et un téléphone,
dont l'adresse tourne tous les quarts d'heure, serait proposé à l'adoption sous
une nouvelle identité à chaque rotation.

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
//  Publie les balises Tile vues par la radio BLE de l'appareil,
//  et relaie toutes les autres trames au format OpenMQTTGateway.
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

// Les balises autres que Tile. true : toutes, comme une vraie passerelle
// OpenMQTTGateway. false : seulement les capteurs BTHome (Shelly BLU…).
let RELAY_ALL = true;

// Une même balise n'est relayée qu'une fois par intervalle : le scan voit
// chaque appareil toutes les trois secondes, et le plugin n'a besoin que d'un
// signal frais à la minute. Une NOUVELLE mesure BTHome part tout de suite.
let RELAY_INTERVAL_MS = 10000;

// En dessous de ce signal, la balise n'est pas relayée (voisinage lointain).
// -100 : tout passe.
let RELAY_MIN_RSSI = -100;

// Nombre de balises dont on retient la dernière publication. Au-delà, la plus
// ancienne est oubliée : elle sera simplement relayée un peu plus tôt.
let TRACK_SIZE = 40;

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

// ------------------------------------------------------------
//  Toutes les autres balises
// ------------------------------------------------------------

let trackMac = [];
let trackTime = [];
let trackSig = [];
let trackNext = 0;

// Hexadécimal en minuscules, d'une partie d'une chaîne binaire : c'est
// l'écriture d'OpenMQTTGateway pour manufacturerdata et servicedata.
function hexRange(data, start, end) {
    let result = "";

    for (let i = start; i < end; i++) {
        let h = data.charCodeAt(i).toString(16);

        if (h.length === 1) {
            h = "0" + h;
        }

        result = result + h;
    }

    return result;
}

// Lit les structures d'une annonce (longueur, type, données) et garde ce
// qu'OpenMQTTGateway publie : données constructeur et données de service.
// Les données constructeur gardent leur identifiant en tête, en petit-
// boutiste (« a90b… » pour Shelly), exactement comme OpenMQTTGateway.
function parseAdv(data, out) {
    if (data === undefined || data === null) {
        return;
    }

    let i = 0;

    while (i < data.length) {
        let len = data.charCodeAt(i);

        if (len === 0 || i + 1 + len > data.length) {
            return;
        }

        let type = data.charCodeAt(i + 1);
        let start = i + 2;
        let end = i + 1 + len;

        if (type === 0xFF && out.mfd === "" && end > start) {
            out.mfd = hexRange(data, start, end);
        }

        // Données de service, UUID 16 bits. BTHome (fcd2) l'emporte sur
        // toute autre : c'est la seule que le plugin sache lire.
        if (type === 0x16 && end - start >= 2) {
            let uuid = hexRange(data, start + 1, start + 2) + hexRange(data, start, start + 1);

            if (out.svcUuid === "" || uuid === "fcd2") {
                out.svcUuid = uuid;
                out.svc = hexRange(data, start + 2, end);
            }
        }

        i = end;
    }
}

// Retient la dernière publication d'une balise. Rend true si elle doit
// partir maintenant.
function mustRelay(mac, sig, now) {
    for (let i = 0; i < trackMac.length; i++) {
        if (trackMac[i] === mac) {
            let fresh = sig !== "" && sig !== trackSig[i];

            if (!fresh && now - trackTime[i] < RELAY_INTERVAL_MS) {
                return false;
            }

            trackTime[i] = now;
            trackSig[i] = sig;
            return true;
        }
    }

    trackMac[trackNext] = mac;
    trackTime[trackNext] = now;
    trackSig[trackNext] = sig;
    trackNext = (trackNext + 1) % TRACK_SIZE;
    return true;
}

function publishOther(result) {

    if (result.rssi < RELAY_MIN_RSSI) {
        return;
    }

    let bthome = undefined;

    if (result.service_data !== undefined && result.service_data !== null) {
        bthome = result.service_data["fcd2"];
    }

    if (!RELAY_ALL && bthome === undefined) {
        return;
    }

    // Une nouvelle mesure BTHome part sans attendre ; la même mesure, répétée
    // par le capteur, attend l'intervalle comme le reste.
    let sig = (bthome === undefined) ? "" : toHex(bthome);

    if (!mustRelay(result.addr, sig, Shelly.getUptimeMs())) {
        return;
    }

    let adv = { mfd: "", svcUuid: "", svc: "" };

    parseAdv(result.advData, adv);
    parseAdv(result.scanRsp, adv);

    // Même ordre de champs qu'une passerelle OpenMQTTGateway.
    let msg = { id: result.addr.toUpperCase() };

    // 0 = adresse publique, 1 = aléatoire. Le plugin s'en sert pour ne pas
    // créer un équipement par adresse tournante de téléphone.
    if (result.addr_type === 1) {
        msg.mac_type = 0;
    } else if (result.addr_type === 2 || result.addr_type === 3 || result.addr_type === 4) {
        msg.mac_type = 1;
    }

    if (result.local_name !== undefined && result.local_name !== null && result.local_name !== "") {
        msg.name = result.local_name;
    }

    if (adv.mfd !== "") {
        msg.manufacturerdata = adv.mfd;
    }

    msg.rssi = result.rssi;

    if (result.tx_power_level !== undefined && result.tx_power_level !== null) {
        msg.txpower = result.tx_power_level;
    }

    if (adv.svcUuid !== "") {
        msg.servicedata = adv.svc;
        msg.servicedatauuid = "0x" + adv.svcUuid;
    }

    let json = JSON.stringify(msg);

    if (DEBUG) {
        print("BLE: " + json);
    }

    if (MQTT.isConnected()) {
        MQTT.publish(
            MQTT_BASE + "/BTtoMQTT/" + cleanMac(result.addr),
            json
        );
    }
}

function onScan(event, result) {

    if (event !== BLE.Scanner.SCAN_RESULT) {
        return;
    }

    // Les Tile d'abord, et exactement comme avant.
    if (result.service_data !== undefined &&
        result.service_data !== null) {

        let tileData = result.service_data["feed"];

        if (tileData !== undefined) {
            publishTile(result, tileData);
            return;
        }
    }

    publishOther(result);
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

print("BLE scanner started");
```

Vous saurez que le bon script tourne à la première ligne `GATEWAY:` de la
console : **une seule ligne**, contenant l'adresse IP, `freemem`, `rssi` et
`tempc`. La passerelle apparaît dans Jeedom dans les deux minutes.

## Ce que ce script fait, et ce qu'il ne fait pas

**Il relaie toutes les balises qu'il entend.** Les traceurs Tile sont publiés
comme avant, reconnus comme tels. Les autres partent telles quelles, comme le
ferait une passerelle OpenMQTTGateway : données constructeur, données de
service, signal et type d'adresse. Le script ne décode rien.

**Le plugin lit lui-même les capteurs BTHome v2**, le format ouvert de toute la
gamme Shelly BLU (H&T, Door/Window, Motion…) : température, humidité, pile,
luminosité, ouverture, mouvement. Ajouter un tel capteur ne demande aucune
modification du script : il devient un équipement dès qu'une passerelle
l'entend. Deux limites : un capteur dont le chiffrement est activé dans
l'application Shelly reste illisible, faute de clé, et les appuis sur bouton ne
remontent pas.

Les autres capteurs (Xiaomi, Govee…) arrivent en balises brutes : leurs mesures
ne viennent que d'une vraie passerelle OpenMQTTGateway, qui embarque Theengs et
en reconnaît des centaines. **Ce montage complète une passerelle
OpenMQTTGateway, il ne la remplace pas.**

**Une même balise est relayée au plus toutes les dix secondes**
(`RELAY_INTERVAL_MS`) : le scan voit chaque appareil toutes les trois secondes,
et le plugin n'a besoin que d'un signal frais à la minute. Une nouvelle mesure
BTHome part tout de suite. Sur l'installation d'essai, cela fait entre soixante
et cent messages par minute et par Shelly. Pour réduire encore,
`RELAY_ALL = false` ne relaie que les capteurs BTHome, et `RELAY_MIN_RSSI`
écarte les balises trop lointaines.

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
