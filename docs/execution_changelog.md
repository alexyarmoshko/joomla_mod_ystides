# YSTides Changelog

This changelog is extracted from the [execution plan](execution_plan.md) and tracks development history by version and date.

## Version History

| Version | Date | Summary |
|---------|------|---------|
| 1.0.0 | 2026-01-16 | Module scaffold: manifest, dispatcher, service provider, helper, template, language strings |
| 1.0.1 | 2026-01-20 | SQLite caching, ERDDAP data fetching, tide categorisation, station catalog, template rendering |
| 1.0.2 | 2026-01-22 | Moon phase integration from USNO API |
| 1.0.3 | 2026-01-25 | Met Eireann weather warnings with RSS/CAP XML parsing |
| 1.0.4 | 2026-01-27 | Bug fixes and refinements |
| 1.0.5 | 2026-01-28 | Build and release tooling (Makefile, update server, distribution ZIPs) |

## Detailed Changelog

### v1.0.0 — Module Scaffold (2026-01-16)

Phase 1 of the execution plan. Created the foundational Joomla 5 module structure.

- Created `mod_ystides.xml` manifest with module metadata, `station_id` list parameter (38 Irish stations), and `days_range` number parameter (1-14, default 7).
- Created `services/provider.php` to register Joomla DI factories for the module dispatcher and helper.
- Created `src/Dispatcher/Dispatcher.php` as the module entry point, extending Joomla's `AbstractModuleDispatcher`.
- Created `src/Helper/YstidesHelper.php` as the main orchestrator helper.
- Created `tmpl/default.php` as the Bootstrap-based layout template.
- Added language files under `language/en-GB/` for UI and system strings.

### v1.0.1 — Core Tide Data (2026-01-17 to 2026-01-20)

Phases 2-5 of the execution plan. Implemented the full data pipeline from API fetch through SQLite caching to rendered output.

**SQLite data layer (Phase 2, 2026-01-17):**
- Created `src/Helper/DatabaseHelper.php` to initialise SQLite at `{JOOMLA_TMP}/ystides/ystides.sqlite`.
- Schema: `TideStations` (station metadata with area codes), `TideData` (tide predictions with category, water level, tidal coefficient, tide range).
- Enabled foreign keys, created indices on `(StationID, TideDT)`.

**Fetch and cache (Phase 3, 2026-01-18):**
- Created `src/Helper/TideDataFetcher.php` to fetch CSV from Marine Institute ERDDAP API.
- Checks whether the requested date range is already cached before fetching.
- Constructs ERDDAP URL selecting `time`, `stationID`, `longitude`, `latitude`, `Water_Level`, `Water_Level_ODM` columns, filtered by station and date range.
- CSV is parsed line-by-line with `str_getcsv()` and inserted into `TideData`.

**Categorisation (Phase 4, 2026-01-19):**
- Two-pass algorithm implemented in `TideDataFetcher::assignCategories()`.
- Forward pass: compares each water level to predecessor. Rising assigns `f` (flooding), falling assigns `e` (ebbing), equal inherits previous category.
- Reverse pass: identifies trend-change boundaries. An `f`-to-`e` transition marks the `f` point as `h` (high water). An `e`-to-`f` transition marks the `e` point as `l` (low water).
- Post-processing calculates tide ranges (distance to next opposite extreme) and tidal coefficients.
- Dublin Port coefficient: `(range * 100) / 3.5`. Other stations use the nearest Dublin Port coefficient within +/-1 hour of the same tide category.
- Created `src/Helper/StationCatalog.php` with a static array of 38 Irish tide stations including coordinates, reference station offsets, and Met Eireann area codes.

**Render output (Phase 5, 2026-01-20):**
- Template displays station name header, date range, and a Bootstrap table.
- Each row shows date, UTC time, water level, and a colour-coded tidal coefficient badge.
- Consecutive tides of the same category and water level are grouped into single display rows.

### v1.0.2 — Moon Phases (2026-01-22)

Phase 6 of the execution plan.

- Created `src/Helper/MoonPhaseHelper.php`.
- Fetches annual moon phase data from the USNO API (`https://aa.usno.navy.mil/api/moon/phases/year`).
- Phase name mapping: New Moon -> `new`, First Quarter -> `1q`, Full Moon -> `full`, Last Quarter -> `2q`.
- Cached by year in `TideMoonPhases` table; a year is considered cached if any records exist for it.
- Template displays inline SVG moon icons on dates matching a phase event.

### v1.0.3 — Weather Warnings (2026-01-25)

Phase 7 of the execution plan.

- Created `src/Helper/WeatherWarningHelper.php`.
- HEAD request to Met Eireann RSS feed checks `Last-Modified` header against cached value in `WeatherWarningsMeta`.
- If feed is unchanged (304-equivalent), cached data is used.
- Otherwise, fetches RSS feed and parses each item's linked CAP XML for structured warning data (event, severity, onset, expires, area codes in FIPS and EMMA_ID formats).
- Warnings filtered to marine-relevant: category is "Marine", event name contains "Small Craft", or area codes match EI8xx patterns.
- Severity icon mapping: Minor/Advisory -> green, Moderate -> yellow, Severe -> orange, Extreme -> red. Small craft warnings get a dedicated icon.
- Station-level warnings appear next to the date; the highest-severity active warning appears in the module header.

### v1.0.4 — Refinements (2026-01-27)

Bug fixes and UI refinements between the weather warning integration and the build tooling release.

### v1.0.5 — Build and Release Tooling (2026-01-28)

Phase 8 (build) of the execution plan. Current release.

- Created `Makefile` with `dist` and `clean` targets.
- `make dist` creates a distribution ZIP at `installation/mod_ystides-v{X}-{Y}-{Z}.zip` and updates the SHA256 hash in `mod_ystides.update.xml`.
- `mod_ystides.update.xml` configured to point to GitHub releases for Joomla's update server.
- Pre-built distribution ZIPs stored in `installation/` directory.

## Decision Log

Decisions made during development, extracted from the execution plan.

**2026-01-16 — SQLite instead of MySQL.**
Use SQLite instead of Joomla's MySQL database for caching. Module data is self-contained, ephemeral, and does not need to interact with Joomla core tables. SQLite avoids schema migration concerns and simplifies installation.

**2026-01-16 — UTC time display.**
Show times in UTC rather than local Irish time. Marine/tidal data is conventionally referenced in UTC. A note in the UI explains IST conversion (+1 hour, last Sunday in March to last Sunday in October).

**2026-01-19 — Tidal coefficients relative to Dublin Port.**
Calculate tidal coefficients relative to Dublin Port (3.5 m mean spring range). Dublin Port is the standard Irish tidal reference. Other stations derive their coefficient from the nearest Dublin Port value within +/-1 hour of the same tide category.

**2026-01-25 — Marine-only warning filter.**
Filter weather warnings to marine-related only (Marine category, "Small Craft" event name, or EI8xx area codes). The module serves coastal/marine users; general weather warnings add noise.

**2026-01-28 — Distribution ZIPs in repository.**
Store pre-built distribution ZIPs in `installation/` directory. Allows Joomla update server to reference GitHub releases directly.

## Pending Work

Phase 8 (admin UX and tests) is not yet started:
- Parameter validation hardening with Joomla filter helpers.
- Structured logging improvements for API failures and data anomalies.
- PHPUnit tests for CSV parsing and the two-pass categorisation algorithm.
