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

**OpenMQTTGateway gateways, and what they hear over Bluetooth, are read
directly.** A gateway becomes a device — its firmware, its network, its state,
and a way to restart it — and each Bluetooth sensor it decodes becomes one too:
temperature, humidity, pressure, battery level, and for a tracker the signal
strength as heard by every gateway, along with the one that hears it best. That
is what lets you tell which room an object is in, with nothing else to install.

None of this goes through third-party software. OpenMQTTGateway publishes on its
own topics and the plugin reads them: a Home Assistant may be running next door,
or not at all, and it makes no difference. That is precisely the point of MQTT.

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
recognising devices but creates nothing: everything it sees goes into the
adoption queue, where you decide device by device. Tick the box again once you
are happy with what you see.

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

## Bluetooth gateways

An OpenMQTTGateway gateway listens to everything within range and publishes it on
the broker. The plugin draws two very different things from that.

**The gateway itself** becomes a device: its firmware, its address on the
network, its free memory, its uptime, how many devices it can see, and two
actions — restart it, switch its Bluetooth radio off.

**The sensors it decodes** each become a device, carrying the readings they
actually publish: temperature, humidity, pressure, battery level. Here too
nothing is copied from a catalog — what arrives is what gets created.

The **tracker** deserves a word, being the most useful case and the least
obvious. A tracker measures nothing: it merely exists. What the plugin makes of
it is the signal strength as each gateway hears it, plus two commands that answer
the real questions: **which gateway hears it best** — in practice, which room it
is in — and **whether it is present**, which turns to absent once no gateway has
heard it for a while. That delay is set in the plugin configuration, under
*Discovery*.

Several gateways do not make several devices: the same tracker heard by three
gateways stays one device, with three signal readings.

### What the plugin does not create on its own

A Bluetooth gateway also hears a visitor's phone, the neighbour's watch and the
earbuds in a passing car. Creating a device for each of them would fill Jeedom in
one evening, and most of those devices would fall silent within the quarter hour:
such devices change their address regularly, on purpose, so as not to be tracked.

So the plugin creates what it **recognises** — a sensor publishing a temperature
is a sensor — and **sets the rest aside** rather than guessing. That is what the
adoption queue is for.

## The adoption queue

When discovery sees something without knowing what it is, it neither creates it
nor throws it away: it holds it, and the plugin page shows a banner — *"n
device(s) seen and not created"*. Click it to open the list and decide device by
device.

Every row gives you what you need to decide, rather than a bare identifier:

- **the address type.** *public* never changes: that is the mark of a beacon or a
  sensor, and the device you create will keep working. *random* changes roughly
  every fifteen minutes: that is the mark of a passing phone or watch, and the
  device would fall silent at the next change;
- **how long it has been seen.** Something belonging to the house has been there
  for days, a passer-by for two minutes. It is the most reliable clue;
- **how many gateways hear it.** Heard by three gateways, it is inside your home;
  heard by one, at the edge, it may well be out in the street;
- **how many commands** creating it would produce.

Two buttons per row. **Create** builds the device straight away, without asking
the device anything again: everything needed was set aside already. **Ignore**
drops it, and it will not be offered to you any more.

**Ignoring is not final.** Ignored devices are listed at the bottom of the same
window, under their name, with the date they were refused and a button to put
them back in circulation. They will reappear in the queue at their next
announcement.

The queue looks after itself: a device that has not shown up for a week drops
out, and if it overflows, the passers-by are the ones to go — a device seen for a
long time, with a stable address, keeps its place.

Finally, the queue is not only about Bluetooth. It also receives what discovery
did not create for another reason: automatic creation is unticked, or the device
ceiling has been reached.

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
   `shellies/shelly1pm-A8B0C1000105`. This field does nothing by itself: it only
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

### Your devices' names

A discovered device is first named after its technical identifier — “Shelly 1
55670C” — which is unique, and tells you nothing. So the plugin reads, from the
device itself, the name you gave it in its own app, and appends it:
**“Shelly 1 55670C boiler”**.

If the device does not answer, asks for authentication or has no name, the
technical name stays exactly as before: a failure never degrades what already
works. And once you rename a device yourself, discovery never touches that name
again — it only keeps the plumbing up to date, meaning the topics it listens to.

Reading the name means one request to the device. If you would rather Jeedom did
not reach out on your network, untick “Read the name from the device” in the
plugin configuration.

### Your devices' addresses

Every discovered device shows the device's address, as a link, on its tile and
in its panel. It is the shortest way to tell which one you are holding: open its
page, recognise it, and come back to name it in Jeedom knowing what it is. The
address is refreshed at every discovery — a new DHCP lease does not leave a dead
link behind.

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
