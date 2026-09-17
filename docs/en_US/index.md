# MQTT BE plugin

This plugin connects Jeedom to an MQTT broker and discovers by itself the
devices announcing themselves on it. You describe nothing: no topic to copy, no
device template to write. The plugin listens to what actually flows on the
broker and derives devices and commands from it.

Shelly comes first, Gen1 as well as Gen2 and later. Then come Tasmota,
Zigbee2MQTT gateways, and any hardware speaking the Home Assistant discovery
protocol.

## What you have to configure

**The broker address, and nothing else.** It is the only setting you really
need.

1. Install the plugin, then enable it.
2. Open its configuration (the cog).
3. Enter the address of your MQTT broker — the IP address of the machine
   running Mosquitto, for instance.
4. Click **Test connection**. The plugin connects to the broker and tells you
   what it got.
5. Save.

The port is already filled in with 1883, the customary port of a plain broker.
If your broker asks for a username and a password, fill them in; many home
brokers accept anonymous connections, and there is then nothing to type.

The other settings — TLS encryption, authority certificate, daemon order port,
ignored topics — have values that suit as they are. Touch them only if you know
why.

### A word on encryption

MQTT sends the password in the clear when TLS is off. On a closed home network
that is no drama. On a shared network, turn TLS on — and remember to change the
port, a TLS broker rarely listens on 1883.

The **Do not verify the certificate** option keeps encryption on but stops
checking the broker's identity: any server can then impersonate it. It exists
for the self-signed certificate of a home broker, not to get rid of an error
message you have not read.

## What you see on the plugin page

Two badges, and they do not say the same thing.

**Daemon** tells whether the process talking to the broker is running.
**Broker** tells whether that process is actually connected. A running daemon
with a disconnected broker is the common case of a wrong address, a wrong port
or refused credentials: the reason for the refusal shows when hovering the
badge.

Both badges update by themselves, with no page reload.

## Current state

The plugin is at its first two milestones:

- **Milestone 0** — the plugin installs, displays, and breaks nothing.
- **Milestone 1** — the daemon connects to the broker, holds the connection,
  reconnects when it drops, and the interface shows its state.

**No device is discovered or created yet.** The plugin page therefore stays
empty of devices, and that is expected: automatic discovery comes with the next
milestone. What you can check today is that the link with your broker is
established and that it holds.

## If something goes wrong

The plugin log is named `mqttbe`, the daemon log `mqttbed`. Both are read from
**Analysis → Logs**.

If the broker badge stays red, check in order: the address, the port, then the
credentials. From a console on the Jeedom machine, an MQTT client settles the
question in one command:

```bash
mosquitto_sub -h 192.168.1.10 -p 1883 -t '#' -v
```

If that command receives nothing, the plugin will receive nothing either, and
the problem is not on its side.
