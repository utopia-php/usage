<?php

namespace Utopia\Usage\Adapter;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query as DatabaseQuery;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;
use Utopia\Query\Query;

class Database extends SQL
{
    protected string $collection;

    private UtopiaDatabase $db;

    public function __construct(UtopiaDatabase $db)
    {
        $this->db = $db;
    }

    public function getName(): string
    {
        return 'Database';
    }

    /**
     * Check database connection health and collection existence.
     *
     * @return array{healthy: bool, database?: string, collection?: string, error?: string}
     */
    public function healthCheck(): array
    {
        try {
            $databaseName = $this->db->getDatabase();
            if (!$this->db->exists($databaseName)) {
                return [
                    'healthy' => false,
                    'error' => "Database '{$databaseName}' does not exist"
                ];
            }

            $collectionName = $this->collection ?? 'usage';
            if ($this->db->getCollection($collectionName)->isEmpty()) {
                return [
                    'healthy' => false,
                    'database' => $databaseName,
                    'collection' => $collectionName,
                    'error' => "Collection '{$collectionName}' is missing or empty in database '{$databaseName}'"
                ];
            }

            return [
                'healthy' => true,
                'database' => $databaseName,
                'collection' => $collectionName
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function setup(): void
    {
        $this->collection = 'usage';
        if (! $this->db->exists($this->db->getDatabase())) {
            throw new \Exception('You need to create the database before running Usage setup');
        }

        // Event schema is a superset of the gauge schema for the dimensions
        // that exist in both (resourceId, resourceInternalId, teamId,
        // teamInternalId), so a single Database collection backed by the
        // event schema works for both types.
        $attributes = $this->getAttributeDocuments('event');
        $indexDocs = $this->getIndexDocuments('event');

        // Append a `type` column so a single collection can disambiguate event vs gauge rows.
        // ClickHouse uses separate tables instead, so this lives in the Database adapter only.
        $attributes[] = new Document([
            '$id' => 'type',
            'type' => 'string',
            'size' => 16,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);
        $indexDocs[] = new Document([
            '$id' => 'index-type',
            'type' => 'key',
            'attributes' => ['type'],
        ]);

        try {
            $this->db->createCollection(
                $this->collection,
                $attributes,
                $indexDocs
            );
        } catch (DuplicateException) {
            // Collection already exists
        }
    }

    /**
     * Get column definition for Database adapter (not used, but required by SQL parent)
     */
    protected function getColumnDefinition(string $id, string $type = 'event'): string
    {
        return '';
    }

    /**
     * Add metrics in batch (raw append).
     *
     * Database adapter uses a single collection for both types. The $type parameter
     * is stored as a field in each document for query-time differentiation.
     *
     * @param array<array{metric: string, value: int, tags?: array<string,mixed>}> $metrics
     * @param string $type Metric type: 'event' or 'gauge'
     * @param int $batchSize
     * @return bool
     * @throws \Exception
     */
    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        $this->db->getAuthorization()->skip(function () use ($metrics, $type, $batchSize) {
            $documents = [];
            foreach ($metrics as $metric) {
                if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
                    throw new \InvalidArgumentException("Invalid type '{$type}'. Allowed: event, gauge");
                }

                if ($metric['value'] < 0) {
                    throw new \InvalidArgumentException('Value cannot be negative');
                }

                /** @var array<string,mixed> $tags */
                $tags = $metric['tags'] ?? [];

                $columns = Metric::extractColumns($tags, $type);

                $docData = array_merge([
                    '$id' => $this->generateId(),
                    '$permissions' => [],
                    'metric' => $metric['metric'],
                    'value' => $metric['value'],
                    'type' => $type,
                    'time' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                ], $columns);

                $documents[] = new Document($docData);
            }

            foreach (array_chunk($documents, max(1, $batchSize)) as $chunk) {
                foreach ($chunk as $doc) {
                    $this->db->createDocument($this->collection, $doc);
                }
            }
        });

        return true;
    }

    /**
     * Get time series data for metrics.
     *
     * Stub implementation for Database adapter.
     *
     * @param array<string> $metrics
     * @param string $interval
     * @param string $startDate
     * @param string $endDate
     * @param array<Query> $queries
     * @param bool $zeroFill
     * @param string|null $type
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     */
    public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        // Stub: Database adapter time series not yet implemented
        $output = [];
        foreach ($metrics as $metric) {
            $output[$metric] = ['total' => 0, 'data' => []];
        }
        return $output;
    }

    /**
     * Get total value for a single metric.
     *
     * Returns SUM for event metrics, latest value for gauge metrics.
     *
     * @param string $metric
     * @param array<Query> $queries
     * @param string|null $type
     * @return int
     */
    public function getTotal(string $metric, array $queries = [], ?string $type = null): int
    {
        $allQueries = array_merge($queries, [
            Query::equal('metric', [$metric]),
        ]);

        if ($type === Usage::TYPE_GAUGE) {
            // For gauge, return the most recent value by time. find() does
            // not guarantee any ordering, so we explicitly sort + limit
            // here instead of relying on insertion order.
            $gaugeQueries = array_merge($allQueries, [
                Query::orderDesc('time'),
                Query::limit(1),
            ]);
            /** @var array<Metric> $gaugeResults */
            $gaugeResults = $this->find($gaugeQueries, $type);
            if (empty($gaugeResults)) {
                return 0;
            }
            return (int) ($gaugeResults[0]->getValue(0) ?? 0);
        }

        /** @var array<Metric> $results */
        $results = $this->find($allQueries, $type);

        if (empty($results)) {
            return 0;
        }

        if ($type === Usage::TYPE_EVENT) {
            // For events, SUM all values
            $sum = 0;
            foreach ($results as $result) {
                $sum += (int) ($result->getValue(0) ?? 0);
            }
            return $sum;
        }

        // Type is null — partition results by stored type and reject ambiguous mixes.
        $eventResults = [];
        $gaugeResults = [];
        foreach ($results as $result) {
            if ($result->getType() === Usage::TYPE_GAUGE) {
                $gaugeResults[] = $result;
            } else {
                $eventResults[] = $result;
            }
        }

        if (!empty($eventResults) && !empty($gaugeResults)) {
            throw new \Exception(
                "Metric '{$metric}' exists as both event and gauge. "
                . "Specify \$type explicitly to avoid ambiguous aggregation."
            );
        }

        if (!empty($gaugeResults)) {
            // find() returns rows in unspecified order; sort by time so the
            // "latest" gauge sample is deterministic.
            usort(
                $gaugeResults,
                fn (Metric $a, Metric $b): int => strcmp($a->getTime() ?? '', $b->getTime() ?? '')
            );
            $lastResult = end($gaugeResults);
            return (int) ($lastResult->getValue(0) ?? 0);
        }

        $sum = 0;
        foreach ($eventResults as $result) {
            $sum += (int) ($result->getValue(0) ?? 0);
        }

        return $sum;
    }

    /**
     * Get totals for multiple metrics.
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<string, int>
     */
    public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null): array
    {
        if (empty($metrics)) {
            return [];
        }

        $totals = \array_fill_keys($metrics, 0);

        foreach ($metrics as $metric) {
            $totals[$metric] = $this->getTotal($metric, $queries, $type);
        }

        return $totals;
    }

    /**
     * Sum metric values.
     *
     * Events-only by default — summing gauges is semantically meaningless.
     *
     * @param array<Query> $queries
     * @param string $attribute
     * @param string $type 'event' or 'gauge'
     * @return int
     */
    public function sum(array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        /** @var array<Metric> $results */
        $results = $this->find($queries, $type);

        $sum = 0;
        foreach ($results as $result) {
            $sum += (int) ($result->getValue(0) ?? 0);
        }

        return $sum;
    }

    /**
     * Find from daily table — Database adapter falls back to regular find for events.
     *
     * @param array<Query> $queries
     * @return array<Metric>
     */
    public function findDaily(array $queries = []): array
    {
        return $this->find($queries, Usage::TYPE_EVENT);
    }

    /**
     * Sum multiple metrics from daily table — falls back to individual sumDaily calls.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @return array<string, int>
     */
    public function sumDailyBatch(array $metrics, array $queries = []): array
    {
        $totals = \array_fill_keys($metrics, 0);
        foreach ($metrics as $metric) {
            $metricQueries = array_merge($queries, [Query::equal('metric', [$metric])]);
            $totals[$metric] = $this->sumDaily($metricQueries);
        }
        return $totals;
    }

    /**
     * Sum from daily table — Database adapter falls back to regular sum for events.
     *
     * @param array<Query> $queries
     * @param string $attribute
     * @return int
     */
    public function sumDaily(array $queries = [], string $attribute = 'value'): int
    {
        return $this->sum($queries, $attribute, Usage::TYPE_EVENT);
    }

    /**
     * Convert Utopia\Query\Query to Utopia\Database\Query for use with the Database class.
     *
     * @param array<Query> $queries
     * @return array<DatabaseQuery>
     * @throws \Exception When a groupBy attribute is not a valid dimension column,
     *                    or when groupBy is used without groupByInterval.
     */
    private function convertQueriesToDatabase(array $queries): array
    {
        $this->validateGroupByQueries($queries);

        $dbQueries = [];
        foreach ($queries as $query) {
            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            switch ($method) {
                case Query::TYPE_EQUAL:
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::equal($attribute, $values);
                    break;
                case Query::TYPE_GREATER:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::greaterThan($attribute, $value);
                    }
                    break;
                case Query::TYPE_LESSER:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::lessThan($attribute, $value);
                    }
                    break;
                case Query::TYPE_BETWEEN:
                    if (count($values) >= 2) {
                        /** @var bool|float|int|string $start */
                        $start = $values[0];
                        /** @var bool|float|int|string $end */
                        $end = $values[1];
                        $dbQueries[] = DatabaseQuery::between($attribute, $start, $end);
                    }
                    break;
                case Query::TYPE_CONTAINS:
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::contains($attribute, $values);
                    break;
                case Query::TYPE_NOT_EQUAL:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::notEqual($attribute, $value);
                    }
                    break;
                case Query::TYPE_NOT_CONTAINS:
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::notContains($attribute, $values);
                    break;
                case Query::TYPE_NOT_BETWEEN:
                    if (count($values) >= 2) {
                        /** @var bool|float|int|string $start */
                        $start = $values[0];
                        /** @var bool|float|int|string $end */
                        $end = $values[1];
                        $dbQueries[] = DatabaseQuery::notBetween($attribute, $start, $end);
                    }
                    break;
                case Query::TYPE_STARTS_WITH:
                    if (!empty($values) && is_string($values[0])) {
                        $dbQueries[] = DatabaseQuery::startsWith($attribute, $values[0]);
                    }
                    break;
                case Query::TYPE_ENDS_WITH:
                    if (!empty($values) && is_string($values[0])) {
                        $dbQueries[] = DatabaseQuery::endsWith($attribute, $values[0]);
                    }
                    break;
                case Query::TYPE_LESSER_EQUAL:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::lessThanEqual($attribute, $value);
                    }
                    break;
                case Query::TYPE_GREATER_EQUAL:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::greaterThanEqual($attribute, $value);
                    }
                    break;
                case Query::TYPE_ORDER_DESC:
                    $dbQueries[] = DatabaseQuery::orderDesc($attribute);
                    break;
                case Query::TYPE_ORDER_ASC:
                    $dbQueries[] = DatabaseQuery::orderAsc($attribute);
                    break;
                case Query::TYPE_LIMIT:
                    if (!empty($values)) {
                        /** @var int|string $val */
                        $val = $values[0] ?? 0;
                        $dbQueries[] = DatabaseQuery::limit((int) $val);
                    }
                    break;
                case Query::TYPE_OFFSET:
                    if (!empty($values)) {
                        /** @var int|string $val */
                        $val = $values[0] ?? 0;
                        $dbQueries[] = DatabaseQuery::offset((int) $val);
                    }
                    break;

                case UsageQuery::TYPE_GROUP_BY_INTERVAL:
                case UsageQuery::TYPE_GROUP_BY:
                    // groupByInterval and groupBy are not pushed down to the
                    // Database adapter; callers get raw (non-aggregated) results.
                    // Validation runs in validateGroupByQueries() before this loop.
                    break;
            }
        }

        return $dbQueries;
    }

    /**
     * Validate groupBy / groupByInterval interactions in the supplied queries.
     *
     * Mirrors the ClickHouse adapter contract: groupBy attributes must exist on
     * the matching schema (event vs gauge — we default to the broader event set
     * for the Database adapter since both share one collection), and groupBy
     * does not push the aggregation hints down to SQL — it just validates them.
     *
     * @param array<Query> $queries
     * @throws \Exception
     */
    private function validateGroupByQueries(array $queries): void
    {
        $allowed = array_unique(array_merge(Metric::EVENT_COLUMNS, Metric::GAUGE_COLUMNS));

        foreach ($queries as $query) {
            if ($query->getMethod() !== UsageQuery::TYPE_GROUP_BY) {
                continue;
            }

            $attribute = $query->getAttribute();

            if (!in_array($attribute, $allowed, true)) {
                throw new \Exception(
                    "Invalid groupBy attribute '{$attribute}'. Allowed: " . implode(', ', $allowed)
                );
            }
        }
    }

    /**
     * @param array<Query> $queries
     * @param string|null $type
     */
    public function purge(array $queries = [], ?string $type = null): bool
    {
        $queries = $this->withTypeFilter($queries, $type);

        $this->db->getAuthorization()->skip(function () use ($queries) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            $dbQueries[] = DatabaseQuery::limit(100);

            do {
                $documents = $this->db->find(
                    collection: $this->collection,
                    queries: $dbQueries,
                );

                foreach ($documents as $document) {
                    $this->db->deleteDocument($this->collection, $document->getId());
                }
            } while (! empty($documents));
        });

        return true;
    }

    /**
     * Find metrics using Query objects.
     *
     * When $type is non-null an additional `type = $type` filter is applied
     * so callers can isolate event vs gauge rows. When $type is null both
     * are returned (caller distinguishes via Metric::getType()).
     *
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<Metric>
     */
    public function find(array $queries = [], ?string $type = null): array
    {
        $queries = $this->withTypeFilter($queries, $type);

        /** @var array<Document> $result */
        $result = $this->db->getAuthorization()->skip(function () use ($queries) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            return $this->db->find(
                collection: $this->collection,
                queries: $dbQueries,
            );
        });

        return \array_map(fn ($doc) => new Metric($doc->getArrayCopy()), $result);
    }

    /**
     * Count metrics using Query objects.
     *
     * When $max is non-null the count is bounded at the database level —
     * utopia-php/database accepts a `$max` argument that pushes the cap
     * down into the underlying SQL.
     *
     * @param array<Query> $queries
     * @param string|null $type
     * @param int|null $max Optional upper bound (inclusive) for the count
     * @return int
     */
    public function count(array $queries = [], ?string $type = null, ?int $max = null): int
    {
        $queries = $this->withTypeFilter($queries, $type);

        /** @var int $count */
        $count = $this->db->getAuthorization()->skip(function () use ($queries, $max) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            return $this->db->count(
                collection: $this->collection,
                queries: $dbQueries,
                max: $max
            );
        });

        return $count;
    }

    /**
     * Append a `type = $type` filter to the query list when $type is non-null.
     *
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<Query>
     */
    private function withTypeFilter(array $queries, ?string $type): array
    {
        if ($type === null) {
            return $queries;
        }

        if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
            throw new \InvalidArgumentException("Invalid type '{$type}'. Allowed: " . Usage::TYPE_EVENT . ', ' . Usage::TYPE_GAUGE);
        }

        return array_merge($queries, [Query::equal('type', [$type])]);
    }

    /**
     * Set the namespace prefix for table names.
     *
     * @param string $namespace
     * @return self
     */
    public function setNamespace(string $namespace): self
    {
        $this->db->setNamespace($namespace);
        return $this;
    }

    /**
     * Set the tenant ID for multi-tenant support.
     *
     * @param string|null $tenant
     * @return self
     */
    public function setTenant(?string $tenant): self
    {
        if ($tenant !== null && !is_numeric($tenant)) {
            throw new \InvalidArgumentException(
                'Database adapter requires a numeric tenant ID, got: ' . $tenant
            );
        }

        $this->db->setTenant($tenant !== null ? (int) $tenant : null);
        return $this;
    }

    /**
     * Enable or disable shared tables mode.
     *
     * @param bool $sharedTables
     * @return self
     */
    public function setSharedTables(bool $sharedTables): self
    {
        $this->db->setSharedTables($sharedTables);
        return $this;
    }
}
