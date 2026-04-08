<?php

namespace Utopia\Usage\Adapter;

use Utopia\Usage\Adapter;
use Utopia\Usage\Metric;
use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * Base SQL Adapter for Audit
 *
 * This is an abstract base class for SQL-based adapters (Database, ClickHouse, etc.)
 * It provides common functionality and references schema definitions from the Metric class.
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
     * Delegates to Metric class which defines the metric schema.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttributes(): array
    {
        return Metric::getSchema();
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
     * Delegates to Metric class which defines the metric indexes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIndexes(): array
    {
        return Metric::getIndexes();
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

    /**
     * Generate a UUID for row identification.
     * Since we're appending raw rows (no dedup), IDs are random.
     */
    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
