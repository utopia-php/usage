<?php

namespace Utopia\Tests\Usage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Utopia\Usage\SampleRange;
use Utopia\Usage\SampleWatermark;

final class SampleWatermarkTest extends TestCase
{
    public function testBindsEvidenceToOneExactRange(): void
    {
        $range = $this->range(lastSequence: 1);
        $watermark = new SampleWatermark(
            $range,
            ['0123456789abcdef0123456789abcdef'],
            false,
        );

        $this->assertTrue($watermark->matches($range));
        $this->assertFalse($watermark->matches($this->range(lastSequence: 2)));
        $this->assertSame(['0123456789abcdef0123456789abcdef'], $watermark->getIngestIds());
        $this->assertFalse($watermark->isTruncated());
    }

    public function testRejectsDuplicateIngestIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ingestIds must be a unique list');

        new SampleWatermark(
            $this->range(lastSequence: 1),
            [
                '0123456789abcdef0123456789abcdef',
                '0123456789abcdef0123456789abcdef',
            ],
            false,
        );
    }

    private function range(int $lastSequence): SampleRange
    {
        return new SampleRange(
            environment: 'production',
            region: 'fra1',
            projectInternalId: '101',
            databaseInternalId: '202',
            member: 'mysql-0',
            generation: 'generation-1',
            metric: 'bandwidth.inbound',
            firstSequence: 0,
            lastSequence: $lastSequence,
            intervalStart: new DateTimeImmutable('2026-08-01T00:00:00Z'),
            intervalEnd: new DateTimeImmutable('2026-08-01T00:03:00Z'),
        );
    }
}
