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
 *
 * An `aggregate` hint overrides how values are combined. The default `sum`
 * totals them; `max` takes the largest value per bucket, which is how a gauge
 * level series rolls up to a coarser interval — the default `argMax` would
 * return the latest reading rather than the highest.
 */
class UsageQuery extends Query
{
    public const TYPE_GROUP_BY_INTERVAL = 'groupByInterval';
    public const TYPE_GROUP_BY = 'groupBy';
    public const TYPE_AGGREGATE = 'aggregate';

    /**
     * Valid aggregation functions.
     *
     * - `sum` — the default; totals the metric value per bucket (current behaviour).
     * - `max` — the largest value per bucket. Intended for gauges, where the
     *   default `argMax(value, time)` returns the *latest* reading rather than
     *   the highest one. Reading a pre-computed level series (e.g. realtime
     *   concurrency sampled every 5 minutes) at a coarser interval needs the
     *   peak of the samples, not the last one.
     */
    public const VALID_AGGREGATES = ['sum', 'max'];

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
     * Override isMethod to accept groupByInterval and groupBy in addition to all base Query methods.
     */
    public static function isMethod(string $value): bool
    {
        if ($value === self::TYPE_GROUP_BY_INTERVAL || $value === self::TYPE_GROUP_BY || $value === self::TYPE_AGGREGATE) {
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
     * Queries parsed via `Query::parse()` are base `Query` objects rather than
     * `UsageQuery` instances, so we match on the method string alone.
     *
     * @param array<Query> $queries
     * @return Query|null The groupByInterval query, or null if not present
     */
    public static function extractGroupByInterval(array $queries): ?Query
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === self::TYPE_GROUP_BY_INTERVAL) {
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
     * @param string $attribute The dimension column to bucket on (service, path, status, ...).
     * @return self
     */
    public static function groupBy(string $attribute): self
    {
        return new self(self::TYPE_GROUP_BY, $attribute, []);
    }

    /**
     * Check if a query is a groupBy query.
     *
     * @param Query $query
     * @return bool
     */
    public static function isGroupBy(Query $query): bool
    {
        return $query->getMethod() === self::TYPE_GROUP_BY;
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

    /**
     * Create an aggregate query selecting the aggregation function.
     *
     * `sum` (the default when no aggregate query is present) totals the metric
     * value per bucket. `max` takes the largest value in the bucket instead —
     * the meaningful roll-up for a gauge level series, whose default
     * `argMax(value, time)` would return the latest reading.
     * See {@see UsageQuery::VALID_AGGREGATES}.
     *
     * @param string $function One of {@see UsageQuery::VALID_AGGREGATES}.
     * @return self
     */
    public static function aggregate(string $function): self
    {
        if (!in_array($function, self::VALID_AGGREGATES, true)) {
            throw new \InvalidArgumentException(
                "Invalid aggregate '{$function}'. Allowed: " . implode(', ', self::VALID_AGGREGATES)
            );
        }

        return new self(self::TYPE_AGGREGATE, 'value', [$function]);
    }

    /**
     * Check if a query is an aggregate query.
     *
     * @param Query $query
     * @return bool
     */
    public static function isAggregate(Query $query): bool
    {
        return $query->getMethod() === self::TYPE_AGGREGATE;
    }

    /**
     * Extract the aggregation function from an array of queries, if present.
     *
     * Queries parsed via `Query::parse()` are base `Query` objects rather than
     * `UsageQuery` instances, so we match on the method string alone.
     *
     * @param array<Query> $queries
     * @return string|null The aggregation function, or null if not present.
     */
    public static function extractAggregate(array $queries): ?string
    {
        foreach ($queries as $query) {
            if (self::isAggregate($query)) {
                $value = $query->getValues()[0] ?? null;

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * Remove all aggregate queries from an array of queries.
     *
     * @param array<Query> $queries
     * @return array<Query>
     */
    public static function removeAggregate(array $queries): array
    {
        return array_values(array_filter($queries, function (Query $query) {
            return !self::isAggregate($query);
        }));
    }
}
