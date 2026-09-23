# Changelog

## 0.1 — 2026-09-18

First release.

It connects Jeedom to recent Dreame robot vacuums through the DreameHome cloud,
with no daemon, no dependency and no local gateway. The target is the L40 Ultra
and its variants; older models paired with Mi Home use another protocol and are
not supported.

**The account and the robots**

- A single DreameHome account to configure: address, password and region. The
  password is only presented at the first connection, after which the plugin
  renews a session token — the number of authentication attempts is rate-limited
  on Dreame's side.
- The **Test account** button opens a session and lists the robots found without
  creating anything in Jeedom; **Discover robots** creates one device per robot,
  including those shared with you.
- Several robots on one account become as many independent devices.
- If the server reports that the account lives in another region, the plugin
  corrects the setting by itself.
- A rejected account suspends automatic polling for one hour, instead of
  retrying every minute and risking an account lockout. A message reports it,
  the Health page shows when the next attempt is due, and **Test the account**
  bypasses the wait.
- An outage or rate limit on Dreame's side (HTTP 429 or 5xx) is no longer
  mistaken for rejected credentials, and no longer discards the session token.

**What the robot can do**

- On first contact, the plugin queries the robot about every property in the
  dictionary and creates only the commands it answered. There is no per-model
  property table: the same dictionary covers the whole range, and the machine
  decides at run time.
- A robot with a washing base thus gets the humidity setting, the cleaning mode
  and the mop washing and drying orders; a robot without one gets a water level.
  No machine ends up with a setting it does not have.

**Control**

- Start, pause, resume, stop, return to dock, locate, clear an alert, empty the
  bin.
- Set suction, and depending on the machine humidity and mode, or water level.
- Room cleaning, in two ways: a **Clean: *room*** command for each room on the
  map, and a generic command accepting identifiers or names separated by commas.
- "Clean rooms" accepts two parameters after the list, separated by a vertical
  bar since room names already contain commas: `Kitchen, Living room | 2` goes
  over them twice, `Kitchen | 2 | 3` twice on Turbo.
- Zone cleaning, in millimetres in the map's coordinate system, with an optional
  number of passes. A zone that is too small is refused with an explanation,
  where the robot would refuse it without saying anything.
- Six secondary settings when the robot exposes them: announcement volume (0 to
  100), "Do not disturb", water temperature, wash level, carpet handling and
  automatic detergent. Each updates the information facing it without waiting for
  the next slow cycle.

**Monitoring**

- State, status, battery, charging, errors. Station warnings are told apart from
  robot faults: a full bin does not trigger the same command as a jammed wheel.
- Time, area and progress of the current cleaning.
- Wear percentages for the consumables actually present, with a reset command for
  each of them.
- Date, duration and area of the last cleaning, reconstructed from the events the
  cloud archives — there is no history endpoint.
- Fifteen secondary properties when the robot answers them: drying progress, task
  type, localisation, auto-empty availability and state, announcement volume, "Do
  not disturb", water temperature, wash level, drying time, carpet handling,
  automatic detergent, hot water, detergent state and the date of the first
  cleaning.
- A "Rooms" command returning the list of room names separated by commas, so that
  a scenario can enumerate them without hard-coding them.
- Jeedom generic types on the main commands — battery, battery charging, fan
  speed and its state, return to dock and dock state — which widgets, object
  summaries and voice assistants recognise.
- A dashboard that sticks to the essentials: nineteen visible commands on a robot
  with six rooms — state, battery, error, station, map, the five everyday orders,
  the three settings one changes before starting a cleaning, and one command per
  room. The rest is created hidden, with nothing lost: those commands are still
  kept up to date and usable in scenarios. Every tile carries its name, because
  an unlabelled icon does not say that "Stop" interrupts the cleaning where the
  robot stands while "Return to the dock" sends it back to charge.
- A Health page telling whether the account is configured, how long the session
  remains open and when each robot was last read.

**The map**

- Full decoding of the robot's maps: this is the only place where room names
  exist, and therefore the prerequisite for room cleaning.
- A deliberately plain PNG rendering — floor per room, walls, robot and dock —
  cached on disk and served only to authenticated Jeedom users. The maps
  folder is closed with `Require all denied`: the former `Deny from all` rule
  gave way to Jeedom's root `.htaccess`, which allows every PNG, and left floor
  plans readable without a session. A robot's map is deleted with its device.
- Robot position and heading in the map's coordinate system.

**Under the hood**

- Everything is plain PHP: no dependency, no daemon, no process to watch.
- The cloud layer knows nothing of Jeedom and can be replayed offline against
  recorded answers, which makes it possible to verify the decoding without a real
  account.
- A missing acknowledgement from the robot is not treated as a disconnection: the
  plugin falls back on the image the cloud keeps of the machine, and a missing
  value never overwrites the previous one.
- Tokens, passwords and authorisation headers are redacted in the log.
- No command name contains an apostrophe: `cmd::setName()` strips them without
  warning, which displayed "Niveau daspiration". Eleven labels were reworded, and
  the test suite now rejects any name that would contain one. The internal
  identifiers did not change.
- The cycle only asks the cloud for the robot list when one of them is due,
  instead of every minute.
- A poll no longer rebuilds the whole tile: only the values that change are
  pushed to the dashboard.

Protocol studied from Tasshack/dreame-vacuum, TA2k/ioBroker.dreame and
sandraschi/dreame-mcp, all three under the MIT licence. No code was copied from
them.
