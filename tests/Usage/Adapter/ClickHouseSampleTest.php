<?php

namespace Utopia\Tests\Usage\Adapter;

use DateTimeImmutable;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Sample;
use Utopia\Usage\SampleRange;
use Utopia\Usage\Usage;

final class ClickHouseSampleTest extends ClickHouseTestCase
{
    private Usage $usage;

    protected function setUp(): void
    {
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_samples',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );

        $this->usage = new Usage($adapter);
        $this->usage->setup();
    }

    public function testSampleTableHasCanonicalIdentityAndWatermarkColumns(): void
    {
        $adapter = $this->usage->getAdapter();
        $this->assertInstanceOf(ClickHouseAdapter::class, $adapter);

        $table = $this->resolveTableName($adapter, 'getSamplesTableName');
        $database = $this->databaseName($adapter);
        $ddl = $this->queryRaw($adapter, "SHOW CREATE TABLE `{$database}`.`{$table}` FORMAT TabSeparatedRaw");

        foreach ([
            '`environment` LowCardinality(String)',
            '`region` LowCardinality(String)',
            '`projectInternalId` String',
            '`databaseInternalId` String',
            '`member` String',
            '`generation` String',
            '`sequence` UInt64',
            '`metric` LowCardinality(String)',
            "`intervalStart` DateTime64(3, 'UTC')",
            "`intervalEnd` DateTime64(3, 'UTC')",
            '`value` Int64',
            '`eventVersion` UInt32',
            "`ingestedAt` DateTime64(3, 'UTC') DEFAULT now64(3)",
        ] as $column) {
            $this->assertStringContainsString($column, $ddl);
        }
    }

    public function testCanonicalizesConcurrentDuplicatesAndCrashRetry(): void
    {
        $key = bin2hex(random_bytes(8));
        $sample = $this->sample($key, sequence: 0);

        $this->assertTrue($this->usage->addSamples([$sample, $sample]));
        $this->assertTrue($this->usage->addSamples([$sample]));

        $result = $this->usage->findSamples(
            $this->range($key, firstSequence: 0, lastSequence: 0),
            $this->usage->getSampleWatermark(),
            10,
        );

        $this->assertTrue($result->isComplete());
        $this->assertCount(1, $result->getSamples());
        $this->assertSame(2, $result->getDuplicateCount());
        $this->assertSame([], $result->getConflicts());
        $this->assertSame([], $result->getGaps());
        $this->assertSame([], $result->getDiscontinuities());
    }

    public function testCanonicalSamplesWaitForAsyncInsertDurability(): void
    {
        $key = bin2hex(random_bytes(8));
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_samples_async',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
            asyncInserts: true,
            asyncInsertWait: false,
        );
        $usage = new Usage($adapter);
        $usage->setup();

        $this->assertTrue($usage->addSamples([$this->sample($key, sequence: 0)]));

        $result = $usage->findSamples(
            $this->range($key, firstSequence: 0, lastSequence: 0),
            $usage->getSampleWatermark(),
            10,
        );

        $this->assertTrue($result->isComplete());
        $this->assertCount(1, $result->getSamples());
    }

    public function testConflictingDuplicateFailsCompleteness(): void
    {
        $key = bin2hex(random_bytes(8));

        $this->assertTrue($this->usage->addSamples([
            $this->sample($key, sequence: 0, value: 10),
            $this->sample($key, sequence: 0, value: 11),
        ]));

        $result = $this->usage->findSamples(
            $this->range($key, firstSequence: 0, lastSequence: 0),
            $this->usage->getSampleWatermark(),
            10,
        );

        $this->assertFalse($result->isComplete());
        $this->assertSame([0], $result->getConflicts());
    }

    public function testDetectsGapsWithoutExpandingEveryMissingSequence(): void
    {
        $key = bin2hex(random_bytes(8));

        $this->assertTrue($this->usage->addSamples([
            $this->sample($key, sequence: 0),
            $this->sample($key, sequence: 4),
        ]));

        $result = $this->usage->findSamples(
            $this->range($key, firstSequence: 0, lastSequence: 4),
            $this->usage->getSampleWatermark(),
            10,
        );

        $this->assertFalse($result->isComplete());
        $this->assertCount(1, $result->getGaps());
        $this->assertSame(1, $result->getGaps()[0]->first);
        $this->assertSame(3, $result->getGaps()[0]->last);
    }

    public function testDetectsIntervalDiscontinuityWithContiguousSequences(): void
    {
        $key = bin2hex(random_bytes(8));

        $this->assertTrue($this->usage->addSamples([
            $this->sample($key, sequence: 0),
            $this->sample($key, sequence: 1, startMinute: 2),
        ]));

        $result = $this->usage->findSamples(
            $this->range($key, firstSequence: 0, lastSequence: 1, endMinute: 3),
            $this->usage->getSampleWatermark(),
            10,
        );

        $this->assertFalse($result->isComplete());
        $this->assertSame([], $result->getGaps());
        $this->assertSame([1], $result->getDiscontinuities());
    }

    public function testReportsTruncationAndHonorsAStableWatermark(): void
    {
        $key = bin2hex(random_bytes(8));

        $this->assertTrue($this->usage->addSamples([
            $this->sample($key, sequence: 0),
            $this->sample($key, sequence: 1),
            $this->sample($key, sequence: 2),
        ]));

        $watermark = $this->usage->getSampleWatermark();
        usleep(10_000);
        $this->assertTrue($this->usage->addSamples([$this->sample($key, sequence: 3)]));

        $bounded = $this->usage->findSamples(
            $this->range($key, firstSequence: 0, lastSequence: 3),
            $watermark,
            2,
        );
        $watermarked = $this->usage->findSamples(
            $this->range($key, firstSequence: 0, lastSequence: 3),
            $watermark,
            10,
        );

        $this->assertTrue($bounded->isTruncated());
        $this->assertCount(2, $bounded->getSamples());
        $this->assertFalse($bounded->isComplete());

        $this->assertFalse($watermarked->isTruncated());
        $this->assertCount(3, $watermarked->getSamples());
        $this->assertSame(3, $watermarked->getGaps()[0]->first);
        $this->assertSame(3, $watermarked->getGaps()[0]->last);
    }

    private function sample(string $key, int $sequence, int $value = 10, ?int $startMinute = null): Sample
    {
        $start = new DateTimeImmutable('2026-08-01T00:00:00Z');
        $startMinute ??= $sequence;

        return new Sample(
            environment: 'test-' . $key,
            region: 'fra1',
            projectInternalId: '101',
            databaseInternalId: '202',
            member: 'mysql-0',
            generation: 'generation-1',
            sequence: $sequence,
            metric: 'bandwidth.inbound',
            intervalStart: $start->modify("+{$startMinute} minutes"),
            intervalEnd: $start->modify('+' . ($startMinute + 1) . ' minutes'),
            value: $value,
            eventVersion: 1,
        );
    }

    private function range(string $key, int $firstSequence, int $lastSequence, ?int $endMinute = null): SampleRange
    {
        $endMinute ??= $lastSequence + 1;

        return new SampleRange(
            environment: 'test-' . $key,
            region: 'fra1',
            projectInternalId: '101',
            databaseInternalId: '202',
            member: 'mysql-0',
            generation: 'generation-1',
            metric: 'bandwidth.inbound',
            firstSequence: $firstSequence,
            lastSequence: $lastSequence,
            intervalStart: new DateTimeImmutable('2026-08-01T00:00:00Z'),
            intervalEnd: new DateTimeImmutable("2026-08-01T00:{$endMinute}:00Z"),
        );
    }
}
