<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
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


        if ($host === false || $host === '') {
            $this->markTestSkipped('ClickHouse tests disabled (set CLICKHOUSE_ENABLE_TESTS=1 and CLICKHOUSE_HOST to run).');
        }

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);

        // Optional customization via env vars
        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        if ($table = getenv('CLICKHOUSE_TABLE')) {
            $adapter->setTable($table);
        }

        $this->usage = new Usage($adapter);
        $this->usage->setup();
    }
}
