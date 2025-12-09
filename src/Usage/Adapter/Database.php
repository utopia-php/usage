<?php

namespace Utopia\Usage\Adapter;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query;
use Utopia\Exception;
use Utopia\Usage\Adapter;

class Database extends Adapter
{
    public const COLLECTION = 'usage';

    /** @var array<string,string> */
    public const PERIODS = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00',
    ];

    public const ATTRIBUTES = [
        [
            '$id' => 'metric',
            'type' => UtopiaDatabase::VAR_STRING,
            'size' => 255,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'value',
            'type' => UtopiaDatabase::VAR_INTEGER,
            'size' => 0,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'period',
            'type' => UtopiaDatabase::VAR_STRING,
            'size' => 16,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'time',
            'type' => UtopiaDatabase::VAR_DATETIME,
            'format' => '',
            'size' => 0,
            'signed' => true,
            'required' => false,
            'array' => false,
            'filters' => ['datetime'],
        ],
        [
            '$id' => 'tags',
            'type' => UtopiaDatabase::VAR_STRING,
            'size' => 16777216,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => ['json'],
        ],
    ];

    public const INDEXES = [
        [
            '$id' => 'index-metric',
            'type' => UtopiaDatabase::INDEX_KEY,
            'attributes' => ['metric'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-period',
            'type' => UtopiaDatabase::INDEX_KEY,
            'attributes' => ['period'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-metric-period',
            'type' => UtopiaDatabase::INDEX_KEY,
            'attributes' => ['metric', 'period'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-time',
            'type' => UtopiaDatabase::INDEX_KEY,
            'attributes' => ['time'],
            'lengths' => [],
            'orders' => [UtopiaDatabase::ORDER_DESC],
        ],
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

    public function setup(): void
    {
        if (! $this->db->exists($this->db->getDatabase())) {
            throw new Exception('You need to create the database before running Usage setup');
        }

        $attributes = \array_map(function ($attribute) {
            return new Document($attribute);
        }, self::ATTRIBUTES);

        $indexes = \array_map(function ($index) {
            return new Document($index);
        }, self::INDEXES);

        try {
            $this->db->createCollection(
                self::COLLECTION,
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
        $time = $now->format(self::PERIODS[$period]);

        $this->db->getAuthorization()->skip(function () use ($metric, $value, $period, $time, $tags) {
            $this->db->createDocument(self::COLLECTION, new Document([
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
                $time = $now->format(self::PERIODS[$period]);

                return new Document([
                    '$permissions' => [],
                    'metric' => $metric['metric'],
                    'value' => $metric['value'],
                    'period' => $period,
                    'time' => $time,
                    'tags' => $metric['tags'] ?? [],
                ]);
            }, $metrics);

            $this->db->createDocuments(self::COLLECTION, $documents);
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
                collection: self::COLLECTION,
                queries: $queries,
            );
        });

        return $result;
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
                collection: self::COLLECTION,
                queries: $queries,
            );
        });

        return $result;
    }

    public function countByPeriod(string $metric, string $period, array $queries = []): int
    {
        /** @var int $count */
        $count = $this->db->getAuthorization()->skip(function () use ($queries, $metric, $period) {
            return $this->db->count(
                collection: self::COLLECTION,
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
                    collection: self::COLLECTION,
                    queries: [
                        Query::lessThan('time', $datetime),
                        Query::limit(100),
                    ]
                );

                foreach ($documents as $document) {
                    $this->db->deleteDocument(self::COLLECTION, $document->getId());
                }
            } while (! empty($documents));
        });

        return true;
    }
}
