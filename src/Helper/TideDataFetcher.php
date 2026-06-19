<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_ystides
 *
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com/
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Module\Ystides\Site\Helper;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Handles fetching tide data from ERDDAP and caching it into SQLite.
 *
 * @since  1.0.1
 */
class TideDataFetcher
{
    private const BASE_URL = 'https://erddap.marine.ie/erddap/tabledap/IMI-TidePrediction.csv';
    private const HTTP_TIMEOUT_SECONDS = 8;

    /**
     * How long cached data is considered current before a refresh is attempted (12 hours).
     * Tide predictions are stable, so this is deliberately conservative.
     *
     * @since  1.1.0
     */
    private const FRESHNESS_TTL_SECONDS = 43200;

    /**
     * TideDataMeta key prefix for a station's last successful refresh timestamp.
     *
     * @since  1.1.0
     */
    private const META_KEY_LAST_REFRESH_PREFIX = 'LastRefresh:';

    public const STATUS_FRESH = 'FRESH';
    public const STATUS_REFRESHED = 'REFRESHED';
    public const STATUS_SOURCE_UNAVAILABLE = 'SOURCE_UNAVAILABLE';
    public const STATUS_STATION_ERROR = 'STATION_ERROR';

    /**
     * Request-local ERDDAP source failure marker.
     *
     * @var    bool
     * @since  1.1.0
     */
    private bool $sourceUnavailableThisRequest = false;

    /**
     * Ensure tide data is cached for the given station and date range.
     *
     * @param   DatabaseInterface  $db         Database connection.
     * @param   string             $stationId  Station identifier.
     * @param   Date               $startDate  Start date (UTC, inclusive, start of day).
     * @param   Date               $endDate    End date (UTC, inclusive, start of day).
     *
     * @return  string  One of the STATUS_* constants.
     *
     * @since   1.1.0
     */
    public function ensureRange(DatabaseInterface $db, string $stationId, Date $startDate, Date $endDate): string
    {
        // Decision order (see resilience plan §5.2b): freshness first, then the request-local
        // short-circuit, then the network fetch. A fresh station is never collateral damage of
        // a different station's failure earlier in the same request.
        if ($this->isRangeFresh($db, $stationId, $startDate, $endDate)) {
            return self::STATUS_FRESH;
        }

        if ($this->sourceUnavailableThisRequest) {
            return self::STATUS_SOURCE_UNAVAILABLE;
        }

        $rangeStart = $startDate->format('Y-m-d') . 'T00:00:00Z';
        $rangeEnd   = $endDate->format('Y-m-d') . 'T23:59:59Z';

        try {
            $rows = $this->fetchRange($stationId, $startDate, $endDate);
        } catch (Throwable $exception) {
            $status = $this->classifyFetchFailure($exception);

            if ($status === self::STATUS_SOURCE_UNAVAILABLE) {
                $this->sourceUnavailableThisRequest = true;
            }

            Log::add(
                Text::sprintf('MOD_YSTIDES_ERR_FETCH', $exception->getMessage()),
                $status === self::STATUS_STATION_ERROR ? Log::WARNING : Log::ERROR,
                'mod_ystides'
            );

            return $status;
        }

        $rows = $this->assignCategories($rows);
        $rows = $this->filterToRange($rows, $rangeStart, $rangeEnd);

        if (!empty($rows)) {
            $this->storeRows($db, $rows);
            $this->postProcessRanges($db, $stationId);
        }

        // A successful fetch means the source is current, even if it returned no rows.
        $this->setLastRefresh($db, $stationId);

        return self::STATUS_REFRESHED;
    }

    /**
     * Check if range is already cached (start and end day present).
     *
     * @param   DatabaseInterface  $db         Database connection.
     * @param   string             $stationId  Station identifier.
     * @param   Date               $startDate  Start date.
     * @param   Date               $endDate    End date.
     *
     * @return  bool
     *
     * @since   1.0.1
     */
    private function isRangeCached(DatabaseInterface $db, string $stationId, Date $startDate, Date $endDate): bool
    {
        $checkDay = function (Date $day) use ($db, $stationId) {
            $dayLabel = $day->format('Y-m-d');

            $query = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('TideData'))
                ->where($db->quoteName('StationID') . ' = ' . $db->quote($stationId))
                ->where('substr(' . $db->quoteName('TideDT') . ',1,10) = ' . $db->quote($dayLabel))
                ->setLimit(1);

            $db->setQuery($query);

            return (bool) $db->loadResult();
        };

        return $checkDay($startDate) && $checkDay($endDate);
    }

    /**
     * Check if the range is cached and still within the freshness TTL.
     *
     * A range is fresh only when both endpoint days are present AND the station's last
     * successful refresh is within FRESHNESS_TTL_SECONDS. Pre-meta caches (no LastRefresh)
     * and data older than the TTL are treated as stale, prompting a refresh attempt.
     *
     * @param   DatabaseInterface  $db         Database connection.
     * @param   string             $stationId  Station identifier.
     * @param   Date               $startDate  Start date.
     * @param   Date               $endDate    End date.
     *
     * @return  bool
     *
     * @since   1.1.0
     */
    private function isRangeFresh(DatabaseInterface $db, string $stationId, Date $startDate, Date $endDate): bool
    {
        if (!$this->isRangeCached($db, $stationId, $startDate, $endDate)) {
            return false;
        }

        $lastRefresh = $this->getLastRefresh($db, $stationId);

        if ($lastRefresh === null) {
            return false;
        }

        $age = $this->now() - (int) strtotime($lastRefresh);

        return $age >= 0 && $age <= self::FRESHNESS_TTL_SECONDS;
    }

    /**
     * Get the ISO-8601 UTC timestamp of a station's last successful refresh, if known.
     *
     * @param   DatabaseInterface  $db         Database connection.
     * @param   string             $stationId  Station identifier.
     *
     * @return  string|null
     *
     * @since   1.1.0
     */
    public function getLastRefresh(DatabaseInterface $db, string $stationId): ?string
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('Value'))
            ->from($db->quoteName('TideDataMeta'))
            ->where($db->quoteName('Key') . ' = ' . $db->quote(self::META_KEY_LAST_REFRESH_PREFIX . $stationId));

        $db->setQuery($query);

        $value = $db->loadResult();

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Record a station's last successful refresh as the current UTC time.
     *
     * @param   DatabaseInterface  $db         Database connection.
     * @param   string             $stationId  Station identifier.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function setLastRefresh(DatabaseInterface $db, string $stationId): void
    {
        $sql = sprintf(
            'INSERT INTO TideDataMeta (Key, Value) VALUES (%s, %s) ON CONFLICT(Key) DO UPDATE SET Value = excluded.Value',
            $db->quote(self::META_KEY_LAST_REFRESH_PREFIX . $stationId),
            $db->quote(gmdate('Y-m-d\TH:i:s\Z', $this->now()))
        );

        $db->setQuery($sql);
        $db->execute();
    }

    /**
     * Current UTC time as a Unix timestamp. Isolated as a seam for testing the TTL logic.
     *
     * @return  int
     *
     * @since   1.1.0
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * Fetch the range (with padding) from ERDDAP and filter to requested window.
     *
     * @param   string  $stationId  Station identifier.
     * @param   Date    $startDate  Start date (UTC) inclusive.
     * @param   Date    $endDate    End date (UTC) inclusive.
     *
     * @return  array<int,array<string,mixed>>
     *
     * @since   1.0.1
     */
    private function fetchRange(string $stationId, Date $startDate, Date $endDate): array
    {
        $startPad = (clone $startDate)->modify('-1 day');
        $endPad   = (clone $endDate)->modify('+1 day');

        $dayStart = $startPad->format('Y-m-d') . 'T00:00:00Z';
        $dayEnd   = $endPad->format('Y-m-d') . 'T23:59:59Z';

        $query = [
            'time',
            'stationID',
            'longitude',
            'latitude',
            'Water_Level',
            'Water_Level_ODM',
        ];

        $columns      = implode(',', $query);
        $stationParam = 'stationID=' . '"' . $stationId . '"';
        $startParam   = 'time>=' . $dayStart;
        $endParam     = 'time<=' . $dayEnd;
        $orderParam   = 'orderBy("time")';

        $queryString = self::BASE_URL . '?' . rawurlencode($columns . '&' . implode('&', [$stationParam, $startParam, $endParam, $orderParam]));

        $http = HttpFactory::getHttp();

        Log::add(
            Text::sprintf('MOD_YSTIDES_FETCHING', $queryString),
            Log::INFO,
            'mod_ystides'
        );

        $response = $http->get($queryString, ['Accept' => 'text/csv', 'Accept-Encoding' => 'gzip'], self::HTTP_TIMEOUT_SECONDS);

        if ($response->code < 200 || $response->code >= 300) {
            throw new RuntimeException(
                'HTTP ' . $response->code . ' URL: ' . $queryString . ' Response Body: ' . $response->body,
                (int) $response->code
            );
        }

        $rows = $this->parseCsvBody($response->body, $stationId);

        return $rows;
    }

    /**
     * Classify ERDDAP failures for Phase A resilience behaviour.
     *
     * @param   Throwable  $exception  Fetch exception.
     *
     * @return  string  STATUS_SOURCE_UNAVAILABLE or STATUS_STATION_ERROR.
     *
     * @since   1.1.0
     */
    private function classifyFetchFailure(Throwable $exception): string
    {
        $code = (int) $exception->getCode();

        if ($code >= 400 && $code < 500 && $code !== 429) {
            return self::STATUS_STATION_ERROR;
        }

        return self::STATUS_SOURCE_UNAVAILABLE;
    }

    /**
     * Parse CSV response body into rows.
     *
     * @param   string  $body       CSV body.
     * @param   string  $stationId  Station identifier.
     *
     * @return  array<int,array<string,mixed>>
     *
     * @since   1.0.1
     */
    private function parseCsvBody(string $body, string $stationId): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($body));

        if (!$lines || count($lines) < 3) {
            return [];
        }

        // Skip header and units lines.
        $lines = array_slice($lines, 2);

        $rows = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $columns = str_getcsv($line);

            if (count($columns) < 6) {
                continue;
            }

            $rows[] = [
                'StationID'       => $columns[1] ?: $stationId,
                'TideDT'          => $columns[0],
                'TideCategory'    => null,
                'TideCoefficient' => null,
                'WLM'             => is_numeric($columns[4]) ? (float) $columns[4] : null,
                'WLODMM'          => is_numeric($columns[5]) ? (float) $columns[5] : null,
                'TideRange'       => null,
            ];
        }

        return $rows;
    }

    /**
     * Filter rows to the requested date range only.
     *
     * @param   array   $rows        Parsed rows.
     * @param   string  $rangeStart  Range start datetime (Y-m-d H:i:sZ).
     * @param   string  $rangeEnd    Range end datetime.
     *
     * @return  array
     *
     * @since   1.0.1
     */
    private function filterToRange(array $rows, string $rangeStart, string $rangeEnd): array
    {
        return array_values(array_filter($rows, static function ($row) use ($rangeStart, $rangeEnd) {
            $dt = $row['TideDT'] ?? '';

            return $dt !== '' && $dt >= $rangeStart && $dt <= $rangeEnd;
        }));
    }

    /**
     * Assign tide categories in memory before persisting.
     *
     * @param   array<int,array<string,mixed>>  $rows  Rows sorted by TideDT.
     *
     * @return  array<int,array<string,mixed>>
     *
     * @since   1.0.1
     */
    private function assignCategories(array $rows): array
    {
        usort($rows, static function ($a, $b) {
            return strcmp($a['TideDT'] ?? '', $b['TideDT'] ?? '');
        });

        $previousCategory = '';
        $previousValue    = null;

        foreach ($rows as $index => &$row) {
            $value = $row['WLM'];

            if ($index === 0 || $previousValue === null || $value === null) {
                $row['TideCategory'] = $previousCategory === '' ? 'f' : $previousCategory;
            } elseif ($value > $previousValue) {
                $row['TideCategory'] = 'f'; // rising -> flooding
            } elseif ($value < $previousValue) {
                $row['TideCategory'] = 'e'; // falling -> ebbing
            } else {
                $row['TideCategory'] = $previousCategory === '' ? 'f' : $previousCategory;
            }

            $previousCategory = $row['TideCategory'];
            $previousValue    = $value;
        }

        unset($row);

        // Reverse pass to assign highs (h) and lows (l) at trend changes.
        $first_item_idx = count($rows) - 1;
        $last_item_idx  = 1;

        for ($i = $first_item_idx; $i > $last_item_idx; $i--) {
            $currCat = ($rows[$i]['TideCategory'] ?? '') . ($rows[$i - 1]['TideCategory'] ?? '');
            $newCat  = '';

            switch ($currCat) {
                case 'ef':
                    $newCat = 'h';
                    break;
                case 'fe':
                    $newCat = 'l';
                    break;
                default:
                    continue 2;
            }

            if ($newCat !== '') {
                $wlmTarget = $rows[$i - 1]['WLM'];

                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($rows[$j]['WLM'] === $wlmTarget) {
                        $rows[$j]['TideCategory'] = $newCat;
                    } else {
                        break;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Store rows into TideData, ignoring duplicates.
     *
     * @param   DatabaseInterface  $db    Database connection.
     * @param   array              $rows  Parsed rows.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    private function storeRows(DatabaseInterface $db, array $rows): void
    {
        $db->transactionStart();

        try {
            foreach ($rows as $row) {
                $sql = sprintf(
                    'INSERT OR IGNORE INTO TideData (StationID, TideDT, TideCategory, TideCoefficient, WLM, WLODMM, TideRange) VALUES (%s, %s, %s, %s, %s, %s, %s)',
                    $db->quote($row['StationID']),
                    $db->quote($row['TideDT']),
                    $db->quote($row['TideCategory']),
                    $row['TideCoefficient'] === null ? 'NULL' : (int) $row['TideCoefficient'],
                    $row['WLM'] === null ? 'NULL' : $db->quote($row['WLM']),
                    $row['WLODMM'] === null ? 'NULL' : $db->quote($row['WLODMM']),
                    $row['TideRange'] === null ? 'NULL' : $db->quote($row['TideRange'])
                );

                $db->setQuery($sql);
                $db->execute();
            }

            $db->transactionCommit();
        } catch (\Throwable $exception) {
            $db->transactionRollback();

            throw $exception;
        }
    }

    /**
     * Post-process tide ranges using neighbouring extremes once data is stored.
     *
     * @param   DatabaseInterface  $db         Database connection.
     * @param   string             $stationId  Station identifier.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    private function postProcessRanges(DatabaseInterface $db, string $stationId): void
    {
        $quotedStation = $db->quote($stationId);

        $updateHigh = "
UPDATE TideData AS TD
   SET TideRange = round(abs(WLM - (
       SELECT WLM
         FROM TideData
        WHERE StationID = TD.StationID
          AND TideDT > TD.TideDT
          AND TideCategory IN ('l')
          AND WLM <> TD.WLM
        ORDER BY TideDT ASC
        LIMIT 1
   )), 2)
 WHERE TideCategory IN ('h', 'e')
   AND TideRange IS NULL;";

        $updateLow = "
UPDATE TideData AS TD
   SET TideRange = round(abs(WLM - (
       SELECT WLM
         FROM TideData
        WHERE StationID = TD.StationID
          AND TideDT > TD.TideDT
          AND TideCategory IN ('h')
          AND WLM <> TD.WLM
        ORDER BY TideDT ASC
        LIMIT 1
   )), 2)
 WHERE TideCategory IN ('l', 'f')
   AND TideRange IS NULL;";

# 3.5m is the mean spring tidal range at Dublin Port, this is the reference value
# to calculate the tide coefficient for Irish coastal stations.

        $updateDublinPortRefCoeff = "
UPDATE TideData
    SET TideCoefficient =  round((TideRange * 100)/3.5, 0)
WHERE TideCategory in ('h', 'l') AND 
    TideRange IS NOT NULL AND 
    StationID='Dublin_Port';";

        $updateOtherStationsCoeff = "
UPDATE TideData AS TD
  SET TideCoefficient = 
            (SELECT TD1.TideCoefficient 
			FROM TideData TD1
			WHERE TD1.StationID='Dublin_Port' AND 
		          datetime(TD1.TideDT, 'utc') BETWEEN datetime(TD.TideDT, '-1 hours', 'utc') AND datetime(TD.TideDT, '+1 hours', 'utc')  AND 
				  TD1.TideCategory = TD.TideCategory LIMIT 1)
WHERE TD.TideCategory in ('h', 'l') AND 
      TD.StationID <> 'Dublin_Port' AND 
	  TD.TideRange IS NOT NULL AND 
	  TD.TideCoefficient IS NULL;";

        foreach ([$updateHigh, $updateLow, $updateDublinPortRefCoeff, $updateOtherStationsCoeff] as $sql) {
            $db->setQuery($sql);
            $db->execute();
        }
    }
}
