# YSTides — Joomla Tide Predictions Module

A Joomla 5 site module that displays tide predictions, moon phases, and marine weather warnings for 37 Irish coastal stations.

## Features

- **Tide predictions** — High/low tide times and water levels from the Marine Institute ERDDAP API
- **Tidal coefficients** — Colour-coded badges indicating forecast tidal range relative to Dublin Port (3.5 m mean spring range)
- **Moon phases** — Inline icons for new moon, first quarter, full moon, and last quarter from the U.S. Naval Observatory API
- **Marine weather warnings** — Severity-coded icons for active warnings from Met Eireann, filtered to marine and small craft alerts
- **Local caching** — SQLite database minimises API calls; weather warnings use HTTP 304 cache validation
- **Configurable** — Select any of 37 Irish tide stations and a 1–14 day forecast window

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
|------|--------|-----|
| Tide predictions | [Marine Institute](https://www.marine.ie/) | [ERDDAP IMI-TidePrediction](https://erddap.marine.ie/erddap/tabledap/IMI-TidePrediction.html) |
| Moon phases | [U.S. Naval Observatory](https://aa.usno.navy.mil/) | [Moon Phases API](https://aa.usno.navy.mil/data/api) |
| Weather warnings | [Met Eireann](https://www.met.ie/) | [Warnings RSS/CAP XML](https://www.met.ie/warnings-today.html) |

## How It Works

### Tide Data

The module fetches CSV tide prediction data from the Marine Institute ERDDAP service for the selected station and date range. Data is cached in a local SQLite database. A two-pass algorithm categorises each data point:

1. **Forward pass** — Compares each water level to the previous: rising is marked as flooding (`f`), falling as ebbing (`e`)
2. **Reverse pass** — Identifies trend-change points as high water (`h`) or low water (`l`)

### Tidal Coefficients

The tidal coefficient indicates the forecast tidal range compared with the mean equinoctial spring-tide range at Dublin Port (3.5 m). Coefficients range from ~20 (smallest neap tides) to ~120 (extraordinary spring tides).

| Range | Label | Colour |
|-------|-------|--------|
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

```
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
    WeatherWarningHelper.php  # Met Eireann warnings client
    StationCatalog.php   # Static catalogue of 37 tide stations
tmpl/
  default.php            # Bootstrap template
media/
  css/template.css       # Module styles
  images/                # Moon phase SVGs, warning PNGs (1x/2x/3x)
language/en-GB/          # English language strings
Makefile                 # Build: make dist
```

## Building from Source

Requires GNU Make and `zip`.

```bash
make dist    # Creates installation/mod_ystides-v{X}-{Y}-{Z}.zip
             # and updates SHA256 in mod_ystides.update.xml
make clean   # Removes the distribution ZIP
```

The version number is read from `<version>` in `mod_ystides.xml`.

## License

[GNU General Public License v2.0](LICENSE) or later.

