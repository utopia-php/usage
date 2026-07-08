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
        $validIntervals = ['1m', '5m', '15m', '30m', '1h', '1d', '1w', '1M'];

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
        $this->assertInstanceOf(Query::class, $extracted);
        $this->assertEquals(UsageQuery::TYPE_GROUP_BY_INTERVAL, $extracted->getMethod());
        $this->assertEquals('1h', $extracted->getValue());
    }

    public function testExtractGroupByIntervalFromParsedQuery(): void
    {
        // Queries created via Query::parse() are base Query objects, not UsageQuery.
        $parsedGroupBy = new Query(UsageQuery::TYPE_GROUP_BY_INTERVAL, 'time', ['1h']);
        $equalQuery = Query::equal('metric', ['bandwidth']);

        $queries = [$equalQuery, $parsedGroupBy];

        $extracted = UsageQuery::extractGroupByInterval($queries);

        $this->assertNotNull($extracted);
        $this->assertEquals(UsageQuery::TYPE_GROUP_BY_INTERVAL, $extracted->getMethod());
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
        $this->assertArrayHasKey('1m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('5m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('15m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('30m', UsageQuery::VALID_INTERVALS);
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

    public function testGroupByCreation(): void
    {
        $query = UsageQuery::groupBy('service');

        $this->assertInstanceOf(UsageQuery::class, $query);
        $this->assertEquals(UsageQuery::TYPE_GROUP_BY, $query->getMethod());
        $this->assertEquals('service', $query->getAttribute());
        $this->assertEquals([], $query->getValues());
    }

    public function testGroupByIsMethod(): void
    {
        $this->assertTrue(UsageQuery::isMethod(UsageQuery::TYPE_GROUP_BY));
        $this->assertTrue(UsageQuery::isMethod(UsageQuery::TYPE_GROUP_BY_INTERVAL));
        $this->assertTrue(UsageQuery::isMethod(Query::TYPE_EQUAL));
        $this->assertFalse(UsageQuery::isMethod('notARealMethod'));
    }

    public function testIsGroupBy(): void
    {
        $groupBy = UsageQuery::groupBy('service');
        $groupByInterval = UsageQuery::groupByInterval('time', '1h');
        $regular = Query::equal('metric', ['bandwidth']);

        $this->assertTrue(UsageQuery::isGroupBy($groupBy));
        $this->assertFalse(UsageQuery::isGroupBy($groupByInterval));
        $this->assertFalse(UsageQuery::isGroupBy($regular));
    }

    public function testExtractGroupByReturnsAllMatches(): void
    {
        $byService = UsageQuery::groupBy('service');
        $byPath = UsageQuery::groupBy('path');
        $interval = UsageQuery::groupByInterval('time', '1h');
        $equal = Query::equal('metric', ['bandwidth']);

        $queries = [$equal, $byService, $interval, $byPath];

        $extracted = UsageQuery::extractGroupBy($queries);

        $this->assertCount(2, $extracted);
        $this->assertEquals('service', $extracted[0]->getAttribute());
        $this->assertEquals('path', $extracted[1]->getAttribute());
    }

    public function testExtractGroupByReturnsEmptyWhenAbsent(): void
    {
        $queries = [
            Query::equal('metric', ['bandwidth']),
            UsageQuery::groupByInterval('time', '1h'),
        ];

        $this->assertSame([], UsageQuery::extractGroupBy($queries));
    }

    public function testRemoveGroupBy(): void
    {
        $byService = UsageQuery::groupBy('service');
        $byPath = UsageQuery::groupBy('path');
        $interval = UsageQuery::groupByInterval('time', '1h');
        $equal = Query::equal('metric', ['bandwidth']);

        $queries = [$equal, $byService, $interval, $byPath];

        $remaining = UsageQuery::removeGroupBy($queries);

        $this->assertCount(2, $remaining);
        foreach ($remaining as $query) {
            $this->assertNotEquals(UsageQuery::TYPE_GROUP_BY, $query->getMethod());
        }
    }

    public function testGroupByParseRoundTrip(): void
    {
        $json = json_encode([
            'method' => UsageQuery::TYPE_GROUP_BY,
            'attribute' => 'service',
            'values' => [],
        ]);
        $this->assertIsString($json);

        $parsed = UsageQuery::parse($json);

        $this->assertEquals(UsageQuery::TYPE_GROUP_BY, $parsed->getMethod());
        $this->assertEquals('service', $parsed->getAttribute());
        $this->assertEquals([], $parsed->getValues());
    }

    public function testExtractGroupByFromParsedQuery(): void
    {
        // Queries created via Query::parse() are base Query objects, not UsageQuery.
        $parsedGroupBy = new Query(UsageQuery::TYPE_GROUP_BY, 'service', []);
        $equal = Query::equal('metric', ['bandwidth']);

        $extracted = UsageQuery::extractGroupBy([$equal, $parsedGroupBy]);

        $this->assertCount(1, $extracted);
        $this->assertEquals('service', $extracted[0]->getAttribute());
    }

    public function testAggregateCreation(): void
    {
        $query = UsageQuery::aggregate('peak');

        $this->assertInstanceOf(UsageQuery::class, $query);
        $this->assertEquals(UsageQuery::TYPE_AGGREGATE, $query->getMethod());
        $this->assertEquals(['peak'], $query->getValues());
        $this->assertEquals('peak', $query->getValue());
    }

    public function testAggregateAcceptsAllValidFunctions(): void
    {
        foreach (UsageQuery::VALID_AGGREGATES as $function) {
            $query = UsageQuery::aggregate($function);
            $this->assertEquals($function, $query->getValue());
        }
    }

    public function testAggregateRejectsInvalidFunction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid aggregate 'max'");
        UsageQuery::aggregate('max');
    }

    public function testAggregateIsMethod(): void
    {
        $this->assertTrue(UsageQuery::isMethod(UsageQuery::TYPE_AGGREGATE));
    }

    public function testIsAggregate(): void
    {
        $aggregate = UsageQuery::aggregate('peak');
        $regular = Query::equal('metric', ['bandwidth']);

        $this->assertTrue(UsageQuery::isAggregate($aggregate));
        $this->assertFalse(UsageQuery::isAggregate($regular));
    }

    public function testExtractAggregate(): void
    {
        $queries = [
            Query::equal('metric', ['realtime.connections']),
            UsageQuery::aggregate('peak'),
        ];

        $this->assertEquals('peak', UsageQuery::extractAggregate($queries));
    }

    public function testExtractAggregateFromParsedQuery(): void
    {
        // Queries created via Query::parse() are base Query objects, not UsageQuery.
        $parsedAggregate = new Query(UsageQuery::TYPE_AGGREGATE, 'value', ['peak']);
        $equal = Query::equal('metric', ['realtime.connections']);

        $this->assertEquals('peak', UsageQuery::extractAggregate([$equal, $parsedAggregate]));
    }

    public function testExtractAggregateReturnsNullWhenMissing(): void
    {
        $queries = [
            Query::equal('metric', ['realtime.connections']),
            UsageQuery::groupByInterval('time', '1h'),
        ];

        $this->assertNull(UsageQuery::extractAggregate($queries));
    }

    public function testRemoveAggregate(): void
    {
        $queries = [
            Query::equal('metric', ['realtime.connections']),
            UsageQuery::aggregate('peak'),
            UsageQuery::groupByInterval('time', '1h'),
        ];

        $remaining = UsageQuery::removeAggregate($queries);

        $this->assertCount(2, $remaining);
        foreach ($remaining as $query) {
            $this->assertNotEquals(UsageQuery::TYPE_AGGREGATE, $query->getMethod());
        }
    }

    public function testValidAggregatesConstant(): void
    {
        $this->assertContains('sum', UsageQuery::VALID_AGGREGATES);
        $this->assertContains('peak', UsageQuery::VALID_AGGREGATES);
    }
}
