<?php

namespace Utopia\Usage;

use InvalidArgumentException;

/**
 * Exact bounded membership captured by one adapter snapshot query.
 *
 * IDs are generated inside addSamples() before transport, so a repeated HTTP
 * request retains them while any later logical insert receives different IDs.
 */
final readonly class SampleWatermark
{
    /**
     * @param list<string> $ingestIds
     */
    public function __construct(
        private SampleRange $range,
        private array $ingestIds,
        private bool $truncated,
    ) {
        if ($ingestIds !== array_values(array_unique($ingestIds))) {
            throw new InvalidArgumentException('ingestIds must be a unique list');
        }

        foreach ($ingestIds as $ingestId) {
            if (preg_match('/^[a-f0-9]{32}$/', $ingestId) !== 1) {
                throw new InvalidArgumentException('ingestIds must contain lowercase 128-bit hexadecimal IDs');
            }
        }
    }

    public function matches(SampleRange $range): bool
    {
        return $this->range->environment === $range->environment
            && $this->range->region === $range->region
            && $this->range->projectInternalId === $range->projectInternalId
            && $this->range->databaseInternalId === $range->databaseInternalId
            && $this->range->member === $range->member
            && $this->range->generation === $range->generation
            && $this->range->metric === $range->metric
            && $this->range->firstSequence === $range->firstSequence
            && $this->range->lastSequence === $range->lastSequence
            && $this->range->getFormattedIntervalStart() === $range->getFormattedIntervalStart()
            && $this->range->getFormattedIntervalEnd() === $range->getFormattedIntervalEnd();
    }

    /** @return list<string> */
    public function getIngestIds(): array
    {
        return $this->ingestIds;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }
}
