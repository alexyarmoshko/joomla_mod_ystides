# Yak Shaver Tides (YSTides) Joomla 5 Site Module

This Execution Plan is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, and `Outcomes & Retrospective` must be kept up to date as work proceeds. This document must be maintained in accordance with `.agent/PLANS.md`.

## Purpose / Big Picture

The YSTides module gives Joomla site visitors a quick-glance tide forecast for any of 38 Irish coastal stations. After installing the module and selecting a station in the Joomla admin panel, a site visitor sees a compact table showing high/low tide times, water levels, tidal coefficients, moon phase icons, and active marine weather warnings for the configured number of days ahead (1–14, default 7). All data is fetched from public APIs, cached locally in SQLite, and rendered with Bootstrap styling inside the Joomla template.

## Progress

A detailed changelog with version-by-version history is maintained in [docs/execution_changelog.md](execution_changelog.md).

- [x] (2026-01-16) Scaffold module — manifest, dispatcher, service provider, helper, default layout, language strings, params for StationID and DaysRange.
- [x] (2026-01-17) SQLite data layer — DatabaseHelper creates/opens SQLite at `{JOOMLA_TMP}/ystides/ystides.sqlite`, creates `TideStations` and `TideData` tables with keys and indices.
- [x] (2026-01-18) Fetch & cache — TideDataFetcher requests ERDDAP CSV for station/date window, stream-parses rows, inserts missing `TideData` rows and upserts station metadata.
- [x] (2026-01-19) Categorization — forward pass assigns `f`/`e` (rising/falling), reverse pass marks trend-change points as `h`/`l` (high/low). Tide ranges and coefficients calculated relative to Dublin Port.
- [x] (2026-01-20) Render output — default template displays station name header, date range, table rows with time (UTC), water level, tidal coefficient badges, and ▲/▼ markers.
- [x] (2026-01-22) Moon phases — MoonPhaseHelper fetches annual data from USNO API, caches in `TideMoonPhases` table, displays inline SVG icons on matching dates.
- [x] (2026-01-25) Weather warnings — WeatherWarningHelper fetches Met Éireann RSS/CAP XML, caches in `WeatherWarnings`/`WeatherWarningsMeta` tables with HTTP 304 support, displays severity icons filtered to marine warnings matching station area codes.
- [x] (2026-01-28) Build & release tooling — Makefile packages distribution ZIPs, updates SHA256 in `mod_ystides.update.xml`.
- [ ] Admin UX & tests — validate params, add logging/error improvements, unit tests for CSV parsing and categorization.

## Surprises & Discoveries

- Observation: The ERDDAP CSV contains raw water levels at every predicted time step, not pre-labelled high/low markers. A two-pass categorization algorithm was needed.
  Evidence: Forward pass assigns rising (`f`) / falling (`e`); reverse pass identifies trend-change points as `h` / `l`.

- Observation: Tidal coefficients are not available from ERDDAP. They must be derived from tide range relative to Dublin Port's mean spring range (3.5 m). Non-Dublin stations use the nearest Dublin Port coefficient within ±1 hour.
  Evidence: `TideDataFetcher.php` calculates `(range × 100) / 3.5` for Dublin Port and matches temporally for other stations.

- Observation: Met Éireann RSS feed items only contain summaries; full CAP XML must be fetched per-item for structured area codes and severity data.
  Evidence: `WeatherWarningHelper.php` performs a HEAD check, then fetches the RSS feed, then fetches each linked CAP XML individually.

- Observation: Equal successive water-level values during categorization need to inherit the previous category to avoid misclassifying plateaus.
  Evidence: Algorithm rule: if WLM equals previous WLM, keep the same category as the prior point.

## Decision Log

- Decision: Use SQLite instead of Joomla's MySQL database for caching.
  Rationale: Module data is self-contained, ephemeral, and does not need to interact with Joomla core tables. SQLite avoids schema migration concerns and simplifies installation — no database setup required beyond the PHP SQLite extension.
  Date/Author: 2026-01-16 / initial design

- Decision: Show times in UTC rather than local Irish time.
  Rationale: Marine/tidal data is conventionally referenced in UTC. A note in the UI explains IST conversion (+1 hour, last Sunday in March to last Sunday in October).
  Date/Author: 2026-01-16 / initial design

- Decision: Calculate tidal coefficients relative to Dublin Port (3.5 m mean spring range).
  Rationale: Dublin Port is the standard Irish tidal reference. Other stations derive their coefficient from the nearest Dublin Port value within ±1 hour of the same tide category, giving a normalised comparison.
  Date/Author: 2026-01-19 / Phase 4

- Decision: Filter weather warnings to marine-related only (Marine category or "Small Craft" event name or EI8xx area codes).
  Rationale: The module serves coastal/marine users. General weather warnings add noise; marine and small craft warnings are directly relevant to tidal conditions.
  Date/Author: 2026-01-25 / Phase 7

- Decision: Store pre-built distribution ZIPs in `installation/` directory within the repository.
  Rationale: Allows Joomla update server to reference GitHub releases directly. The Makefile automates ZIP creation and SHA256 hash update in `mod_ystides.update.xml`.
  Date/Author: 2026-01-28 / Build tooling

## Outcomes & Retrospective

Phases 1–7 are complete. The module is functional at v1.0.6 and deployed. All three external APIs (ERDDAP, USNO, Met Éireann) are integrated with local SQLite caching. The template renders a responsive Bootstrap table with tide data, moon phases, and weather warnings.

Remaining work: Phase 8 (admin UX & tests) is not yet started. This includes parameter validation hardening, structured logging improvements, and unit tests for CSV parsing and the categorization algorithm.

Lesson learned: A two-pass categorization algorithm was the correct approach for labelling tides from raw water-level data, since the data arrives as continuous measurements rather than pre-identified extremes. Calculating tidal coefficients required a cross-station temporal matching strategy rather than a simple per-station formula.

## Context and Orientation

The module lives at the repository root as a standard Joomla 5 site module. Its PHP namespace is `Joomla\Module\Ystides\Site`. Key paths:

- `mod_ystides.xml` — Joomla manifest declaring module metadata, configuration parameters (station dropdown, days range), file structure, and update server URL.
- `services/provider.php` — Joomla DI service provider registering the module dispatcher and helper factories.
- `src/Dispatcher/Dispatcher.php` — Entry point. Extends `AbstractModuleDispatcher`, calls `YstidesHelper::getLayoutVariables()` to prepare template data.
- `src/Helper/YstidesHelper.php` — Main orchestrator. Initialises the database, triggers data fetching, loads display rows, and merges consecutive same-category tides for compact display.
- `src/Helper/DatabaseHelper.php` — Creates/opens SQLite database at `{JOOMLA_TMP}/ystides/ystides.sqlite`. Manages schema for four tables: `TideStations`, `TideData`, `TideMoonPhases`, `WeatherWarnings`, plus `WeatherWarningsMeta` for HTTP cache headers.
- `src/Helper/TideDataFetcher.php` — Fetches CSV from Marine Institute ERDDAP API, parses rows, runs two-pass categorization (flooding/ebbing then high/low detection), calculates tide ranges and coefficients.
- `src/Helper/MoonPhaseHelper.php` — Fetches moon phase dates from USNO API, caches by year in `TideMoonPhases` table.
- `src/Helper/WeatherWarningHelper.php` — Fetches Met Éireann RSS feed with HTTP 304 cache validation, parses CAP XML per warning item, filters to marine warnings matching station area codes.
- `src/Helper/StationCatalog.php` — Static array of 38 Irish tide stations with IDs, names, coordinates, and Met Éireann area codes. Seeds the `TideStations` table.
- `tmpl/default.php` — Bootstrap-based template rendering the tide table with date/time columns, water level with coefficient badges, moon phase SVGs, and weather warning PNGs. Image paths use `Uri::root(true)` for subdirectory-safe URLs.
- `media/css/template.css` — Styling for coefficient colour-coding (green/yellow/orange/red), info panel, and layout. Uses relative paths for background images.
- `media/images/` — Moon phase SVGs (`moon-{new,1q,full,2q}-details.svg`), weather warning PNGs at 1×/2×/3× densities, and the module logo.
- `language/en-GB/mod_ystides.ini` — 49 language keys for all UI strings.
- `Makefile` — Build tool: `make dist` creates distribution ZIP and updates SHA256 in `mod_ystides.update.xml`.
- `installation/` — Pre-built distribution ZIPs for each release.
- `docs/` — Reference data (`IE_TIDE_STATIONS.json`, `MET_IE_FIPS_EMMA_ID.json`) and sample XML responses from APIs.

The SQLite database stores five tables:

`TideStations` holds station metadata (ID, name, coordinates, area codes). Primary key: `StationID`.

`TideData` holds tide predictions per station per timestamp, with category (`h`/`l`/`f`/`e`), water level, OD Malin level, tide range, and tidal coefficient. Primary key: `(StationID, TideDT)`. Foreign key to `TideStations`.

`TideMoonPhases` holds moon phase events (new, first quarter, full, last quarter) by datetime. Primary key: `PhaseDT`.

`WeatherWarnings` holds parsed CAP warning data: identifier, event name, category, severity, onset/expires datetimes, and area codes. Primary key: `Identifier`.

`WeatherWarningsMeta` stores key-value pairs for HTTP caching (currently the `LastModified` header value). Primary key: `Key`.

## Plan of Work

All implementation phases are described below for reference. Phases 1–7 are complete. Phase 8 remains.

**Phase 1 — Scaffold module.** Created `mod_ystides.xml` manifest with module metadata, `station_id` list parameter (38 Irish stations), and `days_range` number parameter (1–14, default 7). Created `services/provider.php` to register DI factories, `src/Dispatcher/Dispatcher.php` as entry point, `src/Helper/YstidesHelper.php` as main helper, and `tmpl/default.php` as the layout template. Added language files under `language/en-GB/`.

**Phase 2 — SQLite data layer.** Created `src/Helper/DatabaseHelper.php` to initialise SQLite at the Joomla tmp path. The helper creates the directory if missing, opens an SQLite3 connection, enables foreign keys, and creates `TideStations` and `TideData` tables with the schema described above. Added indices on `(StationID, TideDT)`.

**Phase 3 — Fetch & cache.** Created `src/Helper/TideDataFetcher.php`. On each page load, it checks whether the requested date range is already cached. If not, it constructs an ERDDAP CSV URL selecting columns `time`, `stationID`, `longitude`, `latitude`, `Water_Level`, `Water_Level_ODM`, filtered by station and date range, ordered by time ascending. The CSV is streamed, parsed line-by-line, and inserted into `TideData`.

**Phase 4 — Categorization.** After insertion, `TideDataFetcher` runs two passes over the data. The forward pass compares each water level to its predecessor: rising assigns `f` (flooding), falling assigns `e` (ebbing), equal inherits the previous category. The reverse pass identifies trend-change boundaries: an `f→e` transition marks the `f` point as `h` (high water), an `e→f` transition marks the `e` point as `l` (low water). Tide ranges (distance to next opposite extreme) and coefficients are then calculated. Dublin Port uses `(range × 100) / 3.5`. Other stations look up the nearest Dublin Port coefficient within ±1 hour of the same tide category.

**Phase 5 — Render output.** The template (`tmpl/default.php`) receives prepared data from the helper and renders a Bootstrap table. Each row shows the date (with moon phase and warning icons), UTC time, and water level with a colour-coded tidal coefficient badge. Consecutive tides of the same category and water level are grouped into single display rows. A collapsible info panel explains the data sources and coefficient scale.

**Phase 6 — Moon phases.** Created `src/Helper/MoonPhaseHelper.php`. It fetches annual moon phase data from the USNO API (`https://aa.usno.navy.mil/api/moon/phases/year?year=YEAR`). Phase names are mapped to codes: New Moon → `new`, First Quarter → `1q`, Full Moon → `full`, Last Quarter → `2q`. Data is cached by year in `TideMoonPhases`; a year is considered cached if any records exist for it. The template displays inline SVG moon icons on dates matching a phase event.

**Phase 7 — Weather warnings.** Created `src/Helper/WeatherWarningHelper.php`. It first sends a HEAD request to `https://www.met.ie/warningsxml/rss.xml` comparing the `Last-Modified` header against the cached value in `WeatherWarningsMeta`. If the feed is unchanged (304-equivalent), cached data is used. Otherwise, the RSS feed is fetched and each item's linked CAP XML is parsed for structured warning data (event, severity, onset, expires, area codes in FIPS and EMMA_ID formats). Warnings are filtered to marine-relevant ones by checking: category is "Marine", event name contains "Small Craft", or area codes match EI8xx patterns. Severity determines the icon: Minor/Advisory → green, Moderate → yellow, Severe → orange, Extreme → red. Small craft warnings get a dedicated icon. Station-level warnings appear next to the date; nationwide warnings appear in the module header.

**Phase 8 — Admin UX & tests (pending).** Validate module parameters with Joomla filter helpers. Improve structured logging for API failures and data anomalies. Add PHPUnit tests for CSV parsing logic in `TideDataFetcher` and the two-pass categorization algorithm. Confirm CSRF handling (display-only module, no form actions expected).

## Concrete Steps

Phase 8 implementation (pending):

1. In `src/Helper/TideDataFetcher.php`, extract the CSV parsing and categorization logic into testable static methods.
2. Create a `tests/` directory with PHPUnit configuration.
3. Write unit tests covering: CSV row parsing, forward-pass categorization, reverse-pass high/low detection, coefficient calculation for Dublin Port, and coefficient lookup for non-Dublin stations.
4. Add parameter validation in `YstidesHelper::getLayoutVariables()` — verify `station_id` exists in `StationCatalog`, clamp `days_range` to 1–14.
5. Run `php vendor/bin/phpunit` from the Joomla root and confirm all tests pass.

## Validation and Acceptance

The module is validated by installing the distribution ZIP (`installation/mod_ystides-v1-0-5.zip`) in a Joomla 5 site, configuring it with a station (e.g., "Dublin_Port") and days range (e.g., 7), and verifying that the front-end displays:

1. Station name in the header.
2. A table with date, UTC time, water level, and tidal coefficient for each tide event.
3. Moon phase icons on dates with lunar events.
4. Weather warning icons on dates with active marine warnings (if any exist).
5. A collapsible info panel explaining the data.

For Phase 8, acceptance is: PHPUnit tests pass for CSV parsing and categorization; invalid `station_id` or out-of-range `days_range` values are handled gracefully with user-visible warnings.

## Idempotence and Recovery

The module is safe to run repeatedly. SQLite data is upserted — re-fetching the same date range for the same station does not create duplicates. The weather warning cache is validated via HTTP headers before re-fetching. The `make dist` command can be run multiple times; it recreates the ZIP and updates the SHA256 hash each time.

If the SQLite database becomes corrupted, deleting `{JOOMLA_TMP}/ystides/ystides.sqlite` causes the module to recreate it on the next page load. All data is re-fetched from APIs.

## Artifacts and Notes

Distribution packages are stored in `installation/` with naming convention `mod_ystides-v{X}-{Y}-{Z}.zip`.

The update server XML at `mod_ystides.update.xml` points to GitHub releases and includes a SHA256 hash for integrity verification. The Makefile target `make dist` automatically updates this hash.

Sample API responses are stored in `docs/samples/` for reference and offline development.

## Interfaces and Dependencies

**PHP namespace:** `Joomla\Module\Ystides\Site`

**External APIs:**
- Marine Institute ERDDAP: `https://erddap.marine.ie/erddap/tabledap/IMI-TidePrediction.csv` — tide prediction CSV data
- USNO Astronomical Applications: `https://aa.usno.navy.mil/api/moon/phases/year?year={YEAR}` — moon phase JSON data
- Met Éireann: `https://www.met.ie/warningsxml/rss.xml` — weather warnings RSS/CAP XML

**Joomla framework dependencies:**
- `Joomla\CMS\Dispatcher\AbstractModuleDispatcher`
- `Joomla\CMS\Helper\HelperFactory`
- `Joomla\CMS\Http\HttpFactory`
- `Joomla\CMS\Log\Log`
- `Joomla\CMS\Language\Text`
- `Joomla\CMS\Date\Date`
- `Joomla\Registry\Registry`

**PHP extensions required:** SQLite3

**Key class signatures:**

All helpers use Joomla's `DatabaseInterface` (backed by the SQLite driver) rather than raw `\SQLite3`. The database connection is obtained via `DatabaseHelper::prepareDatabase()` and passed to other helpers.

In `src/Helper/DatabaseHelper.php`:

    public function prepareDatabase(Registry $params): array{driver: DatabaseInterface, path: string}

In `src/Helper/TideDataFetcher.php`:

    public function ensureRange(DatabaseInterface $db, string $stationId, Date $startDate, Date $endDate): void

In `src/Helper/MoonPhaseHelper.php`:

    public function ensurePhasesForYears(DatabaseInterface $db, array $years): void
    public function getPhasesForRange(DatabaseInterface $db, string $startDate, string $endDate): array

In `src/Helper/WeatherWarningHelper.php`:

    public function ensureWarningsUpdated(DatabaseInterface $db): void
    public function getWarningsForStation(DatabaseInterface $db, string $stationId, string $startDate, string $endDate): array
    public static function getPrimaryWarningIcon(array $dayWarning): ?string

In `src/Helper/StationCatalog.php`:

    public static function getStations(): array
    public static function findStation(string $stationId): ?array
    public static function getStationLabel(string $stationId): string

In `src/Helper/YstidesHelper.php`:

    public function getLayoutVariables(Registry $params): array

---

## Revision Notes

**2026-02-09 — Documentation accuracy review.** Corrected station count from 37 to 38 throughout the document to match the actual `StationCatalog` and manifest. Fixed the categorisation reverse pass description: an `f→e` transition marks `h` (high water) and an `e→f` transition marks `l` (low water); the previous text had these labels swapped. Updated all method signatures in the Interfaces section to reflect the actual implementation (instance methods using `DatabaseInterface` rather than static methods with `\SQLite3`; correct method names such as `prepareDatabase`, `ensureRange`, `ensurePhasesForYears`, `ensureWarningsUpdated`). Added missing `getStationLabel()` to `StationCatalog` signatures. Corrected `YstidesHelper::getLayoutVariables` signature to `(Registry $params): array` (no `$app` parameter). Fixed language key count from 48 to 49. Extracted detailed changelog into `docs/execution_changelog.md`.

**2026-02-09 — Subdirectory-safe media paths.** Replaced hardcoded absolute image paths (`/media/mod_ystides/images/...`) in `tmpl/default.php` with `Uri::root(true)`-based paths so the module works when Joomla is installed in a subdirectory. Changed `media/css/template.css` background-image URL to a relative path (`../images/...`).
