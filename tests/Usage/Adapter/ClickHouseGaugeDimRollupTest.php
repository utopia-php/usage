<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Verifies that the per-dim gauge rollup MV slate (by_service, by_resource)
 * is created by setup() as AggregatingMergeTree tables that capture
 * argMaxState(value, time), and that writes to the raw gauges table fan out
 * into each rollup table.
 */
class ClickHouseGaugeDimRollupTest extends TestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    /** @var array<int, array{name: string, dims: array<int, string>}> */
    private array $rollups = [
        ['name' => 'by_service', 'dims' => ['service']],
        ['name' => 'by_resource', 'dims' => ['resource']],
    ];

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $this->adapter->setNamespace('utopia_usage_gauge_dim_rollup');
        $this->adapter->setSharedTables(true);
        $this->adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $this->adapter->setDatabase($database);
        }

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge();
    }

    protected function tearDown(): void
    {
        $this->usage->purge();
    }

    public function testGaugeRollupTablesAreCreated(): void
    {
        foreach ($this->rollups as $rollup) {
            $table = $this->getGaugeDimRollupTable($rollup['name']);
            $this->assertTrue(
                $this->tableExists($table),
                "Gauge rollup table {$table} should exist after setup()"
            );

            $mv = $table . '_mv';
            $this->assertTrue(
                $this->tableExists($mv),
                "Gauge rollup MV {$mv} should exist after setup()"
            );
        }
    }

    public function testWritesPropagateToEachGaugeRollup(): void
    {
        $this->assertTrue($this->usage->addBatch([
            [
                'metric' => 'gauge.fanout',
                'value' => 1024,
                'tags' => [
                    'service' => 'storage',
                    'resource' => 'file',
                ],
            ],
        ], Usage::TYPE_GAUGE));

        foreach ($this->rollups as $rollup) {
            $table = $this->getGaugeDimRollupTable($rollup['name']);
            $count = $this->countRows($table);
            $this->assertGreaterThan(
                0,
                $count,
                "Gauge rollup {$table} should have at least one row after raw INSERT"
            );
        }
    }

    public function testArgMaxMergeReturnsLatestPerGroup(): void
    {
        $this->seedGaugeRow('gauge.argmax', 100, '-3 hours', ['service' => 'storage', 'resource' => 'file']);
        $this->seedGaugeRow('gauge.argmax', 200, '-2 hours', ['service' => 'storage', 'resource' => 'file']);
        $this->seedGaugeRow('gauge.argmax', 50, '-1 hour', ['service' => 'databases', 'resource' => 'database']);

        $serviceValues = $this->argMaxMergeBy('by_service', 'service', 'gauge.argmax');
        $this->assertSame(200, $serviceValues['storage'] ?? -1, 'latest storage snapshot should be 200');
        $this->assertSame(50, $serviceValues['databases'] ?? -1, 'latest databases snapshot should be 50');

        $resourceValues = $this->argMaxMergeBy('by_resource', 'resource', 'gauge.argmax');
        $this->assertSame(200, $resourceValues['file'] ?? -1);
        $this->assertSame(50, $resourceValues['database'] ?? -1);
    }

    public function testPurgeByResourceClearsAllGaugeRollupsForThatDay(): void
    {
        $metric = 'gauge.purge-cross-dim';

        $this->seedGaugeRow($metric, 77, '-1 hour', ['service' => 'storage', 'resource' => 'file']);

        // Purging by resource — only by_resource stores it. by_service must
        // still drop its stale day rows or argMaxMerge will continue to
        // return the deleted snapshot.
        $this->usage->purge([
            Query::equal('metric', [$metric]),
            Query::equal('resource', ['file']),
        ], Usage::TYPE_GAUGE);

        foreach ($this->rollups as $rollup) {
            $table = $this->getGaugeDimRollupTable($rollup['name']);
            $count = $this->countRows($table);
            $this->assertSame(
                0,
                $count,
                "Gauge rollup {$table} must drop the stale day rows when purging across dims (Greptile finding for line 4516)"
            );
        }
    }

    public function testPurgeByIdFiltersDropAllGaugeRollupRowsForThatDay(): void
    {
        $metric = 'gauge.purge-by-id';

        $this->seedGaugeRow($metric, 88, '-1 hour', ['service' => 'storage', 'resource' => 'file']);

        // id is raw-only on gauges. The rollups can't narrow by it; they
        // must whole-day delete instead of attempting `WHERE id = ...`.
        $this->usage->purge([
            Query::equal('metric', [$metric]),
            Query::equal('id', ['nonexistent']),
        ], Usage::TYPE_GAUGE);

        foreach ($this->rollups as $rollup) {
            $table = $this->getGaugeDimRollupTable($rollup['name']);
            $count = $this->countRows($table);
            $this->assertSame(0, $count, "Gauge rollup {$table} must whole-day delete when purge filter (id) isn't expressible");
        }
    }

    public function testArgMaxMergeReturnsLatestWhenSameDay(): void
    {
        $this->seedGaugeRow('gauge.sameday', 100, '-3 hours', ['service' => 'storage', 'resource' => 'file']);
        $this->seedGaugeRow('gauge.sameday', 999, '-1 hour', ['service' => 'storage', 'resource' => 'file']);
        $this->seedGaugeRow('gauge.sameday', 50, '-2 hours', ['service' => 'storage', 'resource' => 'file']);

        $serviceValues = $this->argMaxMergeBy('by_service', 'service', 'gauge.sameday');
        $this->assertSame(999, $serviceValues['storage'] ?? -1, 'within a day argMax returns max-by-time, not max-by-value');
    }

    private function getGaugeDimRollupTable(string $name): string
    {
        $reflection = new ReflectionClass($this->adapter);
        $method = $reflection->getMethod('getGaugeDimRollupTableName');
        $method->setAccessible(true);
        $raw = $method->invoke($this->adapter, $name);
        return is_string($raw) ? $raw : '';
    }

    private function tableExists(string $table): bool
    {
        $reflection = new ReflectionClass($this->adapter);
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $databaseValue = $dbProp->getValue($this->adapter);
        $database = is_string($databaseValue) ? $databaseValue : '';

        $sql = "EXISTS TABLE `{$database}`.`{$table}` FORMAT JSON";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $raw = $query->invoke($this->adapter, $sql, []);
        $rawString = is_string($raw) ? $raw : '';
        $json = json_decode($rawString, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            $row = $json['data'][0];
            $value = $row['result'] ?? 0;
            return ((int) $value) === 1;
        }
        return false;
    }

    private function countRows(string $table): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $databaseValue = $dbProp->getValue($this->adapter);
        $database = is_string($databaseValue) ? $databaseValue : '';

        $sql = "SELECT count() AS c FROM `{$database}`.`{$table}` FORMAT JSON";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $raw = $query->invoke($this->adapter, $sql, []);
        $rawString = is_string($raw) ? $raw : '';
        $json = json_decode($rawString, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            return (int) ($json['data'][0]['c'] ?? 0);
        }
        return 0;
    }

    /**
     * @param array<string, string> $tags
     */
    private function seedGaugeRow(string $metric, int $value, string $modifier, array $tags = []): void
    {
        $reflection = new ReflectionClass($this->adapter);
        $gauges = $reflection->getMethod('getGaugesTableName');
        $gauges->setAccessible(true);
        $raw = $gauges->invoke($this->adapter);
        $gaugesTable = is_string($raw) ? $raw : '';
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $dbRaw = $dbProp->getValue($this->adapter);
        $database = is_string($dbRaw) ? $dbRaw : '';

        $time = (new \DateTime($modifier, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $id = bin2hex(random_bytes(16));

        $cols = ['id', 'metric', 'value', 'time', 'tenant'];
        $vals = [
            "'{$id}'",
            "'" . addslashes($metric) . "'",
            (string) $value,
            "'{$time}'",
            "'1'",
        ];
        foreach (['service', 'resource'] as $tag) {
            if (isset($tags[$tag])) {
                $cols[] = $tag;
                $vals[] = "'" . addslashes($tags[$tag]) . "'";
            }
        }

        $sql = "INSERT INTO `{$database}`.`{$gaugesTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $query->invoke($this->adapter, $sql, []);
    }

    /**
     * @return array<string, int>
     */
    private function argMaxMergeBy(string $rollupName, string $dim, string $metric): array
    {
        $table = $this->getGaugeDimRollupTable($rollupName);
        $reflection = new ReflectionClass($this->adapter);
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $dbRaw = $dbProp->getValue($this->adapter);
        $database = is_string($dbRaw) ? $dbRaw : '';

        $sql = "SELECT `{$dim}` AS dim, argMaxMerge(value) AS value "
            . "FROM `{$database}`.`{$table}` "
            . "WHERE metric = '" . addslashes($metric) . "' "
            . "GROUP BY `{$dim}` FORMAT JSON";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $raw = $query->invoke($this->adapter, $sql, []);
        $rawString = is_string($raw) ? $raw : '';
        $json = json_decode($rawString, true);
        $out = [];
        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = $row['dim'] ?? null;
                if (!is_string($key)) {
                    continue;
                }
                $out[$key] = (int) ($row['value'] ?? 0);
            }
        }
        return $out;
    }
}
