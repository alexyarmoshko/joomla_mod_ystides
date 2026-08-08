# Yak Shaver Tides (YSTides) — Joomla Tide Predictions Module

A Joomla 5 site module that displays tide predictions, moon phases, and marine weather warnings for 38 Irish coastal stations.

## Features

- **Tide predictions** — High/low tide times and water levels from the Marine Institute ERDDAP API
- **Tidal coefficients** — Colour-coded badges indicating forecast tidal range relative to Dublin Port (3.5 m mean spring range)
- **Moon phases** — Inline icons for new moon, first quarter, full moon, and last quarter from the U.S. Naval Observatory API
- **Marine weather warnings** — Severity-coded icons for active warnings from Met Éireann, filtered to marine and small craft alerts
- **Local caching** — SQLite database minimises API calls; weather warnings use HTTP 304 cache validation
- **Graceful source outages** — Cached tide rows still render when ERDDAP is unavailable; cold caches show a service-unavailable empty state
- **Configurable** — Select any of 38 Irish tide stations and a 1–14 day forecast window

## Requirements

- Joomla 4.4+ or 5.0–5.9
- PHP 8.3+
- PHP SQLite3 extension

## Installation

1. Download the latest release ZIP from the [Releases](https://github.com/alexyarmoshko/joomla_mod_ystides/releases) page.
2. In Joomla Admin, go to **System > Install > Extensions** and upload the ZIP.
3. Go to **Content > Site Modules**, find "Yak Shaver Tides", and configure:
   - **Station** — Select an Irish coastal tide station
   - **Days Range** — Number of days to display (1–14, default 7)
4. Assign the module to a position and publish it.

## Configuration

| Parameter           | Description                                  | Default                |
| ------------------- | -------------------------------------------- | ---------------------- |
| Station             | Tide station to display                      | _(none - must select)_ |
| Days Range          | Number of forecast days (inclusive of today)  | 7                      |
| Layout              | Module layout override                       | Default                |
| Module Class Suffix | Additional CSS class                         | _(empty)_              |
| Caching             | Enable Joomla page caching                   | No caching             |

## Data Sources

| Data | Source | API |
| ---- | ------ | --- |
| Tide predictions | [Marine Institute](https://www.marine.ie/) | [ERDDAP IMI-TidePrediction](https://erddap.marine.ie/erddap/tabledap/IMI-TidePrediction.html) |
| Moon phases | [U.S. Naval Observatory](https://aa.usno.navy.mil/) | [Moon Phases API](https://aa.usno.navy.mil/data/api) |
| Weather warnings | [Met Éireann](https://www.met.ie/) | [Warnings RSS/CAP XML](https://www.met.ie/warnings-today.html) |

## How It Works

### Tide Data

The module fetches CSV tide prediction data from the Marine Institute ERDDAP service for the selected station and date range. Data is cached in a local SQLite database. A two-pass algorithm categorises each data point:

1. **Forward pass** — Compares each water level to the previous: rising is marked as flooding (`f`), falling as ebbing (`e`)
2. **Reverse pass** — Identifies trend-change points as high water (`h`) or low water (`l`)

ERDDAP refreshes are time-boxed and isolated from rendering. If the upstream source is unavailable, the module still renders cached rows where possible; with no cached rows it shows a tide-source unavailable message instead of a blocking fetch error.

### Tidal Coefficients

The tidal coefficient indicates the forecast tidal range compared with the mean equinoctial spring-tide range at Dublin Port (3.5 m). Coefficients range from ~20 (smallest neap tides) to ~120 (extraordinary spring tides).

| Range | Label | Colour |
| ----- | ----- | ------ |
| < 50 | Low | Green |
| 50–69 | Average | Yellow |
| 70–89 | High | Orange |
| 90+ | Very High | Red |

For Dublin Port: `coefficient = (tide_range * 100) / 3.5`. Other stations use the nearest Dublin Port coefficient within ±1 hour of the same tide category.

### Time Display

All times are shown in **UTC**. In Ireland, from the last Sunday in March to the last Sunday in October, add one hour to convert to Irish Summer Time (IST).

## Supported Stations

Achill Island, Aranmore, Arklow, Ballycotton, Ballyglass, Bray Harbour, Buncranna, Carrigaholt, Castletownbere, Clare Island, Crosshaven, Dingle, Dublin Port, Dungarvan, Dunmore, Fenit, Galway, Howth, Inishmore, Killary Harbour, Killybegs, Kilrush, Kinsale, Lahinch, Letterfrack, Malin Head, Port Oriel, Ringaskiddy, Roonagh, Rossaveel, Rosslare, Skerries, Sligo, Tom Clarke Bridge, Tory Island, Union Hall, Wexford, Wicklow

## Project Structure

```text
mod_ystides.xml          # Joomla manifest (metadata, params, update server)
services/provider.php    # DI service registration
src/
  Dispatcher/
    Dispatcher.php       # Module entry point
  Helper/
    YstidesHelper.php    # Main orchestrator
    DatabaseHelper.php   # SQLite initialisation and schema
    TideDataFetcher.php  # ERDDAP API client and tide categorisation
    MoonPhaseHelper.php  # USNO moon phase API client
    WeatherWarningHelper.php  # Met Éireann warnings client
    StationCatalog.php   # Static catalogue of 38 tide stations
tmpl/
  default.php            # Bootstrap template
media/
  css/template.css       # Module styles
  images/                # Moon phase SVGs, warning PNGs (1x/2x/3x)
language/en-GB/          # English language strings
Makefile                 # Build: make release, make dist_release, make dist_dev
tools/jzip.php           # Deterministic ZIP writer used by the build
```

## Building

Packages are **reproducible**: the ZIP bytes depend only on the packaged files and one timestamp — nothing about the machine that built it — so a release can be rebuilt from its tag and still hash to the `sha256` the update descriptor claims. Needs `make`, `git` and `php`; no Composer and no `zip` binary, since the packager is the vendored `tools/jzip.php`.

One caveat on that claim: packages are deflated (`ZIP_LEVEL=9`), so the compressed bytes come from zlib. That is stable across zlib *versions*, but not guaranteed across *implementations* — zlib-ng, shipped as the zlib provider by some distributions, deflates differently. Build with `make dist_release ZIP_LEVEL=0` if a third party has to re-derive the checksum with no assumption about the compressor; it stores rather than deflates and is reproducible by construction.

| Target | What it does |
| --- | --- |
| `make info` | Shows the version, the packaged file list, and the output paths. |
| `make test` | No automated tests in this repository; the target says so rather than passing silently. |
| `make lint` | Syntax-checks every shipped PHP file plus the packager, and both XML files. |
| `make release` | Validates (clean tree, unused tag, release notes heading, test, lint) and tags the manifest `<version>`. |
| `make dist_release` | Packages **that tag** into `installation/release/`, and writes the update descriptor beside it. |
| `make dist_dev` | Packages the **working tree** into `installation/dev/` for a test site; strips `<updateservers>` so the test install cannot update over itself. |
| `make clean` | Removes `build/` and both package directories. |

What ships is one explicit list (`PACKAGE_FILES` in the [Makefile](Makefile)) — never a directory, so a stray file cannot be published by accident. The trade is that a new module file is **silently left out** until it is added to that list; the build only fails the other way round, when a listed file is missing. Check `make info` after adding one.

To cut a release: bump `<version>` in `mod_ystides.xml` and in `mod_ystides.update.xml`, add the matching `## <version>` section to [docs/RELEASE.md](docs/RELEASE.md), commit, then `make release && make dist_release`. Upload **that exact ZIP** as the release asset, then publish the generated `installation/release/mod_ystides.update.xml` at the location the manifest's `<updateservers>` entry points to — currently [joomla_update_system](https://github.com/alexyarmoshko/joomla_update_system), as `manifests/mod_ystides.update.xml` — in that order, since a descriptor published before its asset announces a download that 404s.

The tracked `mod_ystides.update.xml` is a **template**: its `<sha256>` is a 64-zero placeholder and the build refuses to run without it. The real checksum only exists once the package is built, so the published descriptor is a build artifact and is never committed here.

## License

[GNU General Public License v2.0](LICENSE) or later.
