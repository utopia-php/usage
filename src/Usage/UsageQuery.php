<?php

namespace Utopia\Usage;

use Utopia\Query\Query;

/**
 * Usage Query
 *
 * Extends the base Query class with usage-specific query types.
 * Currently adds support for `groupByInterval` which enables time-bucketed
 * aggregated queries in the ClickHouse adapter.
 *
 * Example usage:
 * ```php
 * $queries = [
 *     UsageQuery::groupByInterval('time', '1h'),
 *     Query::equal('metric', ['bandwidth']),
 *     Query::greaterThanEqual('time', '2026-03-01'),
 *     Query::lessThanEqual('time', '2026-04-01'),
 * ];
 * $results = $usage->find($queries, 'event');
 * ```
 *
 * When `groupByInterval` is present in the queries array, the ClickHouse adapter
 * switches from raw row returns to aggregated results grouped by time bucket:
 * - Events: SUM(value) per bucket
 * - Gauges: argMax(value, time) per bucket
 */
class UsageQuery extends Query
{
    public const TYPE_GROUP_BY_INTERVAL = 'groupByInterval';

    /**
     * Valid interval values and their ClickHouse INTERVAL equivalents.
     */
    public const VALID_INTERVALS = [
        '1m' => 'INTERVAL 1 MINUTE',
        '5m' => 'INTERVAL 5 MINUTE',
        '15m' => 'INTERVAL 15 MINUTE',
        '1h' => 'INTERVAL 1 HOUR',
        '1d' => 'INTERVAL 1 DAY',
        '1w' => 'INTERVAL 1 WEEK',
        '1M' => 'INTERVAL 1 MONTH',
    ];

    /**
     * Override isMethod to accept groupByInterval in addition to all base Query methods.
     */
    public static function isMethod(string $value): bool
    {
        if ($value === self::TYPE_GROUP_BY_INTERVAL) {
            return true;
        }

        return parent::isMethod($value);
    }

    /**
     * Create a groupByInterval query.
     *
     * When passed to `find()`, this switches the adapter to return time-bucketed
     * aggregated results instead of raw rows.
     *
     * @param string $attribute The time attribute to bucket (usually 'time')
     * @param string $interval The bucket size: '1m', '5m', '15m', '1h', '1d', '1w', '1M'
     * @return self
     */
    public static function groupByInterval(string $attribute, string $interval): self
    {
        if (!isset(self::VALID_INTERVALS[$interval])) {
            throw new \InvalidArgumentException(
                "Invalid interval '{$interval}'. Allowed: " . implode(', ', array_keys(self::VALID_INTERVALS))
            );
        }

        return new self(self::TYPE_GROUP_BY_INTERVAL, $attribute, [$interval]);
    }

    /**
     * Check if a query is a groupByInterval query.
     *
     * @param Query $query
     * @return bool
     */
    public static function isGroupByInterval(Query $query): bool
    {
        return $query->getMethod() === self::TYPE_GROUP_BY_INTERVAL;
    }

    /**
     * Extract the groupByInterval query from an array of queries, if present.
     *
     * @param array<Query> $queries
     * @return self|null The groupByInterval query, or null if not present
     */
    public static function extractGroupByInterval(array $queries): ?self
    {
        foreach ($queries as $query) {
            if ($query instanceof self && $query->getMethod() === self::TYPE_GROUP_BY_INTERVAL) {
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
}
