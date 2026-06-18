<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Verifies that the per-dim rollup MV slate (by_path / by_country /
 * by_service / by_method_status) is created by setup() and that writes to
 * the raw events table fan out into each rollup table.
 */
class ClickHouseDimRollupTest extends TestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    /** @var array<int, array{name: string, dims: array<int, string>}> */
    private array $rollups = [
        ['name' => 'by_path', 'dims' => ['path']],
        ['name' => 'by_country', 'dims' => ['country']],
        ['name' => 'by_service', 'dims' => ['service']],
        ['name' => 'by_method_status', 'dims' => ['method', 'status']],
    ];

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $this->adapter->setNamespace('utopia_usage_dim_rollup');
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

    public function testAllFourRollupTablesAreCreated(): void
    {
        foreach ($this->rollups as $rollup) {
            $table = $this->getDimRollupTable($rollup['name']);
            $this->assertTrue(
                $this->tableExists($table),
                "Rollup table {$table} should exist after setup()"
            );

            $mv = $table . '_mv';
            $this->assertTrue(
                $this->tableExists($mv),
                "Rollup MV {$mv} should exist after setup()"
            );
        }
    }

    public function testWritesPropagateToEachRollup(): void
    {
        $this->assertTrue($this->usage->addBatch([
            [
                'metric' => 'dim.rollup.fanout',
                'value' => 42,
                'tags' => [
                    'path' => '/v1/dim-rollup/fanout',
                    'method' => 'POST',
                    'status' => '201',
                    'service' => 'storage',
                    'country' => 'us',
                ],
            ],
        ], Usage::TYPE_EVENT));

        // MVs are synchronous on insert by default in ClickHouse, but the
        // SummingMergeTree won't merge identical sort keys until background
        // merges run — we count rows, not sum, to check propagation.
        foreach ($this->rollups as $rollup) {
            $table = $this->getDimRollupTable($rollup['name']);
            $count = $this->countRows($table);
            $this->assertGreaterThan(
                0,
                $count,
                "Rollup {$table} should have at least one row after raw INSERT"
            );
        }
    }

    public function testRollupTotalsMatchRawSum(): void
    {
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'dim.rollup.totals', 'value' => 10, 'tags' => ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']],
            ['metric' => 'dim.rollup.totals', 'value' => 20, 'tags' => ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']],
            ['metric' => 'dim.rollup.totals', 'value' => 30, 'tags' => ['path' => '/v1/b', 'method' => 'POST', 'status' => '201', 'service' => 'databases', 'country' => 'de']],
        ], Usage::TYPE_EVENT));

        foreach ($this->rollups as $rollup) {
            $table = $this->getDimRollupTable($rollup['name']);
            $total = $this->sumValue($table, 'dim.rollup.totals');
            $this->assertSame(60, $total, "Sum of MV {$table} must equal 10+20+30=60");
        }
    }

    private function getDimRollupTable(string $name): string
    {
        $reflection = new ReflectionClass($this->adapter);
        $method = $reflection->getMethod('getDimRollupTableName');
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

    private function sumValue(string $table, string $metric): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $databaseValue = $dbProp->getValue($this->adapter);
        $database = is_string($databaseValue) ? $databaseValue : '';

        $sql = "SELECT sum(value) AS s FROM `{$database}`.`{$table}` "
            . "WHERE metric = '" . addslashes($metric) . "' FORMAT JSON";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $raw = $query->invoke($this->adapter, $sql, []);
        $rawString = is_string($raw) ? $raw : '';
        $json = json_decode($rawString, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            return (int) ($json['data'][0]['s'] ?? 0);
        }
        return 0;
    }
}
