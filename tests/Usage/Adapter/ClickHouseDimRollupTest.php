<?php

namespace Utopia\Tests\Adapter;

use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Verifies that the per-dim rollup MV slate (by_path / by_country /
 * by_service / by_method_status) is created by setup() and that writes to
 * the raw events table fan out into each rollup table.
 */
class ClickHouseDimRollupTest extends ClickHouseTestCase
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
        $this->adapter = $this->makeAdapter('utopia_usage_dim_rollup');
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

    public function testPurgeByIdFiltersDropAllRollupRowsForThatDay(): void
    {
        $metric = 'dim.rollup.purge-by-id';

        $this->assertTrue($this->usage->addBatch([
            ['metric' => $metric, 'value' => 11, 'tags' => ['path' => '/v1/x', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']],
        ], Usage::TYPE_EVENT));

        $this->usage->purge([
            Query::equal('metric', [$metric]),
            Query::equal('id', ['nonexistent-id']),
        ], Usage::TYPE_EVENT);

        foreach ($this->rollups as $rollup) {
            $table = $this->getDimRollupTable($rollup['name']);
            $total = $this->sumValue($table, $metric);
            $this->assertSame(
                0,
                $total,
                "Rollup {$table} must drop the stale day rows when the purge filter (id) isn't expressible on the rollup"
            );
        }
    }

    public function testPurgeByPathClearsAllRollupsForThatDay(): void
    {
        $metric = 'dim.rollup.purge-cross-dim';

        $this->assertTrue($this->usage->addBatch([
            ['metric' => $metric, 'value' => 7, 'tags' => ['path' => '/v1/x', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']],
        ], Usage::TYPE_EVENT));

        $this->usage->purge([
            Query::equal('metric', [$metric]),
            Query::equal('path', ['/v1/x']),
        ], Usage::TYPE_EVENT);

        foreach ($this->rollups as $rollup) {
            $table = $this->getDimRollupTable($rollup['name']);
            $total = $this->sumValue($table, $metric);
            $this->assertSame(
                0,
                $total,
                "Rollup {$table} must contain no rows for {$metric} after purge — staleness across dims is the fix Greptile flagged"
            );
        }
    }

    private function getDimRollupTable(string $name): string
    {
        return $this->resolveTableName($this->adapter, 'getDimRollupTableName', [$name]);
    }

    private function tableExists(string $table): bool
    {
        $database = $this->databaseName($this->adapter);
        $raw = $this->queryRaw($this->adapter, "EXISTS TABLE `{$database}`.`{$table}` FORMAT JSON");
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            $value = $json['data'][0]['result'] ?? 0;
            return ((int) $value) === 1;
        }
        return false;
    }

    private function countRows(string $table): int
    {
        $database = $this->databaseName($this->adapter);
        $raw = $this->queryRaw($this->adapter, "SELECT count() AS c FROM `{$database}`.`{$table}` FORMAT JSON");
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            return (int) ($json['data'][0]['c'] ?? 0);
        }
        return 0;
    }

    private function sumValue(string $table, string $metric): int
    {
        $database = $this->databaseName($this->adapter);
        $sql = "SELECT sum(value) AS s FROM `{$database}`.`{$table}` "
            . "WHERE metric = '" . addslashes($metric) . "' FORMAT JSON";
        $raw = $this->queryRaw($this->adapter, $sql);
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            return (int) ($json['data'][0]['s'] ?? 0);
        }
        return 0;
    }
}
