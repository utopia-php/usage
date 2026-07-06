<?php

namespace Utopia\Usage;

use Utopia\Query\Method;
use Utopia\Query\Query;

/**
 * Usage Query
 *
 * Extends the base Query class with usage-specific query factories.
 * `groupByInterval` enables time-bucketed aggregated queries in the
 * ClickHouse adapter (compiled as `Method::GroupByTimeBucket`), and
 * `groupBy` buckets results by a dimension column
 * (compiled as `Method::GroupBy`).
 *
 * Example usage:
 * ```php
 * $queries = [
 *     UsageQuery::groupByInterval('time', '1h'),
 *     Query::equal('metric', ['bandwidth']),
 *     Query::greaterThanEqual('time', '2026-03-01'),
 *     Query::lessThanEqual('time', '2026-04-01'),
 * ];
 * $results = $usage->find($tenant, $queries, 'event');
 * ```
 *
 * When a `groupByInterval` query is present in the queries array, the
 * ClickHouse adapter switches from raw row returns to aggregated results
 * grouped by time bucket:
 * - Events: SUM(value) per bucket
 * - Gauges: argMax(value, time) per bucket
 */
class UsageQuery extends Query
{
    /**
     * Valid interval values and their ClickHouse INTERVAL equivalents.
     */
    public const VALID_INTERVALS = [
        '1m' => 'INTERVAL 1 MINUTE',
        '5m' => 'INTERVAL 5 MINUTE',
        '15m' => 'INTERVAL 15 MINUTE',
        '30m' => 'INTERVAL 30 MINUTE',
        '1h' => 'INTERVAL 1 HOUR',
        '1d' => 'INTERVAL 1 DAY',
        '1w' => 'INTERVAL 1 WEEK',
        '1M' => 'INTERVAL 1 MONTH',
    ];

    /**
     * Create a groupByInterval query.
     *
     * When passed to `find()`, this switches the adapter to return time-bucketed
     * aggregated results instead of raw rows.
     *
     * @param string $attribute The time attribute to bucket (usually 'time')
     * @param string $interval The bucket size: '1m', '5m', '15m', '30m', '1h', '1d', '1w', '1M'
     * @return self
     */
    public static function groupByInterval(string $attribute, string $interval): self
    {
        if (!isset(self::VALID_INTERVALS[$interval])) {
            throw new \InvalidArgumentException(
                "Invalid interval '{$interval}'. Allowed: " . implode(', ', array_keys(self::VALID_INTERVALS))
            );
        }

        return new self(Method::GroupByTimeBucket, $attribute, [$interval]);
    }

    /**
     * Check if a query is a groupByInterval query.
     *
     * @param Query $query
     * @return bool
     */
    public static function isGroupByInterval(Query $query): bool
    {
        return $query->getMethod() === Method::GroupByTimeBucket;
    }

    /**
     * Extract the groupByInterval query from an array of queries, if present.
     *
     * @param array<Query> $queries
     * @return Query|null The groupByInterval query, or null if not present
     */
    public static function extractGroupByInterval(array $queries): ?Query
    {
        foreach ($queries as $query) {
            if (self::isGroupByInterval($query)) {
                return $query;
            }
        }

        return null;
    }

    /**
     * Remove groupByInterval queries from an array of queries.
     *
     * Returns the remaining queries that should be processed normally.
     *
     * @param array<Query> $queries
     * @return array<Query>
     */
    public static function removeGroupByInterval(array $queries): array
    {
        return array_values(array_filter($queries, function (Query $query) {
            return !self::isGroupByInterval($query);
        }));
    }

    /**
     * Create a groupBy query for dimensional aggregation.
     *
     * Buckets results by the given attribute in addition to the time bucket
     * supplied via `groupByInterval`. Multiple `groupBy` queries may be
     * combined to bucket by several dimensions at once (e.g. service x status).
     *
     * @param array<string>|string $attributes The dimension column(s) to bucket on (service, path, status, ...).
     */
    public static function groupBy(array|string $attributes): static
    {
        if (is_string($attributes)) {
            return new static(Method::GroupBy, $attributes, []);
        }

        return parent::groupBy($attributes);
    }

    /**
     * Check if a query is a groupBy query.
     *
     * @param Query $query
     * @return bool
     */
    public static function isGroupBy(Query $query): bool
    {
        return $query->getMethod() === Method::GroupBy;
    }

    /**
     * Extract all groupBy queries from an array of queries.
     *
     * Multiple groupBy queries can coexist (group by service AND status), so
     * this returns every match rather than the single-instance form used by
     * groupByInterval.
     *
     * @param array<Query> $queries
     * @return array<Query>
     */
    public static function extractGroupBy(array $queries): array
    {
        return array_values(array_filter($queries, function (Query $query) {
            return self::isGroupBy($query);
        }));
    }

    /**
     * Remove all groupBy queries from an array of queries.
     *
     * @param array<Query> $queries
     * @return array<Query>
     */
    public static function removeGroupBy(array $queries): array
    {
        return array_values(array_filter($queries, function (Query $query) {
            return !self::isGroupBy($query);
        }));
    }
}
