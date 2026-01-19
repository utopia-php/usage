<?php

namespace Utopia\Usage\Adapter;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query;
use Utopia\Exception;
use Utopia\Usage\Metric;
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
            ? '1000-01-01 00:00:00'
            : $now->format(Usage::PERIODS[$period]);

        $this->db->getAuthorization()->skip(function () use ($metric, $value, $period, $time, $tags) {
            $this->db->createDocument($this->collection, new Document([
                '$permissions' => [],
                'metric' => $metric,
                'value' => $value,
                'period' => $period,
                'time' => $time,
                'tags' => $tags,
            ]));
        });

        return true;
    }

    public function logBatch(array $metrics): bool
    {
        $this->db->getAuthorization()->skip(function () use ($metrics) {
            $documents = \array_map(function ($metric) {
                $period = $metric['period'] ?? '1h';

                if (! isset(Usage::PERIODS[$period])) {
                    throw new \InvalidArgumentException('Invalid period. Allowed: ' . implode(', ', array_keys(Usage::PERIODS)));
                }

                $now = new \DateTime();
                $time = $period === 'inf'
                    ? '1000-01-01 00:00:00'
                    : $now->format(Usage::PERIODS[$period]);

                return new Document([
                    '$permissions' => [],
                    'metric' => $metric['metric'],
                    'value' => $metric['value'],
                    'period' => $period,
                    'time' => $time,
                    'tags' => $metric['tags'] ?? [],
                ]);
            }, $metrics);

            $this->db->createDocuments($this->collection, $documents);
        });

        return true;
    }

    public function getByPeriod(string $metric, string $period, array $queries = []): array
    {
        /** @var array<Document> $result */
        $result = $this->db->getAuthorization()->skip(function () use ($queries, $metric, $period) {
            $queries[] = Query::equal('metric', [$metric]);
            $queries[] = Query::equal('period', [$period]);
            $queries[] = Query::orderDesc();

            return $this->db->find(
                collection: $this->collection,
                queries: $queries,
            );
        });

        return \array_map(fn ($doc) => new Metric($doc->getArrayCopy()), $result);
    }

    public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array
    {
        /** @var array<Document> $result */
        $result = $this->db->getAuthorization()->skip(function () use ($queries, $metric, $startDate, $endDate) {
            $queries[] = Query::equal('metric', [$metric]);
            $queries[] = Query::greaterThanEqual('time', $startDate);
            $queries[] = Query::lessThanEqual('time', $endDate);
            $queries[] = Query::orderDesc();

            return $this->db->find(
                collection: $this->collection,
                queries: $queries,
            );
        });

        return \array_map(fn ($doc) => new Metric($doc->getArrayCopy()), $result);
    }

    public function countByPeriod(string $metric, string $period, array $queries = []): int
    {
        /** @var int $count */
        $count = $this->db->getAuthorization()->skip(function () use ($queries, $metric, $period) {
            return $this->db->count(
                collection: $this->collection,
                queries: [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    ...$queries,
                ]
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
                        Query::lessThan('time', $datetime),
                        Query::limit(100),
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
            return $this->db->find(
                collection: $this->collection,
                queries: $queries,
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
            return $this->db->count(
                collection: $this->collection,
                queries: $queries
            );
        });

        return $count;
    }
}
