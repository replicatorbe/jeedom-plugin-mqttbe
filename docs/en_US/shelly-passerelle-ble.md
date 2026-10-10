# Turning a Shelly into a Bluetooth gateway

A second-generation or newer Shelly carries a Bluetooth radio and can run a
script. It can therefore report the beacons it hears, just as an
OpenMQTTGateway bridge would — without buying more hardware, and taking
advantage of the fact that there is already one in every room.

The plugin makes no difference between the two: it does not know about devices,
it reads topics.

## What the plugin expects

Two topics, under a prefix you choose.

**The identity**, on `<prefix>/SYStoMQTT`. This is the only source of the
hardware address, and the hardware address is what makes the device. Without
that message nothing is created — the plugin would rather have a gateway
missing from the list than a ghost device coming back under another name at
every restart.

```json
{"mac":"D0CF13C4FC68","ip":"192.168.30.185","version":"2.0.0",
 "env":"Shelly","uptime":7355,"freemem":146228,"rssi":-66,"tempc":57.8}
```

Only `mac` is required. Each other field becomes a command: IP address,
version, uptime, free memory, Wi-Fi signal, internal temperature.

**The frames**, on `<prefix>/BTtoMQTT/<beacon address>`, one per beacon heard,
in the OpenMQTTGateway format. A Tile tracker:

```json
{"id":"d3:46:4c:e6:95:4b","rssi":-62,"brand":"Tile",
 "model":"Smart Tracker","model_id":"TILE","type":"TRACK"}
```

Any other beacon, here a Shelly BLU H&T:

```json
{"id":"C0:2C:ED:42:9C:14","mac_type":0,"name":"SBHT-203C",
 "manufacturerdata":"a90b0101000b11000a149c42ed2cc0","rssi":-60,
 "servicedata":"40000a01642e3b45d300","servicedatauuid":"0xfcd2"}
```

`mac_type` is no detail: 0 for a public address, 1 for a random one. Without
it, the plugin takes every address as fixed, and a phone, whose address rotates
every quarter of an hour, would be offered for adoption under a new identity at
each rotation.

## The announcement must be repeated, and that is no detail

**This is the trap of this setup, and an expensive one to diagnose.**

When the device boots, the script runs **before** the MQTT connection is up. A
script that publishes its identity only once, at startup, therefore publishes it
into the void — and never tries again. The Bluetooth scan, on the other hand,
starts right after and finds the connection ready by the time the first beacons
go by.

The result is as misleading as can be: **the beacons come through perfectly,
but the gateway exists nowhere**. You look for a missing device while everything
appears to work.

On three devices carrying the same script, two had been discovered and the third
never — the only difference being the order of startup. The plugin now reports
that case in the log, after half an hour:

> gateway "Mini1G3Vestiaire" is publishing Bluetooth frames but has not
> published its identity on "Mini1G3Vestiaire/SYStoMQTT" since the daemon
> started

An OpenMQTTGateway bridge republishes its identity every two minutes. Do the
same: it is one line, and it makes the gateway discoverable again after any
mishap — broker restarted, retained messages cleared, daemon relaunched.

## The script

One single line changes from one device to the next: `MQTT_BASE`. Paste the
whole thing into the Shelly script editor, **save**, then **stop and start** the
script — saving alone does not reload the running code.

```js
// ============================================================
//  Shelly -> Bluetooth gateway (OpenMQTTGateway emulation)
//  Publishes the Tile beacons seen by the device's BLE radio,
//  and relays every other frame in the OpenMQTTGateway format.
//
//  TO ADAPT ON EACH DEVICE: MQTT_BASE, and nothing else.
// ============================================================

let MQTT_BASE = "ShellySalon";

// Gateway re-announcement. The identity announcement cannot go out just once:
// when the device boots, the script runs BEFORE MQTT is connected, and the
// announcement is lost with nothing to replay it. Two minutes is what a real
// OpenMQTTGateway bridge does.
let GATEWAY_INTERVAL_MS = 120000;

// true to see every frame in the console. Leave it at false in service: the
// scan is continuous and the console becomes unreadable.
let DEBUG = false;

// Beacons other than Tile. true: all of them, like a real OpenMQTTGateway
// bridge. false: BTHome sensors only (Shelly BLU…).
let RELAY_ALL = true;

// A given beacon is relayed once per interval: the scan sees each device every
// three seconds, and the plugin only needs a fresh signal every minute. A NEW
// BTHome reading goes out right away.
let RELAY_INTERVAL_MS = 10000;

// Below this signal, the beacon is not relayed (distant neighbours).
// -100: everything goes through.
let RELAY_MIN_RSSI = -100;

// Number of beacons whose last publication is remembered. Beyond that, the
// oldest is forgotten: it will simply be relayed a little earlier.
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
        // No message, no giving up: the timer will come back in two minutes.
        return;
    }

    let msg = {
        mac: DEVICE.mac,
        ip: "",
        version: DEVICE.ver,
        env: "Shelly",
        uptime: 0
    };

    // Uptime and free memory.
    let sys = Shelly.getComponentStatus("sys");

    if (sys !== undefined && sys !== null) {
        if (sys.uptime !== undefined) {
            msg.uptime = sys.uptime;
        }
        if (sys.ram_free !== undefined) {
            msg.freemem = sys.ram_free;
        }
    }

    // IP address (it provides the "open the interface" link on the Jeedom
    // side) and Wi-Fi signal level.
    let wifi = Shelly.getComponentStatus("wifi");

    if (wifi !== undefined && wifi !== null) {
        if (wifi.sta_ip !== undefined && wifi.sta_ip !== null) {
            msg.ip = wifi.sta_ip;
        }
        if (wifi.rssi !== undefined) {
            msg.rssi = wifi.rssi;
        }
    }

    // Internal temperature, when the device exposes one.
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
//  Every other beacon
// ------------------------------------------------------------

let trackMac = [];
let trackTime = [];
let trackSig = [];
let trackNext = 0;

// Lower-case hexadecimal of part of a binary string: this is how
// OpenMQTTGateway writes manufacturerdata and servicedata.
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

// Reads the structures of an advertisement (length, type, data) and keeps
// what OpenMQTTGateway publishes: manufacturer data and service data.
// Manufacturer data keeps its company identifier first, little-endian
// ("a90b…" for Shelly), exactly like OpenMQTTGateway.
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

        // Service data, 16-bit UUID. BTHome (fcd2) wins over any other:
        // it is the only one the plugin can read.
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

// Remembers a beacon's last publication. Returns true if it must go out
// now.
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

    // A new BTHome reading goes out without waiting; the same reading,
    // repeated by the sensor, waits for the interval like the rest.
    let sig = (bthome === undefined) ? "" : toHex(bthome);

    if (!mustRelay(result.addr, sig, Shelly.getUptimeMs())) {
        return;
    }

    let adv = { mfd: "", svcUuid: "", svc: "" };

    parseAdv(result.advData, adv);
    parseAdv(result.scanRsp, adv);

    // Same field order as an OpenMQTTGateway bridge.
    let msg = { id: result.addr.toUpperCase() };

    // 0 = public address, 1 = random. The plugin relies on it not to create
    // one device per rotating phone address.
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

    // Tile first, and exactly as before.
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

// Gateway discovery: right away, then relentlessly.
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

You will know the right script is running from the first `GATEWAY:` line in the
console: **a single line**, carrying the IP address, `freemem`, `rssi` and
`tempc`. The gateway shows up in Jeedom within two minutes.

## What this script does, and what it does not

**It relays every beacon it hears.** Tile trackers are published as before,
recognised as such. The others go out as they are, as an OpenMQTTGateway bridge
would send them: manufacturer data, service data, signal and address type. The
script decodes nothing.

**The plugin reads BTHome v2 sensors itself**, the open format of the whole
Shelly BLU range (H&T, Door/Window, Motion…): temperature, humidity, battery,
illuminance, opening, motion. Adding such a sensor requires no change to the
script: it becomes a device as soon as a gateway hears it. Two limits: a sensor
with encryption enabled in the Shelly app stays unreadable, for lack of the key,
and button presses are not reported.

Other sensors (Xiaomi, Govee…) arrive as raw beacons: their readings only come
from a real OpenMQTTGateway bridge, which embeds Theengs and recognises hundreds
of them. **This setup complements an OpenMQTTGateway bridge, it does not
replace one.**

**A given beacon is relayed at most every ten seconds** (`RELAY_INTERVAL_MS`):
the scan sees each device every three seconds, and the plugin only needs a
fresh signal every minute. A new BTHome reading goes out right away. On the test
installation, that comes to sixty to a hundred messages per minute per Shelly.
To cut further, `RELAY_ALL = false` relays BTHome sensors only, and
`RELAY_MIN_RSSI` drops beacons that are too far away.

**Three commands will stay empty**, and that is normal:

- *Connected* depends on the MQTT last will, a message the broker publishes on
  your behalf when the device disappears. It is set at connection time, not from
  a script. Publishing "connected" from the script would leave a command stuck
  on that value forever — worse than empty;
- *Latest version available* comes from a topic this script does not publish;
- *Scan interval* and *Scan duration* make no sense with a continuous scan.

Likewise, the **Restart** and **turn off Bluetooth detection** buttons do
nothing: they publish orders on topics the script does not subscribe to.

## Two things to know

**Your Shelly becomes two devices**, and that is intended: the relay on one
side, the gateway on the other. They carry the same hardware address but
different roles and different topics. Do not try to merge them.

**The identity message is published retained** (the trailing `true` in
`MQTT.publish`). That is what makes the gateway robust: the broker replays it on
every subscription. But there is a flip side — if you delete the gateway device
in Jeedom, **it will come back at the next daemon restart**, as long as the
retained message sits on the broker. To decommission a gateway for good, clear
that retained topic as well.
