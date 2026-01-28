<?php

namespace Utopia\Usage\Adapter;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query as DatabaseQuery;
use Utopia\Exception;
use Utopia\Usage\Metric;
use Utopia\Usage\Query;
use Utopia\Usage\Usage;

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

    public function setup(): void
    {
        $this->collection = 'usage';
        if (! $this->db->exists($this->db->getDatabase())) {
            throw new Exception('You need to create the database before running Usage setup');
        }

        // Use column and index definitions from parent SQL adapter
        $attributes = $this->getAttributeDocuments();
        $indexDocs = $this->getIndexDocuments();

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
    protected function getColumnDefinition(string $id): string
    {
        // Not used in Database adapter, but required by SQL abstract class
        return '';
    }

    public function log(string $metric, int $value, string $period = '1h', array $tags = []): bool
    {
        if (! isset(Usage::PERIODS[$period])) {
            throw new \InvalidArgumentException('Invalid period. Allowed: ' . implode(', ', array_keys(Usage::PERIODS)));
        }

        $now = new \DateTime();
        $time = $period === 'inf'
            ? null
            : $now->format(Usage::PERIODS[$period]);

        // Sort tags for consistent storage
        ksort($tags);
        $id = $this->buildDeterministicId($metric, $period, $time);

        $this->db->getAuthorization()->skip(function () use ($metric, $value, $period, $time, $tags, $id) {
            $doc = new Document([
                '$id' => $id,
                '$permissions' => [],
                'metric' => $metric,
                'value' => $value,
                'period' => $period,
                'time' => $time,
                'tags' => $tags,
            ]);

            $this->db->upsertDocumentsWithIncrease($this->collection, 'value', [$doc]);
        });

        return true;
    }

    public function logBatch(array $metrics): bool
    {
        $this->db->getAuthorization()->skip(function () use ($metrics) {
            $documentsById = [];
            foreach ($metrics as $metric) {
                $period = $metric['period'] ?? '1h';

                if (! isset(Usage::PERIODS[$period])) {
                    throw new \InvalidArgumentException('Invalid period. Allowed: ' . implode(', ', array_keys(Usage::PERIODS)));
                }

                $now = new \DateTime();
                $time = $period === 'inf'
                    ? null
                    : $now->format(Usage::PERIODS[$period]);

                $tags = $metric['tags'] ?? [];
                ksort($tags);

                $id = $this->buildDeterministicId($metric['metric'], $period, $time);

                if (isset($documentsById[$id])) {
                    $documentsById[$id]['value'] += $metric['value'];
                } else {
                    $documentsById[$id] = [
                        '$id' => $id,
                        '$permissions' => [],
                        'metric' => $metric['metric'],
                        'value' => $metric['value'],
                        'period' => $period,
                        'time' => $time,
                        'tags' => $tags,
                    ];
                }
            }

            $documents = [];
            foreach ($documentsById as $doc) {
                $documents[] = new Document($doc);
            }

            if (!empty($documents)) {
                $this->db->upsertDocumentsWithIncrease($this->collection, 'value', $documents);
            }
        });

        return true;
    }

    /**
     * Convert Utopia\Usage\Query to Utopia\Database\Query for use with the Database class.
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
                case Query::TYPE_IN:
                    // For IN queries, the values are the items to match
                    // Create using equal with array values for compatibility
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::contains($attribute, $values);
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
            }
        }

        return $dbQueries;
    }

    public function getByPeriod(string $metric, string $period, array $queries = []): array
    {
        /** @var array<Document> $result */
        $result = $this->db->getAuthorization()->skip(function () use ($queries, $metric, $period) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            $dbQueries[] = DatabaseQuery::equal('metric', [$metric]);
            $dbQueries[] = DatabaseQuery::equal('period', [$period]);
            $dbQueries[] = DatabaseQuery::orderDesc();

            return $this->db->find(
                collection: $this->collection,
                queries: $dbQueries,
            );
        });

        return \array_map(fn ($doc) => new Metric($doc->getArrayCopy()), $result);
    }

    public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array
    {
        /** @var array<Document> $result */
        $result = $this->db->getAuthorization()->skip(function () use ($queries, $metric, $startDate, $endDate) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            $dbQueries[] = DatabaseQuery::equal('metric', [$metric]);
            $dbQueries[] = DatabaseQuery::greaterThanEqual('time', $startDate);
            $dbQueries[] = DatabaseQuery::lessThanEqual('time', $endDate);
            $dbQueries[] = DatabaseQuery::orderDesc();

            return $this->db->find(
                collection: $this->collection,
                queries: $dbQueries,
            );
        });

        return \array_map(fn ($doc) => new Metric($doc->getArrayCopy()), $result);
    }

    public function countByPeriod(string $metric, string $period, array $queries = []): int
    {
        /** @var int $count */
        $count = $this->db->getAuthorization()->skip(function () use ($queries, $metric, $period) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            $dbQueries[] = DatabaseQuery::equal('metric', [$metric]);
            $dbQueries[] = DatabaseQuery::equal('period', [$period]);

            return $this->db->count(
                collection: $this->collection,
                queries: $dbQueries
            );
        });

        return $count;
    }

    public function sumByPeriod(string $metric, string $period, array $queries = []): int
    {
        /** @var array<Document> $results */
        $results = $this->getByPeriod($metric, $period, $queries);

        $sum = 0;
        foreach ($results as $result) {
            $sum += $result->getAttribute('value', 0);
        }

        return $sum;
    }

    public function purge(string $datetime): bool
    {
        $this->db->getAuthorization()->skip(function () use ($datetime) {
            do {
                $documents = $this->db->find(
                    collection: $this->collection,
                    queries: [
                        DatabaseQuery::lessThan('time', $datetime),
                        DatabaseQuery::limit(100),
                    ]
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
     * @return array<Metric>
     */
    public function find(array $queries = []): array
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
     * @return int
     */
    public function count(array $queries = []): int
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
}
