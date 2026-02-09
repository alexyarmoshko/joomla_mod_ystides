# YSTides Release Notes

## v1.0.6 (2026-02-09) — Current Release

Yak Shaver Tides is a Joomla 5 site module that displays tide predictions, moon phases, and marine weather warnings for 38 Irish coastal stations.

### Highlights

- Tide predictions with high/low water times, water levels, and colour-coded tidal coefficients
- Moon phase icons (new, first quarter, full, last quarter) from the U.S. Naval Observatory
- Marine weather warnings from Met Éireann with severity-coded icons
- Local SQLite caching to minimise API calls
- Configurable station selection and 1-14 day forecast window
- Bootstrap-styled responsive table output
- Joomla update server integration with SHA256 verification

### Requirements

- Joomla 4.4+ or 5.0-5.9
- PHP 8.3+
- PHP SQLite3 extension

### Installation

Download `mod_ystides-v1-0-6.zip` from the [Releases](https://github.com/alexyarmoshko/joomla_mod_ystides/releases) page and install via **System > Install > Extensions** in Joomla Admin.

### Data Sources

- **Tide predictions** — [Marine Institute ERDDAP](https://erddap.marine.ie/erddap/tabledap/IMI-TidePrediction.html)
- **Moon phases** — [U.S. Naval Observatory API](https://aa.usno.navy.mil/data/api)
- **Weather warnings** — [Met Éireann RSS/CAP XML](https://www.met.ie/warnings-today.html)

### Changes Since v1.0.5

- Fixed hardcoded image paths — module now works when Joomla is installed in a subdirectory (uses `Uri::root(true)`)
- Fixed CSS background-image to use relative path instead of absolute
- Added LICENSE file to distribution ZIP packages
- Fixed misleading code comment (3.4m → 3.5m) in tidal coefficient calculation
- Updated copyright in all PHP files to `(C) 2026 Yak Shaver https://www.kayakshaver.com/`
- Corrected "Met Eireann" to "Met Éireann" throughout documentation

### Known Limitations

- Times are displayed in UTC only (no automatic IST conversion)
- Parameter validation and unit tests are not yet implemented (planned for a future release)

---

## v1.0.5 (2026-01-28)

Build and release tooling. Created Makefile with `dist` and `clean` targets. Configured `mod_ystides.update.xml` for Joomla update server with SHA256 verification.

---

## v1.0.4 (2026-01-27)

Bug fixes and UI refinements.

---

## v1.0.3 (2026-01-25)

### Added

- Marine weather warnings from Met Éireann RSS/CAP XML feed
- HTTP 304 cache validation for weather warning data
- Severity-coded warning icons: green (advisory), yellow (moderate), orange (severe), red (extreme)
- Dedicated small craft warning icon
- Station-level warnings displayed next to dates; highest-severity warning in module header
- Warning filtering to marine-relevant alerts only (Marine category, "Small Craft" events, EI8xx area codes)

---

## v1.0.2 (2026-01-22)

### Added

- Moon phase integration from U.S. Naval Observatory API
- Inline SVG icons for new moon, first quarter, full moon, and last quarter
- Annual moon phase data cached in `TideMoonPhases` table

---

## v1.0.1 (2026-01-20)

### Added

- SQLite data layer with automatic database initialisation at `{JOOMLA_TMP}/ystides/ystides.sqlite`
- ERDDAP API client for fetching tide prediction CSV data from the Marine Institute
- Two-pass tide categorisation algorithm (flooding/ebbing forward pass, high/low reverse pass)
- Tidal coefficient calculation relative to Dublin Port (3.5 m mean spring range)
- Cross-station coefficient matching within +/-1 hour temporal window
- Static catalogue of 38 Irish tide stations with coordinates and Met Éireann area codes
- Bootstrap table rendering with date, UTC time, water level, and colour-coded coefficient badges
- Grouping of consecutive same-category tides into single display rows
- Collapsible information panel explaining data sources and coefficient scale

---

## v1.0.0 (2026-01-16)

### Added

- Initial Joomla 5 module scaffold
- Module manifest with station dropdown (38 stations) and days range parameter (1-14, default 7)
- Service provider for Joomla DI container
- Module dispatcher extending `AbstractModuleDispatcher`
- English language files (UI and system strings)
- Default Bootstrap layout template
