# Changelog

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
