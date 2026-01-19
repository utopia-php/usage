<?php

namespace Utopia\Usage\Adapter;

use Utopia\Usage\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * Base SQL Adapter for Audit
 *
 * This is an abstract base class for SQL-based adapters (Database, ClickHouse, etc.)
 * It provides common functionality and schema definitions for all SQL adapters.
 */
abstract class SQL extends Adapter
{
    public const COLLECTION = 'usage';

    /**
     * Get the collection/table name for audit logs.
     *
     * @return string
     */
    public function getCollectionName(): string
    {
        return self::COLLECTION;
    }

    /**
     * Get attribute definitions for audit logs.
     *
     * Each attribute is an array with the following string keys:
     * - $id: string (attribute identifier)
     * - type: string
     * - size: int
     * - required: bool
     * - signed: bool
     * - array: bool
     * - filters: array<string>
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttributes(): array
    {
        return [
            [
                '$id' => 'metric',
                'type' => 'string',
                'size' => 255,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'value',
                'type' => 'integer',
                'size' => 0,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'period',
                'type' => 'string',
                'size' => 16,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'time',
                'type' => 'datetime',
                'format' => '',
                'size' => 0,
                'signed' => true,
                'required' => false,
                'array' => false,
                'filters' => ['datetime'],
            ],
            [
                '$id' => 'tags',
                'type' => 'string',
                'size' => 16777216,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => ['json'],
            ],
        ];
    }

    /**
     * Get attribute documents for audit logs.
     *
     * @return array<Document>
     */
    public function getAttributeDocuments(): array
    {
        return array_map(static fn (array $attribute) => new Document($attribute), $this->getAttributes());
    }

    /**
     * Get index definitions for audit logs.
     *
     * Each index is an array with the following string keys:
     * - $id: string (index identifier)
     * - type: string
     * - attributes: array<string>
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIndexes(): array
    {
        return [
            [
                '$id' => 'index-metric',
                'type' => 'key',
                'attributes' => ['metric'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => 'index-period',
                'type' => 'key',
                'attributes' => ['period'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => 'index-metric-period',
                'type' => 'key',
                'attributes' => ['metric', 'period'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => 'index-time',
                'type' => 'key',
                'attributes' => ['time'],
                'lengths' => [],
                'orders' => ['desc'],
            ],
        ];
    }

    /**
     * Get index documents for audit logs.
     *
     * @return array<Document>
     */
    public function getIndexDocuments(): array
    {
        return array_map(static fn (array $index) => new Document($index), $this->getIndexes());
    }

    /**
     * Get a single attribute by ID.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    protected function getAttribute(string $id)
    {
        foreach ($this->getAttributes() as $attribute) {
            if ($attribute['$id'] === $id) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Get SQL column definition for a given attribute ID.
     * This method is database-specific and must be implemented by each concrete adapter.
     *
     * @param string $id Attribute identifier
     * @return string Database-specific column definition
     */
    abstract protected function getColumnDefinition(string $id): string;

    /**
     * Get all SQL column definitions.
     * Uses the concrete adapter's implementation of getColumnDefinition.
     *
     * @return array<string>
     */
    protected function getAllColumnDefinitions(): array
    {
        $definitions = [];
        foreach ($this->getAttributes() as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];
            $definitions[] = $this->getColumnDefinition($id);
        }

        return $definitions;
    }
}
