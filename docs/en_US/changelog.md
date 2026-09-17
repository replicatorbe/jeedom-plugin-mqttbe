# Changelog

## 0.1 — 17 September 2026

First release. It brings the link to the broker, automatic discovery of
first-generation Shelly devices, and hand-made devices for everything else.

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
- Automatic creation can be turned off: discovery then recognises devices and
  reports them on the plugin page without creating anything, giving you time to
  see what it finds.
- Discovered devices get no parent object: filing them into rooms is yours to do,
  and it has to be done for them to show on the Dashboard.

### Not there yet

Shelly Gen2, Gen3 and Gen4 — the Plus, Pro and Mini ranges — are not discovered
yet: discovering them relies on a conversation with the device, which needs
powered-on hardware to validate. Tasmota, Zigbee2MQTT and the Home Assistant
discovery protocol come after that. Until then, those devices can be used in
Jeedom by creating them by hand.
