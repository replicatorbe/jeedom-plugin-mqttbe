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

**The frames**, on `<prefix>/BTtoMQTT/<beacon address>`, one per beacon heard.
The plugin reads what it finds there; it decodes nothing itself.

```json
{"id":"d3:46:4c:e6:95:4b","rssi":-62,"brand":"Tile",
 "model":"Smart Tracker","model_id":"TILE","type":"TRACK"}
```

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
//  Publishes the Tile beacons seen by the device's BLE radio.
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

print("Tile scanner started");
```

You will know the right script is running from the first `GATEWAY:` line in the
console: **a single line**, carrying the IP address, `freemem`, `rssi` and
`tempc`. The gateway shows up in Jeedom within two minutes.

## What this script does, and what it does not

**It only reports Tile trackers.** That is what `onScan` does: it looks at a
single service data entry and ignores everything else. A real OpenMQTTGateway
bridge embeds Theengs and recognises hundreds of sensors — thermometers,
hygrometers, plant sensors, scales. Reporting others here would mean writing
their decoding yourself, in the script. **This setup complements an
OpenMQTTGateway bridge, it does not replace one.**

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
