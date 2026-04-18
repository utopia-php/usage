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

        // Use event attributes which is a superset (includes path/method/status/resource/resourceId)
        $attributes = $this->getAttributeDocuments('event');
        $indexDocs = $this->getIndexDocuments('event');

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
    public function addBatch(array $metrics, string $type = Usage::TYPE_EVENT, int $batchSize = 1000): bool
    {
        $this->db->getAuthorization()->skip(function () use ($metrics, $type, $batchSize) {
            $documents = [];
            foreach ($metrics as $metric) {
                if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
                    throw new \InvalidArgumentException("Invalid type '{$type}'. Allowed: event, gauge");
                }

                $tags = $metric['tags'] ?? [];
                ksort($tags);

                $docData = [
                    '$id' => $this->generateId(),
                    '$permissions' => [],
                    'metric' => $metric['metric'],
                    'value' => $metric['value'],
                    'time' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                    'tags' => $tags,
                ];

                // For events, extract event-specific columns from tags
                if ($type === Usage::TYPE_EVENT) {
                    foreach (Metric::EVENT_COLUMNS as $col) {
                        $docData[$col] = $tags[$col] ?? null;
                    }
                }

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

        // If type is specified, query only that type
        $queryType = $type;
        /** @var array<Metric> $results */
        $results = $this->find($allQueries, $queryType);

        if (empty($results)) {
            return 0;
        }

        if ($type === Usage::TYPE_GAUGE) {
            // For gauge, return the last (most recently inserted) value
            $lastResult = end($results);
            return $lastResult->getValue(0) ?? 0;
        }

        if ($type === Usage::TYPE_EVENT) {
            // For events, SUM all values
            $sum = 0;
            foreach ($results as $result) {
                $sum += (int) ($result->getValue(0) ?? 0);
            }
            return $sum;
        }

        // Type is null — try to detect from results
        $firstType = $results[0]->getType();

        if ($firstType === 'gauge') {
            $lastResult = end($results);
            return $lastResult->getValue(0) ?? 0;
        }

        // Default to SUM for events
        $sum = 0;
        foreach ($results as $result) {
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
     * @param array<Query> $queries
     * @param string $attribute
     * @param string|null $type
     * @return int
     */
    public function sum(array $queries = [], string $attribute = 'value', ?string $type = null): int
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
     */
    private function convertQueriesToDatabase(array $queries): array
    {
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
                    // groupByInterval is not supported by the Database adapter.
                    // Silently skip — callers get raw (non-aggregated) results.
                    break;
            }
        }

        return $dbQueries;
    }

    /**
     * @param array<Query> $queries
     * @param string|null $type
     */
    public function purge(array $queries = [], ?string $type = null): bool
    {
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
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<Metric>
     */
    public function find(array $queries = [], ?string $type = null): array
    {
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
     * @param array<Query> $queries
     * @param string|null $type
     * @return int
     */
    public function count(array $queries = [], ?string $type = null): int
    {
        /** @var int $count */
        $count = $this->db->getAuthorization()->skip(function () use ($queries) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            return $this->db->count(
                collection: $this->collection,
                queries: $dbQueries
            );
        });

        return $count;
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
