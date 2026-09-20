# Changelog

## 0.7 — 20 September 2026

### Shelly Gen2, Gen3 and Gen4 read again against their documentation

Their discovery was written without a single reachable device: from the
manufacturer's official documentation, and checked against reconstructed
conversations. That method has a known weakness, stated from the start, and it
has just shown itself: **a data set written from a reading of the documentation
confirms the understanding one has of it, including where that understanding is
wrong.** The adapter was therefore read a second time, line by line, against the
official pages — MQTT transport, enumeration methods, components field by field,
notifications. Here is what that reading found, and what the checks could not.

- **The white channel of an RGBW strip was capped at 39% of its power.** It is
  set from 0 to 255 on the device, and was being given the value of a slider
  that runs from 0 to 100. Pushed to the top, it commanded 100 out of 255 — and
  since the state was read back on the same scale, the slider appeared to refuse
  to go any higher. The value is now converted before being published.
- **The colour temperature setting did nothing at all, ever.** It is expressed
  in kelvin, between the bounds the device announces itself — 2700 to 6500 on a
  tunable-white bulb — and it too was given a value between 0 and 100. Every one
  of them was out of range, so every one was refused by the device, silently.
  The slider now takes the device's bounds, and a virtual number takes the ones
  you gave it.
- **An unplugged device was being questioned at every daemon start.** The
  message saying a Shelly is offline is not published by the device: it is its
  will, published by the broker and kept. A device unplugged six months ago
  replays it on every subscription, and the plugin used to ask it three
  questions before drawing any conclusion. It no longer speaks to a closed
  session, and picks the conversation back up when the device returns.
- **A second door existed, and the plugin believed it walled up.** Modern
  Shellys answer two commands published on their own topic — this is "MQTT
  control", active out of the factory: announce yourself, and publish your full
  state. Nothing to change on the device. The plugin now uses it when the usual
  conversation stays unanswered, a case that used to have no way out: a device
  whose RPC calls are disabled is now discovered in full.
- **A device's inventory was never refreshed.** Renaming an output in the Shelly
  app, changing a 2PM's profile, adding a virtual component: the device
  announces it, and nothing was listening. Its Jeedom equipment kept the
  commands from the day it was discovered until someone re-ran discovery by
  hand. Those announcements now restart the questioning of that device, and of
  that device only.
- **An unplugged probe kept its command, and its last value.** When a Shelly
  loses a field it announces it by publishing it empty; that announcement was
  received and immediately forgotten. The matching command now disappears, and
  its neighbours stay.
- **Text containing a quotation mark broke the command sending it.** Writing a
  value with `"` or `\` into a virtual text component produced a message the
  device rejected without a word.
- **A button press could replay itself.** The *Last event* and *Event component*
  commands react to everything that arrives, including the same value twice —
  which is what two quick presses need. But a broker or a badly set-up bridge
  can keep those messages, and the daemon then received them at every start: a
  scenario fired, triggered by a gesture weeks old, with nothing to explain it.
  Those two commands now tell the daemon to ignore what the broker replays.
  State commands still rely on it — for them, the replayed value is the current
  one.

And smaller corrections, all from the same reading: orders are published with
acknowledgement where replaying them is harmless — a lost button press no longer
vanishes; a device that answers without saying to whom is heard; a refusal that
is not "I do not know this method" is no longer treated as one; a device
announcing more components than it delivers is no longer presented as fully
known; a device whose notifications have been switched off in its settings
leaves a warning in the log, instead of creating commands that would stay empty
with nothing to explain it; and a battery sensor's sleep is read from what the
device publishes rather than from a hand-written list of models.

**New commands**, also read from the documentation: muting a smoke detector and
knowing whether it is muted, a roller shutter's state in plain words — the only
information an uncalibrated shutter has, and it had none —, virtual buttons,
`rgbcct` bulbs, the Shelly cloud link, the Wi-Fi state, illumination in words,
and an input's converted measurements: a sensor set up in litres or bars shows
its litres.

### And then the hardware turned up

These corrections were written believing no modern Shelly was reachable. There
were four on the broker: three **Shelly 1 Mini Gen3** on firmware 2.0.0 and one
**Plus Smoke**. Nobody had looked for them there.

**The three Mini Gen3 are discovered on their own, with fifteen commands each**,
without a single setting being touched on them: state, on, off, toggle, internal
temperature, input, signal, Wi-Fi state, cloud link, uptime, free memory,
restart, the two event commands and availability. That is this milestone's
acceptance criterion, and it is met for this model. The smoke detector sleeps: it
gives only its availability, which is exactly what a battery device should do.

Their conversation was captured and kept, anonymised, in the test bench. It
settled three questions the documentation left open:

- **the broadcast announce request works** on a Gen3 — a community thread claimed
  otherwise. Discovery is therefore immediate, without waiting for a device to
  change state;
- **the availability message is indeed kept by the broker**, which is what lets a
  whole estate report in when the daemon starts;
- **a device does not hand over its components in one go**: it announces
  fourteen, delivers eleven, then the last three. A plugin assuming otherwise
  would lose uptime and signal with no message to say so. Ours already asked for
  the rest, and we now know that was not an idle precaution.

Still unproven, for lack of hardware: roller shutters, lights, energy meters and
awake battery sensors. They are written from the documentation, with the same
caveat as before.

## 0.6 — 20 September 2026

### The adoption queue no longer keeps passers-by

A Bluetooth gateway sees a visitor's phone go past, a neighbour's watch, the car
stereo stopped at the lights. Those devices have no business in Jeedom, and the
adoption queue is there so you can set them aside — provided it empties itself.

- **A candidate you no longer see is cleared within hours** instead of a week.
  The delay already existed, but it could not sort anything: the date it
  compared was that of the candidate's last CHANGE, not of its last appearance —
  and a device that is really there does not change. On a real installation the
  queue was full of fifty candidates last seen sixty-six hours earlier, with no
  room left for a device seen today.
- **The daemon now says what it still sees.** Every candidate still within range
  gives a sign of life every quarter of an hour, whether it changed or not. That
  is what finally gives meaning to the last-seen date, and what allows the delay
  to be short. An adopted device has nothing to prove: it has its own device.
- **The queue stays readable after the daemon stops**: what it holds remains
  adoptable without it, and six hours leave time to decide.

## 0.5 — 20 September 2026

### A gateway that never introduces itself is now named

A Bluetooth gateway is recognised by its hardware address, which it publishes on
its identity topic — and nowhere else. Without that message no gateway device
can be created: that is a deliberate choice, a gateway missing from the list
being better than a ghost device that would come back under another name at
every restart.

- **But the plugin now says so.** A gateway that has been publishing Bluetooth
  frames for half an hour without introducing itself leaves a warning in the
  log, with its prefix and the topic expected from it. Without it, you saw its
  beacons coming through, looked for the gateway in the list, did not find it —
  and nothing anywhere said why. The half hour is not idle caution: the interval
  between two announcements is set on the gateway, and a shorter delay accused a
  perfectly live gateway whose announcement simply came later. The warning
  repeats at most once an hour.

This happens with gateways emulated by a script: a device that only publishes
its identity at startup will have tried before being connected to the broker,
and never tries again. An OpenMQTTGateway bridge republishes that message
periodically.

## 0.4 — 20 September 2026

### A device can have two roles

A Shelly running a Bluetooth gateway script is two things at once: a relay, and
a gateway. Both roles publish on different topics, but they carry the same
hardware address — and that is what the plugin uses to recognise a device it
already knows.

- **Both roles now yield two devices.** They used to fight over a single one:
  the log holds ten takeovers, "taken over by adapter omg", then "taken over by
  adapter shelly.gen2" two seconds later. On each swing, whoever won did not
  know the other's commands and switched them off as gone — fourteen out of
  seventeen. An address or a topic no longer makes a device recognisable beyond
  its own family; its identifier still does, and a device that changes path is
  recognised as before.
- **Commands switched off by those swings are switched back on**, when the
  plugin updates. On those devices, "the channel is gone" was false: the channel
  was there, it was the other adapter that did not know it. A command you hid
  yourself is left alone.

## 0.3 — 20 September 2026

This release fixes a flaw that filled Jeedom on its own: on a real installation,
one hundred and ninety devices created in three days, three an hour, day and
night, not one of which ever received a single value.

### Bluetooth beacons that were not beacons

- **An advertising format is no longer mistaken for a device.** An
  OpenMQTTGateway bridge can read standard formats — iBeacon and its like —
  broadcast by phones, watches and car stereos. It names them while stating that
  it does not know which device they belong to: the plugin read the name and
  ignored the admission. The gateway must now have recognised a brand — and a
  measurement does not make up for a generic brand, because decoding an
  advertising format yields make-believe values: 10.9 V of voltage for a beacon.
  Such beacons are offered in the adoption queue rather than created: whoever
  recognises their own adopts it in one click. The day the gateway truly
  recognises the device, the device is created on its own, as before.
- **A rotating Bluetooth address no longer produces one device per rotation.** A
  phone changes address roughly every twenty minutes: that used to be a brand
  new device each time, mute from birth since the address had already changed. A
  random address must now have lasted an hour before it exists for Jeedom. A
  tracker, whose address does not rotate, is therefore created after an hour —
  with nothing for you to do; a phone never reaches that threshold.
- **A button to clean up what was already created**, in the plugin
  configuration. The first click writes nothing: it lists what would go, with the
  number of commands and of recorded readings involved. Left untouched: anything
  that is not a Bluetooth beacon, anything the gateway was able to name, anything
  that received something in the last twenty-four hours, anything you renamed or
  added a command to, and anything used in a scenario, a view or a design.

This flaw threatened more than readability: the ceiling on discovered devices
was about to be reached, and beyond it discovery stops creating anything — a
Shelly included.

## 0.2 — 17 September 2026

This release brings discovery of second-generation Shelly devices and later —
the Plus, Pro and Mini ranges, in Gen2, Gen3 and Gen4.

### Shelly Gen2, Gen3 and Gen4

- **Everything goes through the broker, and nothing else.** A modern Shelly does
  not publish its values on separate topics the way the first generation does:
  it holds a conversation. The plugin asks for its identity, then for the list
  of its components, and it answers — all over MQTT, with no request ever
  reaching the device by any other route.
- **Nothing to configure on the device** beyond enabling MQTT. The settings
  discovery relies on are on out of the box, and the plugin changes none of
  them. Other integrations reach into the device to flip its "status
  notifications" setting; this one refuses to, and manages without.
- **A device is discovered whatever its topic prefix**, including a custom
  multi-level prefix such as `home/living/plug`. The plugin announces itself to
  the whole estate, listens for devices introducing themselves, and also
  recognises those it merely overheard.
- **The name you gave the device is picked up**, along with the name of each
  output when there is more than one — "Desk plug" rather than "Output 1".
  Unlike the first generation, no request is needed to obtain it: the device
  publishes it itself.
- **Battery sensors are never polled.** They sleep; the plugin waits for them to
  push their full state, which they do on every wake-up. They are given no
  "connected" indicator either: their link drops on every sleep, and showing it
  would declare them broken almost permanently.
- **A roller shutter is a roller shutter.** A Shelly 2PM in cover mode produces
  a shutter with its position, its three buttons and its slider, not two
  switches that would command nothing. The slider appears only when the device
  is calibrated and can therefore act on it.
- **Energy readings are converted.** A modern Shelly counts in watt-hours; the
  command declares kWh and divides. Without that, a meter would read a thousand
  times its true value.
- **Older firmware is supported**: devices that cannot enumerate their
  components are queried differently, and discovered just as completely.
- **Components recognised**: relay outputs, covers, lights (white, colour, white
  channel, colour temperature), inputs in their four modes, power meters,
  single- and three-phase energy meters with their energy stores, temperature
  and humidity sensors, illuminance, voltmeters, battery power, smoke and flood
  detectors, presence zones, user-created virtual components, and the state of
  the device itself.
- **A mixed estate produces no duplicates.** A device carries the same identity
  whichever generation discovered it.

### What remains to be done, said plainly

This discovery is written from the manufacturer's official documentation and
checked against reconstructed conversations: no Gen2 device was available at the
time of writing. The checks establish that the plugin does what was intended,
not that a real Shelly answers that way. Validation against hardware remains to
be done.

Two known limits, described in the documentation: values are not refreshed when
Jeedom restarts until the device has something to announce, and button presses
are not split per input.

### Fixes

- **A modern Shelly is no longer mistaken for a first-generation one.** Both
  generations share an announce topic, and the Gen1 adapter accepted what Gen2
  devices published there: it built a device on topics that do not exist, mute
  forever, which also took the place of the real one.
- The capability vocabulary gains a generic binary state, apparent power,
  frequency, a light's white channel, and writing a text value.
- A configuration key declared twice has been cleaned up.

## 0.1 — 17 September 2026

First release. It brings the link to the broker, automatic discovery of
first-generation Shelly devices and of OpenMQTTGateway gateways — along with the
Bluetooth sensors and trackers they hear —, an adoption queue for what discovery
sees without recognising it, and hand-made devices for everything else.

### The link to the broker

- A standalone daemon connects to the MQTT broker, holds the connection and
  reconnects by itself when it drops. It needs nothing installed alongside it:
  no Python, no Node, no download.
- The configuration only asks for the broker address. The port, TLS encryption,
  authentication and ignored topics can all be set, but their defaults are fine.
- A **Test connection** button opens a real connection to the broker and gives
  its verdict using what is on screen, so you need neither save a configuration
  you are still unsure about nor wait for the daemon to start.
- The plugin page shows two distinct states, the daemon's and the broker's,
  updated with no page reload. A running daemon with a disconnected broker points
  at an address, a port or credentials to review, and hovering the badge shows
  the reason for the refusal.
- The client id presented to the broker is generated once at installation and
  then kept: two clients sharing an id would disconnect each other in a loop.
- A health page answers the three questions you ask when nothing comes through:
  is the broker configured, is the daemon running, has it spoken recently.

### Devices and their commands

- A device can be created by hand, with its info and action commands: an info
  command reads a topic, an action command publishes to a topic.
- When a device publishes JSON, a dotted path — `emeter.0.power` — says which
  value to pull out of the message.
- Optional fine settings, folded out of the way, cover the cases that are out of
  the ordinary: mapping a message onto a value, scale, offset, rounding, a
  minimum interval between two recordings, and a repeat of an unchanged value so
  that a stable sensor does not end up looking like a failure.
- Incoming values are batched before being handed to Jeedom: a talkative broker
  produces dozens of messages per second, and handling them one at a time would
  cost one request each.

### Shelly Gen1 discovery

- First-generation Shelly devices present on the broker are discovered and
  created automatically, with their commands, their units and their generic
  types. No topic to type in.
- Recognised: the state of every relay and the three actions that drive it, power
  and energy readings when the device really has a meter, energy-meter readings,
  the inputs, the internal temperature, the external temperature and humidity
  probes, and the online state.
- Discovery questions each device instead of copying a spec sheet: a Shelly 1
  fitted with two probes is discovered with both, while a bare Shelly 1 — which
  reports the same model code — is discovered without them. A model the plugin
  has never heard of is therefore discovered just as well as a known one.
- A device without a real power meter gets no power command, even when it
  publishes a token meter of its own: a command stuck at zero looks like a
  breakage.
- A **Restart discovery** button asks every device to introduce itself again. It
  is essential on a home that is already running: a device connected for weeks no
  longer announces itself and would stay invisible while publishing its values
  all along.
- Re-running discovery never creates a duplicate, and never overwrites your
  edits: a renamed command, a corrected unit, something you hid, all stay put.
  Only the plumbing — the topic listened to and the path to the value — is
  refreshed.
- Automatic creation can be turned off: discovery then recognises devices
  without creating anything and drops them into the adoption queue, giving you
  time to see what it finds.
- Discovered devices get no parent object: filing them into rooms is yours to do,
  and it has to be done for them to show on the Dashboard.

### OpenMQTTGateway gateways and Bluetooth

- OpenMQTTGateway gateways are discovered and created, with their firmware, their
  address, their free memory, their uptime, how many devices they hear, and two
  actions: restart the gateway, switch its Bluetooth radio off.
- Every Bluetooth sensor the gateway decodes becomes a device, carrying the
  readings it actually publishes: temperature, humidity, pressure, battery level.
- A tracker, which measures nothing, gets the signal strength as each gateway
  hears it, the gateway that hears it best — in practice, the room it is in — and
  its presence, which turns to absent once no gateway has heard it for an
  adjustable delay.
- The same device heard by several gateways stays one device: gateways are
  listening posts, not owners.
- None of this depends on third-party software. OpenMQTTGateway publishes on its
  own topics and those are what the plugin reads: a Home Assistant may be running
  next door, or not at all, and it makes no difference.

### The adoption queue

- What discovery sees without recognising is neither created nor thrown away: it
  is held, and the plugin page offers to review it. That is what makes it
  possible to listen to a Bluetooth gateway without a visitor's phone becoming a
  device.
- Every candidate is shown with what it takes to decide: the address type — a
  random address changes every fifteen minutes, a public one never —, how long it
  has been seen, how many gateways hear it, and how many commands creating it
  would produce.
- Ignoring a device is reversible: ignored devices are listed under their name,
  with the date they were refused, and a button puts them back in circulation.
- The queue looks after itself: a candidate that has not shown up for a week
  drops out, and when it overflows the passers-by are the ones to go — a device
  seen for a long time, with a stable address, keeps its place.
- A ceiling on discovered devices keeps a chatty, or malicious, device from
  filling Jeedom: beyond it, devices are offered rather than created.

### Device names and addresses

- A discovered device is no longer named by its technical identifier alone: the
  plugin reads from the device the name its owner gave it and appends it —
  "Shelly 1 55670C boiler". If the device does not answer, asks for
  authentication or has no name, the technical name stands alone.
- A name changed by hand is never overwritten by a later discovery.
- This lookup can be turned off so that Jeedom reaches out to nothing on your
  network.
- Every discovered device shows its address, as a link, on its tile and in its
  panel: it is the shortest way to tell which one you are holding. It is kept up
  to date at every discovery, so that a new DHCP lease leaves no dead link.

### Not there yet

Shelly Gen2, Gen3 and Gen4 — the Plus, Pro and Mini ranges — are not discovered
yet: discovering them relies on a conversation with the device, which needs
powered-on hardware to validate. Tasmota, Zigbee2MQTT and the Home Assistant
discovery protocol come after that. Until then, those devices can be used in
Jeedom by creating them by hand.
