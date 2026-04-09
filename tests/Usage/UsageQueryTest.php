<?php

namespace Utopia\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Query;
use Utopia\Usage\UsageQuery;

class UsageQueryTest extends TestCase
{
    public function testGroupByIntervalCreation(): void
    {
        $query = UsageQuery::groupByInterval('time', '1h');

        $this->assertInstanceOf(UsageQuery::class, $query);
        $this->assertEquals(UsageQuery::TYPE_GROUP_BY_INTERVAL, $query->getMethod());
        $this->assertEquals('time', $query->getAttribute());
        $this->assertEquals(['1h'], $query->getValues());
        $this->assertEquals('1h', $query->getValue());
    }

    public function testGroupByIntervalAllValidIntervals(): void
    {
        $validIntervals = ['1m', '5m', '15m', '1h', '1d', '1w', '1M'];

        foreach ($validIntervals as $interval) {
            $query = UsageQuery::groupByInterval('time', $interval);
            $this->assertEquals($interval, $query->getValue());
        }
    }

    public function testGroupByIntervalInvalidInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid interval '2h'");
        UsageQuery::groupByInterval('time', '2h');
    }

    public function testGroupByIntervalInvalidIntervalEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UsageQuery::groupByInterval('time', '');
    }

    public function testIsGroupByInterval(): void
    {
        $groupByQuery = UsageQuery::groupByInterval('time', '1h');
        $regularQuery = Query::equal('metric', ['bandwidth']);

        $this->assertTrue(UsageQuery::isGroupByInterval($groupByQuery));
        $this->assertFalse(UsageQuery::isGroupByInterval($regularQuery));
    }

    public function testExtractGroupByInterval(): void
    {
        $groupByQuery = UsageQuery::groupByInterval('time', '1h');
        $equalQuery = Query::equal('metric', ['bandwidth']);
        $timeQuery = Query::greaterThanEqual('time', '2026-03-01');

        $queries = [$equalQuery, $groupByQuery, $timeQuery];

        $extracted = UsageQuery::extractGroupByInterval($queries);

        $this->assertNotNull($extracted);
        $this->assertInstanceOf(UsageQuery::class, $extracted);
        $this->assertEquals('1h', $extracted->getValue());
    }

    public function testExtractGroupByIntervalReturnsNullWhenMissing(): void
    {
        $queries = [
            Query::equal('metric', ['bandwidth']),
            Query::greaterThanEqual('time', '2026-03-01'),
        ];

        $this->assertNull(UsageQuery::extractGroupByInterval($queries));
    }

    public function testRemoveGroupByInterval(): void
    {
        $groupByQuery = UsageQuery::groupByInterval('time', '1h');
        $equalQuery = Query::equal('metric', ['bandwidth']);
        $timeQuery = Query::greaterThanEqual('time', '2026-03-01');

        $queries = [$equalQuery, $groupByQuery, $timeQuery];
        $remaining = UsageQuery::removeGroupByInterval($queries);

        $this->assertCount(2, $remaining);

        foreach ($remaining as $query) {
            $this->assertNotEquals(UsageQuery::TYPE_GROUP_BY_INTERVAL, $query->getMethod());
        }
    }

    public function testValidIntervalsConstant(): void
    {
        $this->assertIsArray(UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('5m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('15m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1h', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1d', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1w', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1M', UsageQuery::VALID_INTERVALS);

        // Verify interval SQL values
        $this->assertEquals('INTERVAL 1 HOUR', UsageQuery::VALID_INTERVALS['1h']);
        $this->assertEquals('INTERVAL 1 DAY', UsageQuery::VALID_INTERVALS['1d']);
        $this->assertEquals('INTERVAL 1 MINUTE', UsageQuery::VALID_INTERVALS['1m']);
        $this->assertEquals('INTERVAL 1 MONTH', UsageQuery::VALID_INTERVALS['1M']);
    }

    public function testUsageQueryExtendsQuery(): void
    {
        $query = UsageQuery::groupByInterval('time', '1h');
        $this->assertInstanceOf(Query::class, $query);
    }
}
