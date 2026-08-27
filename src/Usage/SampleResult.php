<?php

namespace Utopia\Usage;

use DateTimeImmutable;

final readonly class SampleResult
{
    /**
     * @param list<Sample> $samples
     * @param list<int> $conflicts
     * @param list<SampleGap> $gaps
     * @param list<int> $discontinuities
     */
    public function __construct(
        private array $samples,
        private array $conflicts,
        private array $gaps,
        private array $discontinuities,
        private int $duplicateCount,
        private bool $truncated,
        private DateTimeImmutable $watermark,
    ) {
    }

    /** @return list<Sample> */
    public function getSamples(): array
    {
        return $this->samples;
    }

    /** @return list<int> */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /** @return list<SampleGap> */
    public function getGaps(): array
    {
        return $this->gaps;
    }

    /** @return list<int> */
    public function getDiscontinuities(): array
    {
        return $this->discontinuities;
    }

    public function getDuplicateCount(): int
    {
        return $this->duplicateCount;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function getWatermark(): DateTimeImmutable
    {
        return $this->watermark;
    }

    public function isComplete(): bool
    {
        return !$this->truncated
            && $this->conflicts === []
            && $this->gaps === []
            && $this->discontinuities === [];
    }
}
