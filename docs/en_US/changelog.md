# Changelog

## 0.1 — 2026-09-17

First release, limited to the link with the broker.

**What it does**

- The plugin installs, displays and breaks nothing: that is milestone 0.
- A standalone daemon connects to the MQTT broker, holds the connection and
  reconnects by itself when it drops: that is milestone 1.
- The plugin page shows two distinct states, the daemon's and the broker's,
  updated with no page reload. A running daemon with a disconnected broker
  points at an address, a port or credentials to review, and the reason for the
  refusal is shown.
- The configuration only asks for the broker address. TLS encryption,
  authentication and ignored topics can be set, but their default values suit.
- A **Test connection** button opens a real connection to the broker and gives
  its verdict, without waiting for the daemon to start.
- The client id presented to the broker is generated once at installation, then
  kept: two clients sharing an id would disconnect each other in a loop.

**What it does not do yet**

No discovery, no device, no command. Automatic discovery — Shelly first, then
Tasmota, Zigbee2MQTT and Home Assistant Discovery — comes with the next
milestone.
