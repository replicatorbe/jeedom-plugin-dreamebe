# Dreame plugin

This plugin connects Jeedom to recent Dreame robot vacuums through the
**DreameHome** cloud. There is no daemon, no gateway and no dependency to
install: Jeedom talks to Dreame's server, and the server talks to the robot.

Once the account is configured, each robot becomes a Jeedom device with its
state, battery, errors, consumables, cleaning commands and, when a map is
available, one command per room. All of it is usable in scenarios, which is the
only reason to connect a robot to a home automation system in the first place:
so that it leaves when the house empties, and says something when it stops.

## Which robots

The target is the **Dreame L40 Ultra** and its variants, including the AE
variant, on which the protocol was studied. Other recent models in the range
work insofar as they answer the same property dictionary, which the plugin
checks robot by robot rather than assuming (see "What the robot can do").

Two boundaries worth knowing before installing anything:

- The plugin uses the **DreameHome** application account. Older Dreame robots
  paired with **Mi Home** speak Xiaomi's MiIO protocol, which is unrelated: a Mi
  Home account will be rejected at login, and that is not a password problem.
- The sister brands that use the same cloud under another tenant — Mova,
  Trouver — are not supported.

The model name shown by the plugin comes from a lookup table used for display
only: a robot missing from that table appears under its factory reference and
works exactly the same.

## Installation

1. Install the plugin from the Market, then enable it.
2. Open its configuration and fill in the DreameHome account (next section).
3. Click **Test account**, in that same window. The plugin saves the
   configuration, opens a session and lists the robots it found, with their model
   and connection state. Nothing is created at this stage: this is the button you
   press to find out whether the credentials work.
4. Go back to the plugin page and click the **Discover robots** tile. The plugin
   creates one device per robot, reports what it added and what it already knew,
   then reloads the page.
5. Open the robot that has just appeared under **My robots**, give it a parent
   object — without one, the device shows on no dashboard — and **save**.

As long as no robot exists, the plugin page spells out these steps, so you do not
have to come back here to know where to start.

No dependency is installed and no daemon runs: the plugin only works inside
Jeedom's one-minute core cycle.

## The DreameHome account

Three settings, in the **DreameHome account** section:

| Setting | What it expects |
|---|---|
| **Email address** | The DreameHome application account, not a Xiaomi or Mi Home account. |
| **Password** | The password of that same account. |
| **Region** | The one chosen when the account was created: Europe, America, China, Russia, Singapore or Korea. |

The **region** deserves particular attention, because the error it causes does
not say its own name. An account exists in **one** region only: the servers are
independent, and the European one knows nothing of an account created in America.
Get it wrong and the server does not answer "wrong region" but **"credentials
rejected"** — after which one spends a long time suspecting the password. When in
doubt, the region is the country chosen in the application when the account was
created. If the login succeeds and the server reports that the account lives
elsewhere, the plugin follows that indication and corrects the setting by itself.

The password is kept in Jeedom's database. It is only presented at the **first**
connection: the plugin then obtains a session token which it renews without the
password. This is not gratuitous refinement — the number of authentication
attempts is rate-limited on Dreame's side (see "Known limitations").

Changing the address, the password or the region clears the stored session: the
next one will be opened at the following cycle, with the new values.

## Discovering the robots

The **Discover robots** button asks the account for its list of appliances and
creates one device for each. **Several robots on the same account become as many
independent Jeedom devices**, each with its own commands, its own state and its
own map. Nothing in the plugin assumes there is only one.

Robots **shared** with you by another member of the household are included: they
can be controlled like your own.

Two robots of the same model that were never renamed would carry the same factory
name; the plugin then numbers the second one, otherwise Jeedom would refuse to
create it. The device name is yours afterwards: renaming it in Jeedom changes
nothing else.

Running discovery again later is harmless: existing devices are updated, not
recreated.

## What the robot can do

The plugin does not create a fixed set of commands. On first contact with a
robot, it **asks** what the machine can do: it queries every property in the
dictionary, notes the ones the robot answered, and creates only the matching
commands.

There is a precise reason for this detour. Dreame's MIoT protocol **exposes no
per-model property table**: the same dictionary covers the whole range, from the
entry-level robot to the model with a washing base, and it is the robot that
decides at run time — a property it does not know simply answers an error code.
The only honest way to know whether a robot has a washing base, a detergent
module or a humidity setting is to ask it.

In practice, a robot without a washing base gets a **Set water level** command,
while a robot with one gets **Set humidity**, **Set mode** and the mop washing
and drying commands. And no machine ends up with a setting it does not have.

Probing happens once, at the first cycle following the creation of the device,
and the answer is then kept. The **Probe capabilities** button, on the robot's
page, runs it again on demand. Two occasions call for it: a robot that was
switched off or unplugged at discovery time and could therefore answer nothing,
and above all a **firmware update**, which may bring the machine settings it did
not have — without a new probe, the matching commands would never be created.

## A robot's page

The plugin page shows two tiles — **Discover robots** and **Configuration** —
then the **My robots** list. Clicking a robot opens its page, organised in five
tabs.

The **Rooms**, **Map** and **History** tabs only fill in when you open them. This
is a deliberate choice: each of those contents costs one call to the cloud, and
loading them upfront would mean three calls every time a robot's page is opened,
for data you do not always look at.

The buttons on those tabs act on a saved device: on a robot that was never saved,
they say so rather than failing obscurely.

### Equipment tab

On the left, Jeedom's usual settings: name, parent object, categories, enabled
and visible.

On the right, the robot's identity as the cloud gives it — **DreameHome
identifier**, **model** and **firmware**. These three fields are read-only: they
are filled in by discovery, and changing the identifier would sever the link with
the robot.

Below them, a banner summarises the state of the capabilities: the recognised
model, the number of properties the robot answered, and the date of the last
probe. As long as no probe has taken place, it says so — this is the most useful
piece of information on the page, since without a probe the commands remain
incomplete.

Two buttons go with it:

- **Probe capabilities** asks the robot again what it can do and recreates the
  commands accordingly. This is the button to use after a firmware update.
- **Refresh now** forces an immediate state read, without waiting for the
  configured interval. Handy for checking a setting you have just changed in the
  application.

### Rooms tab

The **Reload rooms** button downloads the map again and re-extracts the rooms
from it. On merely opening the tab, the plugin only displays the rooms it already
knows, without asking the cloud for anything.

For each room the table gives:

| Column | Contents |
|---|---|
| **Identifier** | The room number, the one `nettoyer_pieces` accepts and which suffixes the `room::…` command. |
| **Name** | The room name as it appears in the map: the name entered in the application, or failing that its type. |
| **Area** | In square metres, rounded to two decimals. It is computed on the pixels actually occupied, not on the bounding box, which would overestimate any L-shaped room. |
| **Centre (mm)** | A point inside the room, in the map's coordinate system. |
| **Suction** | The power set for that room in the DreameHome application. |
| **Passes** | The number of passes set for that room in the application. |

An empty table means the robot has no saved map, or that map retrieval is
disabled in the plugin configuration.

### Map tab

The map image, as the plugin drew it at the last download. The **Download the map
again** button fetches a new one right away, which is the way to see where the
robot is without waiting for the next cycle. If no map has been retrieved yet,
the tab says so.

### History tab

The last twenty known cleanings: **date**, **duration**, **area** and **end**,
that last column saying whether the cleaning ran to completion or was
interrupted. The **Reload history** button asks the cloud for these events again;
without it, the tab shows what is already kept with the device.

### Commands tab

Jeedom's usual command table: name, icon, type, visibility, logging,
configuration and test. Each command's internal identifier — the one used in the
tables below — appears as a tooltip on its row, which saves looking it up
elsewhere when writing a scenario.

## The commands

The internal identifiers below are the ones to use in scenarios and API calls.
They are frozen: they will not change from one version to the next.

Commands marked "hidden" are created but invisible on the dashboard. They are
kept up to date like the others; one tick box in the Commands tab is enough to
show one. Without that, a single robot would fill a whole screen with tiles.

### Robot state

| Identifier | Name | Type | Note |
|---|---|---|---|
| `etat` | State | info / string | The detailed state: vacuuming, mopping, returning to dock, charging… |
| `code_etat` | State code | info / numeric | The raw value behind `etat`. Hidden. |
| `statut` | Status | info / string | What the robot is doing: cleaning, room cleaning, zone cleaning, idle… |
| `en_activite` | Active | info / binary | 1 while the robot is working. This is the boolean to use as a trigger. |
| `en_ligne` | Online | info / binary | As the cloud sees the robot. |
| `batterie` | Battery | info / numeric (%) | Logged. |
| `en_charge` | Charging | info / binary | |
| `charge` | Charging state | info / string | Charging, on battery, charge complete, returning to dock. Hidden. |
| `erreur` | Error | info / string | The error label, or "No error". |
| `code_erreur` | Error code | info / numeric | Hidden. |
| `en_erreur` | In error | info / binary | 1 on a robot fault. |
| `en_alerte` | Station alert | info / binary | 1 on a station warning: full bin, tank to empty. |

The distinction between `en_erreur` and `en_alerte` is deliberate. A full dust
bin and a jammed wheel arrive through the same channel, but one is solved by
walking past the station and the other requires fetching the robot. Conflating
them means getting an incident notification because a bag needs changing.

### Current cleaning

| Identifier | Name | Type | Note |
|---|---|---|---|
| `duree` | Cleaning time | info / numeric (min) | Logged. |
| `surface` | Cleaned area | info / numeric (m²) | Logged. |
| `tache` | Task | info / string | The nature of the current or interrupted task. |
| `progression` | Progress | info / numeric (%) | Created only if the robot publishes this value. |

### Cleaning settings

| Identifier | Name | Type | Note |
|---|---|---|---|
| `aspiration` | Suction | info / numeric | 0 to 3. Hidden. |
| `aspiration_texte` | Suction (text) | info / string | Silent, Standard, Strong, Turbo. |
| `reservoir` | Tank | info / string | Hidden. |
| `serpillere` | Mop attached | info / binary | |

On a robot **with a washing base**, these are added:

| Identifier | Name | Type | Note |
|---|---|---|---|
| `mode` | Mode | info / numeric | Hidden. |
| `mode_texte` | Mode (text) | info / string | Vacuum only, Mop only, Vacuum and mop, Mop after vacuum. |
| `humidite` | Humidity | info / numeric | Hidden. |
| `humidite_texte` | Humidity (text) | info / string | Slightly damp, Damp, Very damp. |
| `station` | Station | info / string | What the base is doing: washing, drying, refilling… |
| `alerte_eau` | Water warning | info / string | |
| `reservoir_propre` | Clean water tank | info / string | |
| `reservoir_sale` | Dirty water tank | info / string | |
| `sac` | Dust bag | info / string | |

On a robot **without a washing base**, these instead:

| Identifier | Name | Type | Note |
|---|---|---|---|
| `eau` | Water level | info / numeric | Hidden. |
| `eau_texte` | Water level (text) | info / string | Low, Medium, High. |

### Orders

| Identifier | Name | Type |
|---|---|---|
| `demarrer` | Start | action |
| `pause` | Pause | action |
| `reprendre` | Resume | action |
| `arreter` | Stop | action |
| `retour_station` | Return to dock | action |
| `localiser` | Locate | action |
| `acquitter` | Clear the alert | action |
| `regler_aspiration` | Set suction | action / list — Silent, Standard, Strong, Turbo |
| `nettoyer_pieces` | Clean rooms | action / message |
| `nettoyer_zone` | Clean a zone | action / message |

On a robot **with a washing base**:

| Identifier | Name | Type |
|---|---|---|
| `regler_humidite` | Set humidity | action / list — Slightly damp, Damp, Very damp |
| `regler_mode` | Set mode | action / list — Vacuum only, Mop only, Vacuum and mop, Mop after vacuum |
| `laver_serpillere` | Wash the mop | action |
| `secher_serpillere` | Dry the mop | action |
| `arreter_sechage` | Stop drying | action |

On a robot **without a washing base**, instead: `regler_eau`, "Set water level",
as a Low / Medium / High list.

Finally, `vider_bac` ("Empty the bin") is created only if the robot declared a
station with automatic emptying.

`demarrer` and `reprendre` send the same order to the robot: that is how the
protocol works, resuming a paused task with the start command. Both commands
exist because a scenario named "resume" reads better than one that starts what is
already running.

### Maintenance

For every consumable the robot recognises, three commands are created, where
`<name>` is the consumable identifier:

| Identifier | Name | Type | Note |
|---|---|---|---|
| `<name>_wear` | *Label* remaining | info / numeric (%) | Logged. |
| `<name>_left` | *Label* (time) | info / numeric (h or d) | Hidden. |
| `raz_<name>` | Reset: *label* | action | Hidden. |

The consumables the plugin knows how to name:

| `<name>` | Label |
|---|---|
| `main_brush` | Main brush |
| `side_brush` | Side brush |
| `filter` | Filter |
| `sensor` | Sensors |
| `tank_filter` | Tank filter |
| `mop_pad` | Mop pad |
| `silver_ion` | Silver ion module |
| `detergent` | Detergent |
| `squeegee` | Squeegee |
| `deodorizer` | Deodorizer module |
| `wheel` | Wheels |
| `scale_inhib` | Scale inhibitor |

Only those **your** robot actually has produce commands: the squeegee exists only
on roller models, the deodorizer module and the wheels on certain variants.
Probing decides, not this list.

The reset command is created invisible: it matches the gesture one makes in the
application after replacing a part, and it has no business being one click away
on a dashboard. After a reset, the percentages are read again at the next cycle
without waiting for the usual interval.

### Statistics and history

| Identifier | Name | Type | Note |
|---|---|---|---|
| `total_time` | Total time | info / numeric (min) | Hidden. |
| `total_area` | Total area | info / numeric (m²) | Hidden. |
| `total_count` | Cleaning count | info / numeric | Hidden. |
| `dernier_nettoyage` | Last cleaning | info / string | Date and time. |
| `derniere_duree` | Last cleaning time | info / numeric (min) | |
| `derniere_surface` | Last cleaning area | info / numeric (m²) | |

The last three are created only if history is enabled in the plugin
configuration.

### Map and position

Created only if map retrieval is enabled:

| Identifier | Name | Type | Note |
|---|---|---|---|
| `position_x` | Position X | info / numeric (mm) | Hidden. |
| `position_y` | Position Y | info / numeric (mm) | Hidden. |
| `orientation` | Orientation | info / numeric (°) | Hidden. |
| `carte` | Map | info / string | The address of the map image, displayed as an image on the dashboard by the plugin's widget. |

### One command per room

For each room on the map, an action command `room::<identifier>`, named **Clean:
*room name***. They appear as soon as the map has been read once, and disappear
by themselves when a room is merged or deleted in the application: a command that
would fail silently is worth nothing.

## Cleaning one or more rooms

There are two ways to do this, and they coexist on purpose.

**One command per room.** `room::3`, displayed as "Clean: Kitchen", cleans that
one room. This is what you want on a dashboard and in a simple scenario: the name
is readable and there is nothing to remember.

**The generic `nettoyer_pieces` command.** It expects a comma-separated list, and
accepts either room identifiers or room names:

```
3,5
Kitchen, Living room
```

Name matching ignores case. An unknown name makes the command fail, with that
name in the message: an explicit error beats a robot cleaning something else.
This is the command to use when the list of rooms depends on the scenario rather
than being written in advance.

In both cases, the suction power and water level applied to each room are the
ones **set for that room in the DreameHome application**, read from the map. The
plugin does not impose them: the application remains the place where you decide
that the kitchen gets mopped and the bedroom vacuumed. The order in which rooms
are visited is also set in the application.

## Cleaning a zone

`nettoyer_zone` expects a rectangle, in **millimetres in the map's coordinate
system**:

```
x1,y1,x2,y2
```

An optional fifth number gives the number of passes:

```
-1500,200,-300,1400,2
```

The coordinates belong to the robot's own map frame, whose origin is where
mapping started rather than a corner of the home: negative values are normal. The
order of the points does not matter, the plugin puts the rectangle back the right
way round. The `position_x` and `position_y` commands give the robot's current
position in that same frame: walking the robot around and reading its position
remains the simplest way to delimit a zone.

**Each side must exceed 100 mm.** A smaller rectangle is refused by the robot
without the slightest explanation; the plugin therefore refuses it itself, with
one.

## The map

The map serves two purposes, and the first matters most: **it is the only place
where room names exist**. Neither the MIoT protocol nor the cloud exposes a list
of rooms; the identifiers, names and per-room settings live only in the map file
uploaded by the robot. Without it, room cleaning is impossible — which is why
disabling the map in the configuration also disables the `room::…` commands.

The second purpose is the image. The plugin draws a PNG and publishes its address
in the `carte` info command, in the form
`plugins/dreamebe/core/php/map.php?id=<identifier>&t=<timestamp>`. The timestamp
changes with every new map: without it, the browser, always seeing the same
address, would dutifully display the map from an hour ago. It shows:

- the floor, coloured per room, four colours spread across the plan so that two
  adjacent rooms never share one;
- walls and room borders;
- the robot, with a line showing its heading;
- the dock.

It does **not** show furniture, detected obstacles, carpets, no-go zones or the
path travelled. That choice is explained below, under "Technical choices".

Fetching a map takes three requests and a few hundred kilobytes, for information
that does not change from one minute to the next. It is therefore cached on
Jeedom's disk and downloaded again only at the configured interval — a quarter of
an hour by default.

On the dashboard, the `carte` command is not shown as a line of text: the plugin
provides a widget that turns it into an **image**, on desktop as well as on
mobile. A click opens it full size in a new tab. As long as no map has been
retrieved, the tile says so in plain words rather than showing a broken image,
which would be taken for a robot failure.

The image is not served openly: it is written to a folder the web server denies,
and delivered by a pass-through that requires an open Jeedom session — otherwise
the floor plan of a home would be accessible to whoever knows its address. In
return, an external service — a Telegram message, an email — will not be able to
load it on its own.

In a multi-storey home the robot keeps several maps; the plugin only keeps the
selected one, since it is the only one describing what the machine is about to
clean.

## Maintenance

Consumable percentages and cumulative counters are read apart from the main
cycle, every half hour by default. These figures move by one point a week:
asking for them every cycle would produce nothing but traffic.

A reminder scenario takes three lines. Triggered on the **Filter remaining**
command, or simply scheduled once a week:

```
IF #[Home][Vacuum][Filter remaining]# < 10 THEN
    notify(Vacuum filter needs replacing: #[Home][Vacuum][Filter remaining]# %)
END
```

After replacing the part, the `raz_filter` command resets the counter, exactly
like the button in the application. It is hidden by default: show it in the
Commands tab, or call it from a scenario.

## History

Three commands describe the last cleaning: its date, its duration and its area.
They are read again every half hour.

They come from a roundabout path, and that explains what can be expected of them.
**There is no history endpoint** in the Dreame cloud: what the server archives
are the events of the robot's status property, each carrying a snapshot of the
properties at that moment. The plugin reads the last twenty, finds the date, the
duration, the area and whether the cleaning ran to completion, and publishes only
the three values a scenario actually uses. The twenty records themselves are kept
with the device and can be consulted in the **History** tab of its page.

What this history does not contain is the list of rooms cleaned: the archived
event does not carry it, and no request can reconstruct it after the fact.

## A few scenarios

**Leaving when the house empties.** Triggered by your presence sensor or away
mode:

```
IF #[Home][Presence][Anyone]# == 0 THEN
    #[Home][Vacuum][Clean rooms]# (message: Kitchen, Living room, Hallway)
END
```

**Being warned when the robot gets stuck.** Triggered on the `en_erreur` command:

```
IF #[Home][Vacuum][In error]# == 1 THEN
    notify(The vacuum stopped: #[Home][Vacuum][Error]#)
END
```

Using `en_erreur` rather than `erreur` avoids a notification for a full bin,
which is reported through `en_alerte` instead.

**Knowing it is done.** Triggered on the `en_activite` command:

```
IF #[Home][Vacuum][Active]# == 0 THEN
    notify(Cleaning done: #[Home][Vacuum][Last cleaning area]# m² in #[Home][Vacuum][Last cleaning time]# min)
END
```

**Not starting it during a nap.** Action commands run just as well from a
scenario as from a click; it is therefore up to the scenario to set the
condition, for instance by testing a house mode before sending `demarrer`.

## Refresh rate

Jeedom's core calls the plugin every minute; the plugin itself only works if the
configured interval has elapsed.

| What is read | How often |
|---|---|
| State, battery, errors, progress | the configured **interval**, 120 s by default, reduced to 60 s during a cleaning |
| Consumables and cumulative counters | the **maintenance and statistics** interval, 1800 s by default |
| Rooms and map | the **map** interval, 900 s by default |
| Cleaning history | every half hour |

These values are not arbitrary. Every state read is a request to the cloud, which
relays it to the robot and waits for its acknowledgement: it is slow, and it is a
free service that would be rude to hammer. Going below a minute would not help
anyway, since Jeedom's core does not fire more often. Conversely, during a
cleaning you want to watch the progress move: the plugin then tightens its own
rhythm.

One quiet economy is worth mentioning, because it explains why `en_ligne` and
`batterie` are sometimes the only values that move: each cycle starts by asking
the account for its list of robots, which already carries the connection state
and battery level of each of them, for all of them, in a single call. A robot the
cloud reports as disconnected is then not queried — talking to it would only
produce one timeout per cycle.

The plugin's **Health** page shows whether the account is configured, how long the
session remains open, and the date of the last successful read for each robot.

## Known limitations

**There is no estimated remaining time.** That figure exists nowhere in the
protocol: the robot publishes a progress percentage, never a forecast duration.
The application estimates it itself. The plugin prefers to show nothing rather
than an invented number.

**There is no real-time tracking.** The protocol only pushes its state changes
over MQTT, on the cloud's broker; the plugin queries at a regular interval. A
state change may therefore take up to two minutes to appear in Jeedom, one minute
during a cleaning. For the intended uses — starting it, knowing where the
cleaning stands, being warned of an incident — this has no consequence, but it is
worth knowing before writing a scenario that counts seconds.

**Cloud code 80001 does not mean "robot offline".** This is the most expensive
misreading of this API: the server gives up waiting after a few seconds, while
the robot does carry out the order and publishes its state change right
afterwards. The plugin treats this code as "no answer this time" and falls back
on the **image the cloud keeps of the robot**, that is, the last values it
received. A state two minutes old beats a hole in Jeedom's history and flickering
widgets. A missing value never overwrites the previous one.

**The rooms cleaned during a past cleaning cannot be recovered.** The event
archived by the cloud carries the date, the duration, the area and the outcome of
the cleaning, but not the list of rooms. To know which rooms were done, it has to
be noted when the cleaning was started — in a scenario variable, for instance.

**The number of password authentication attempts is rate-limited on Dreame's
side.** That is why the plugin keeps its session token and renews it rather than
logging in again: without that precaution, every polling cycle would open a new
session, and the account would eventually be turned away with nothing explaining
why. It is also why a wrong password stops the cycle instead of making it retry.

**The list of rooms depends on the map.** A robot that has never mapped, or whose
map is disabled in the configuration, will have no `room::…` command and will
refuse `nettoyer_pieces`.

## Technical choices

**Everything is plain PHP, with no daemon and no dependency.** No Python, no
package to install, no process to watch. The protocol is HTTPS and JSON: cURL and
PHP's standard extensions are enough. A plugin without dependencies is a plugin
that survives a Jeedom upgrade, a Python version change and a reinstall. The only
price is the absence of MQTT listening, and therefore of real time — a trade-off
made knowingly, described above.

**The cloud layer knows nothing of Jeedom.** The class that talks to Dreame's
server knows neither `eqLogic`, nor `cmd`, nor `log::add`: it receives
credentials, returns PHP arrays and reports trouble through exceptions. Two
concrete benefits. It can be replayed offline against recorded answers, which
makes it possible to verify the decoding without a real account or internet
access. And the day Dreame changes its API — it will — the repair fits in a
single file that contains nothing but protocol.

**Capability probing, rather than a model whitelist.** Maintaining a table of
"this model has that property" would need redoing with every product release, and
is wrong from the first regional variant onwards. Since the robot can answer, we
ask it. A machine unknown to the plugin therefore works, with exactly the
commands that match it, and a model missing from the name table is not blocked
either: it simply shows up under its factory reference.

**Map rendering is deliberately plain.** The full renderer of the reference
implementation runs to more than four thousand lines and relies on tens of
megabytes of graphical assets and on an imaging library that PHP's GD does not
match. Transposing it would produce a lot of fragile code for an image one looks
at for three seconds on a dashboard. The plugin draws what that image is actually
for — where is the robot, and which room is it doing — and stops there.

## Sources

The protocol was studied from three independent open source implementations,
whose findings agree:

| Project | Licence | What it helped establish |
|---|---|---|
| [Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) (`dev` branch) | MIT | The MIoT table, the enumerations, the map decoding chain |
| [TA2k/ioBroker.dreame](https://github.com/TA2k/ioBroker.dreame) | MIT | The cloud routes and the authentication sequence |
| [sandraschi/dreame-mcp](https://github.com/sandraschi/dreame-mcp) | MIT | Cross-checking of calls and response formats |

**No code was copied from them.** This plugin is a reimplementation in PHP, based
on the protocol documentation those projects effectively constitute. Our thanks
to them: without them, none of this would have been possible.

This plugin is not affiliated with Dreame. It is distributed under the AGPL v3
licence.
