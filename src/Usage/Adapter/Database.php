<?php

namespace Utopia\Usage\Adapter;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query;
use Utopia\Exception;
use Utopia\Usage\Adapter;
use Utopia\Usage\Metric;

class Database extends Adapter
{
    protected string $collection = 'usage_metrics';

    /** @var array<string,string> */
    public const PERIODS = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00',
    ];

    private UtopiaDatabase $db;

    public function __construct(UtopiaDatabase $db)
    {
        $this->db = $db;
    }

    public function getName(): string
    {
        return 'Database';
    }

    public function setup(string $table, array $columns, array $indexes): void
    {
        $this->collection = $table;
        if (! $this->db->exists($this->db->getDatabase())) {
            throw new Exception('You need to create the database before running Usage setup');
        }

        $attributes = \array_map(function ($attribute) {
            return new Document($attribute);
        }, $columns);

        $indexes = \array_map(function ($index) {
            return new Document($index);
        }, $indexes);

        try {
            $this->db->createCollection(
                $table,
                $attributes,
                $indexes
            );
        } catch (DuplicateException) {
            // Collection already exists
        }
    }

    public function log(string $metric, int $value, string $period = '1h', array $tags = []): bool
    {
        if (! isset(self::PERIODS[$period])) {
            throw new \InvalidArgumentException('Invalid period. Allowed: ' . implode(', ', array_keys(self::PERIODS)));
        }

        $now = new \DateTime();
        $time = $period === 'inf'
            ? '1000-01-01 00:00:00'
            : $now->format(self::PERIODS[$period]);

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

                if (! isset(self::PERIODS[$period])) {
                    throw new \InvalidArgumentException('Invalid period. Allowed: ' . implode(', ', array_keys(self::PERIODS)));
                }

                $now = new \DateTime();
                $time = $period === 'inf'
                    ? '1000-01-01 00:00:00'
                    : $now->format(self::PERIODS[$period]);

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
}
