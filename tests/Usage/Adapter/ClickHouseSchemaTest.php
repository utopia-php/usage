<?php

namespace Utopia\Tests\Adapter;

use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Asserts the events / gauges / daily / dim-rollup tables emerge from
 * setup() with the expected codecs, LowCardinality assignments, indexes
 * and UTC pinning.
 */
class ClickHouseSchemaTest extends ClickHouseTestCase
{
    private ClickHouseAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->makeAdapter('utopia_usage_schema');
        $usage = new Usage($this->adapter);
        $usage->setup();
    }

    public function testEventsTableCarriesCodecsAndLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getEventsTableName'));

        $this->assertStringContainsString("`time` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)", $ddl);
        $this->assertStringContainsString('`id` String CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`path` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`hostname` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`teamId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`osVersion` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`deviceModel` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
    }

    public function testEventsTableSwapsBloomForSetOnLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getEventsTableName'));

        $this->assertStringContainsString('`index-status` status TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-method` method TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-country` country TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-service` service TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-clientType` clientType TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-osName` osName TYPE set(0)', $ddl);

        $this->assertStringContainsString('`index-path` path TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-hostname` hostname TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-resourceId` resourceId TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-teamId` teamId TYPE bloom_filter', $ddl);
    }

    public function testDailyTableMatchesPrePrSchema(): void
    {
        $database = $this->databaseName($this->adapter);
        $dailyTable = $this->resolveTableName($this->adapter, 'getEventsDailyTableName');
        $fullName = "`{$database}`.`{$dailyTable}`";
        $mvName = "`{$database}`.`{$this->namespacedDailyMv()}`";

        $this->queryRaw($this->adapter, "DROP TABLE IF EXISTS {$mvName}");
        $this->queryRaw($this->adapter, "DROP TABLE IF EXISTS {$fullName}");

        $usage = new Usage($this->adapter);
        $usage->setup();

        $ddl = $this->showCreate($dailyTable);

        $this->assertStringContainsString("`time` DateTime64(3, 'UTC')", $ddl);
        $this->assertStringNotContainsString("`time` DateTime64(3, 'UTC') CODEC", $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String)', $ddl);
        $this->assertStringNotContainsString('`resourceId` Nullable(String) CODEC', $ddl);
    }

    private function namespacedDailyMv(): string
    {
        return $this->resolveTableName($this->adapter, 'getTableName') . '_events_daily_mv';
    }

    public function testGaugesTableCarriesServiceAndResource(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getGaugesTableName'));

        $this->assertStringContainsString('`service` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString('`resource` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString("`time` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)", $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`teamId` Nullable(String) CODEC(ZSTD(3))', $ddl);
    }

    public function testGaugesTableSwapsBloomForSetOnLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getGaugesTableName'));

        $this->assertStringContainsString('`index-service` service TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-resource` resource TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-resourceId` resourceId TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-teamId` teamId TYPE bloom_filter', $ddl);
    }

    public function testSetupBackfillsServiceResourceOnLegacyGaugesTable(): void
    {
        $legacyAdapter = $this->makeAdapter('utopia_usage_schema_legacy_gauge');
        $database = $this->databaseName($legacyAdapter);
        $gaugesTable = $this->resolveTableName($legacyAdapter, 'getGaugesTableName');
        $fullName = "`{$database}`.`{$gaugesTable}`";

        $this->queryRaw($legacyAdapter, "DROP TABLE IF EXISTS {$fullName}");
        $this->queryRaw($legacyAdapter, "
            CREATE TABLE {$fullName} (
                id String,
                metric String,
                value Int64,
                time DateTime64(3),
                tenant Nullable(String)
            )
            ENGINE = MergeTree()
            ORDER BY (tenant, metric, time, id)
            PARTITION BY toYYYYMM(time)
            SETTINGS allow_nullable_key = 1
        ");

        $usage = new Usage($legacyAdapter);
        $usage->setup();

        $rawString = $this->queryRaw($legacyAdapter, "SHOW CREATE TABLE {$fullName} FORMAT JSON");
        $json = json_decode($rawString, true);
        $ddl = '';
        if (is_array($json) && isset($json['data'][0]['statement']) && is_string($json['data'][0]['statement'])) {
            $ddl = $json['data'][0]['statement'];
        }

        $this->assertStringContainsString('`service` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString('`resource` LowCardinality(Nullable(String))', $ddl);
    }

    private function showCreate(string $table): string
    {
        $database = $this->databaseName($this->adapter);
        $raw = $this->queryRaw($this->adapter, "SHOW CREATE TABLE `{$database}`.`{$table}` FORMAT JSON");
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            $statement = $json['data'][0]['statement'] ?? '';
            if (is_string($statement)) {
                return $statement;
            }
        }
        return '';
    }
}
