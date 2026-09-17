# MQTT BE plugin

This plugin connects Jeedom to an MQTT broker and discovers the devices that
announce themselves on it. You describe nothing: no topic to copy, no device
template to write. The plugin listens to what actually flows on the broker and
works out the devices, their commands, their units and their generic types — the
ones that let Jeedom know a value is a temperature, a power reading or the state
of a switch.

## What the plugin does today

**First-generation Shelly devices are discovered and created automatically.**
You enter your broker address, you start the daemon, and the Shellys on your
network show up on their own, with all their commands: the state of every relay
and the three actions that drive it (on, off, toggle), power and energy readings
when the device really has a meter, the inputs, the internal temperature, the
external temperature and humidity probes, and the online state. On the network
used to build the plugin, 22 devices produced 198 commands without a single
thing being typed in.

The plugin does not rely on a spec sheet copied into a catalog: it asks each
device to introduce itself, then reads what that device actually publishes. That
is why a Shelly 1 fitted with two temperature probes is discovered with both of
them, while a bare Shelly 1 — which reports exactly the same model code — is
discovered without them.

**Everything else is created by hand**, and the plugin is built for that: any
device that publishes over MQTT can become a full Jeedom device, as long as you
supply the topics yourself. How to do it is described below.

## What is not there yet

Better said up front, so that nobody waits for devices that will not come:

- **Shelly Gen2, Gen3 and Gen4** (the Plus, Pro and Mini ranges) will not be
  discovered. It is planned, and most of the surrounding work is done, but
  discovering those devices means holding a conversation with them, and that
  needs powered-on hardware to validate. Until it has been checked against real
  devices, it is not shipped;
- **Tasmota**, **Zigbee2MQTT** and the **Home Assistant** discovery protocol come
  after that.

In the meantime those devices can still be used in Jeedom by creating them by
hand; they simply will not appear on their own.

## Your first fifteen minutes

1. **Install the plugin** from the Market, then enable it. There is nothing to
   install alongside it: no Python, no Node, no download. The plugin page opens
   right away.
2. **Open its configuration** — the cog button at the top of the plugin page.
3. **Enter the broker address** in the *Address* field: the IP address or name of
   the machine running Mosquitto, `192.168.1.10` for instance. It is the only
   setting you really need. The port is already filled in with 1883, the
   customary port of a plain broker. If your broker asks for a username and a
   password, fill them in; many home brokers accept anonymous connections, and
   there is then nothing to type.
4. **Click "Test connection"** at the bottom of the page. The button opens a real
   connection to the broker using what is currently on screen, so you can test
   before saving. An unreachable address takes some fifteen seconds to fail:
   that is the network timing out, not the plugin hanging.
5. **Save**, then **start the daemon**. The daemon panel sits at the top of that
   same configuration page; if you miss it, the plugin's watchdog starts the
   daemon by itself within the minute.
6. **Go back to the plugin page.** The *Daemon* badge turns to "Running", the
   *Broker* badge to "Connected", and your Gen1 Shellys appear within seconds —
   on startup the plugin asks the whole first generation to introduce itself.
   Devices are created without any need to reload the page, and a green banner
   tells you how many have arrived.

If nothing shows up although your Shellys are working, it is nearly always the
same story: a device that has been connected for weeks stops announcing itself.
The **"Restart discovery"** button in the plugin configuration asks every device
on the network to introduce itself again. That is the thing to do after
installing the plugin on a home that is already up and running.

## Two things to know before starting the daemon

### Discovery creates devices on its own

From the very first start, a recognised device becomes a Jeedom device without
asking. On a large installation, that is a lot of devices at once.

If you would rather look before letting it happen, **untick "Create devices"** in
the configuration, under *Discovery*, before starting the daemon. Discovery keeps
recognising devices but creates nothing: the plugin page then tells you how many
devices were seen and which ones. Tick the box again once you are happy with what
you see.

The neighbouring box, **"Automatic discovery"**, is more drastic: untick it and
nothing is recognised at all — only the commands you typed in yourself are still
listened to.

### Created devices have no parent object

**This is what catches people out.** The plugin never files a device into a room:
how your home is divided up is yours to decide, and it would be presumptuous to
assume that a Shelly named "Living room" belongs to the object called "Living
room".

The consequence: **a freshly discovered device does not show on the Dashboard.**
It exists, it receives its values, its commands work — but Jeedom only shows on
the Dashboard what is attached to an object. Until you have filed it away, you
will find it on the plugin page and nowhere else.

To make it appear: open the device, pick a **parent object** from the list, and
save. Once per device; discovery will never touch that choice again.

## The plugin page

At the top, four tiles: add a device, the daemon state, the broker state, and the
way into the configuration.

The two state badges do not say the same thing. **Daemon** tells you whether the
process that talks to the broker is running. **Broker** tells you whether that
process is actually connected. A running daemon with a disconnected broker is the
usual sign of a wrong address, a wrong port or refused credentials: hover the
badge and it tells you why it was refused. Both update by themselves, with no
page reload.

Below them, your devices. Every tile carries its origin: **"Discovered"**,
followed by the family of hardware it belongs to, or **"Created by hand"**. The
distinction matters, because it explains what happens next: a discovered device
will have its plumbing refreshed by every new discovery, a hand-made one is never
touched.

A word on that refresh, since the worry is a fair one: **your edits survive.** A
renamed command, a corrected unit, something you hid, history you turned on —
none of it is overwritten. Discovery only refreshes the plumbing: the topic being
listened to and the path to the value inside the incoming message.

## The other settings

The configuration offers more, but the defaults are fine as they are. Change them
only if you know why.

**Keepalive interval**: how long the broker tolerates silence before it declares
the connection dead. Sixty seconds by default.

**Command port**: the local port through which Jeedom passes instructions to the
daemon. It only listens on the machine itself. Change it only if that port is
already taken.

**Ignored topics**: one pattern per line, with the MQTT wildcards `+` and `#`.
What Jeedom publishes itself is in there by default — taking it back in would
amount to discovering its own devices — along with the broker's internal topics.

### A word on encryption

MQTT sends the password in the clear when TLS is off. On a closed home network
that is no drama. On a shared network, turn TLS on — and remember to change the
port, a TLS broker rarely listens on 1883.

If your broker's certificate is not signed by a public authority, give the path
to the authority certificate in the field provided; left empty, the system's own
trusted authorities are used.

The **Do not verify the certificate** option keeps encryption on but stops
checking the broker's identity: any server can then impersonate it. It exists for
the self-signed certificate of a home broker, not to get rid of an error message
you have not read.

## Creating a device by hand

This is how you bring into Jeedom a device that discovery cannot recognise yet: a
Gen2 Shelly, a Tasmota, a home-made sensor, anything that publishes over MQTT.

1. On the plugin page, click **"Add a device"** and give it a name.
2. Fill in its **base topic**, for example
   `shellies/shelly1pm-D8BFC01A0805`. This field does nothing by itself: it only
   pre-fills the topic of the commands you add next. It is each command's own
   topic, and nothing else, that decides what is listened to or published.
3. Pick a **parent object**, or the device will not reach the Dashboard — the
   same trap as with discovered devices.
4. Move to the **Commands** tab.

An **info** command reads a topic; an **action** command publishes to a topic.

For an info command, give the topic to listen to. If the device publishes JSON —
that is, a message like `{"emeter":[{"power":128.4}]}` rather than a plain number
— the **Path** field says which value to pull out of it. That path is written
with dots: `emeter.0.power` points, in the example above, at the power reading of
the first meter. A segment that is a number means a position in a list, the first
one being 0. Leave the path empty to take the message exactly as it arrives.

Then fill in the **unit** — `W`, `°C`, `%` — which is shown next to the value, and
tick history if you want a graph of it.

For an action command, give the topic to publish to and the **published
message**. Inside that message, `#slider#` is replaced by the slider position,
`#message#` by the text entered and `#color#` by the colour picked: that is how a
slider command sends its value.

The common case — one topic, one value — needs nothing more. The **fine
settings**, folded away behind a button on each row, are there for the cases that
are not:

- **mapping** translates the incoming message, character for character, into the
  value of your choice: it is what turns `on` into 1 and `off` into 0, so that a
  switch shows as being on rather than as the word "on";
- **scale** multiplies the value, **offset** adds a constant to it, and
  **rounding** limits the number of decimals — enough to go from milliwatts to
  watts, or from tenths of a degree to degrees;
- **minimum interval** enforces a delay between two recordings, for a sensor that
  talks too much;
- **repeat** re-emits an unchanged value after a while. Without it, the "last
  communication" date of a perfectly stable sensor ages indefinitely and ends up
  looking like a failure.

A command that carries fine settings says so, so that nobody looks elsewhere for
the explanation of an unexpected value.

## When something goes wrong

**Two logs**, both read from **Analysis → Logs**:

- `mqttbe` — the plugin itself: device creation, routing table updates,
  exchanges with the daemon;
- `mqttbed` — the daemon: broker connection, subscriptions, incoming messages,
  discovery in progress.

When discovery seems to be doing nothing, `mqttbed` is the one to read first: it
records every device it recognises, with its name, its identity and the number of
commands worked out for it.

The **Analysis → Health** page answers, in three lines, the questions you ask
when nothing comes through: is the broker configured, is the daemon running, has
it spoken recently.

**If the broker badge stays red**, check in order the address, the port, then the
credentials; hovering the badge shows the reason for the refusal. From a console
on the Jeedom machine, an MQTT client settles the question in one command:

```bash
mosquitto_sub -h 192.168.1.10 -p 1883 -t '#' -v
```

If that command receives nothing, the plugin will receive nothing either, and the
problem is not on its side. If it does show your Shellys while the plugin stays
empty, that is when the `mqttbed` log earns its keep.

**If a device is missing**, or if a device arrived after the daemon started, use
**"Restart discovery"** in the plugin configuration. The request goes out to
every device, which answers within the second; creating the devices takes a few
seconds more. One caveat: the button makes the daemon work from the **saved**
configuration — if you have just re-enabled discovery, save first.

**If a device does not show on the Dashboard** although it is plainly there on the
plugin page, it is missing its parent object. See above: it is by far the most
common cause.
