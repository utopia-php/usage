<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Database\DateTime;
use Utopia\Tests\Usage\UsageBase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

class ClickHouseTest extends TestCase
{
    use UsageBase;

    protected function initializeUsage(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);


        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage');
        $adapter->setTenant(1);

        // Optional customization via env vars
        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        $this->usage = new Usage($adapter);
        $this->usage->setup();
    }

    public function testMetricTenantOverridesAdapterTenantInBatch(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_shared');
        $adapter->setSharedTables(true);
        $adapter->setTenant(1);

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        $usage = new Usage($adapter);
        $usage->setup();
        $usage->purge(DateTime::now());

        $metrics = [
            [
                'metric' => 'tenant-override',
                'value' => 5,
                'period' => '1h',
                'tenant' => 2,
                'tags' => [],
            ],
        ];

        $this->assertTrue($usage->logBatch($metrics));

        // Switch adapter scope to the metric tenant to verify the row was stored under the override
        $adapter->setTenant(2);

        $results = $usage->getByPeriod('tenant-override', '1h');

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]->getTenant());

        $usage->purge(DateTime::now());
    }
}
