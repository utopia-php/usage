<?php

namespace Utopia\Usage;

use ArrayObject;

/**
 * Usage Metric
 *
 * Represents a single usage metric data point containing information about
 * resource usage, performance metrics, or other measurable data points.
 *
 * This class extends ArrayObject to provide both array-like access and
 * type-safe getter methods for common metric attributes.
 *
 * Example:
 * ```php
 * $metric = new Metric([
 *     '$id' => 'unique-id',
 *     'metric' => 'bandwidth',
 *     'value' => 1024,
 *     'type' => 'event',
 *     'time' => '2025-12-09 10:00:00',
 *     'tags' => ['region' => 'us-east', 'project' => 'my-app']
 * ]);
 *
 * echo $metric->getMetric(); // 'bandwidth'
 * echo $metric->getValue();  // 1024
 * ```
 *
 * @extends ArrayObject<string, mixed>
 */
class Metric extends ArrayObject
{
    /**
     * Construct a new metric object.
     *
     * Initializes the metric with the provided data array.
     * The array can contain any attributes, but common ones include:
     * - $id: Unique identifier for the metric
     * - metric: Name/type of the metric being tracked
     * - value: Numeric value of the metric
     * - type: Metric type ('event' or 'gauge')
     * - time: Timestamp when the metric was recorded
     * - tags: Additional metadata as key-value pairs
     * - tenant: Tenant ID for multi-tenant environments
     *
     * @param  array<string, mixed>  $input  Metric data
     */
    public function __construct(array $input = [])
    {
        parent::__construct($input);
    }

    /**
     * Get metric ID.
     *
     * Returns the unique identifier for this metric entry.
     * This is typically a UUID or auto-generated ID from the storage backend.
     *
     * @return string The metric ID, or empty string if not set
     */
    public function getId(): string
    {
        $id = $this->getAttribute('$id', '');
        return is_string($id) ? $id : '';
    }

    /**
     * Get metric name.
     *
     * Returns the name or type of metric being tracked.
     * Examples: 'bandwidth', 'requests', 'storage', 'executions'
     *
     * @return string The metric name, or empty string if not set
     */
    public function getMetric(): string
    {
        $metric = $this->getAttribute('metric', '');
        return is_string($metric) ? $metric : '';
    }

    /**
     * Get metric value.
     *
     * Returns the numeric value associated with this metric.
     * For example, number of requests, bytes transferred, or execution count.
     *
     * @param  int|null  $default  Default value to return if not set
     * @return int|null The metric value, or the default if not set or invalid
     */
    public function getValue(?int $default = null): ?int
    {
        $value = $this->getAttribute('value', $default ?? 0);
        return is_int($value) ? $value : $default;
    }

    /**
     * Get metric type.
     *
     * Returns the type of this metric.
     * Values:
     * - 'event': Additive metrics (bandwidth, requests, etc.) aggregated with SUM
     * - 'gauge': Point-in-time metrics (storage, user count, etc.) aggregated with argMax
     *
     * @return string The type identifier, defaults to 'event'
     */
    public function getType(): string
    {
        $type = $this->getAttribute('type', 'event');
        return is_string($type) ? $type : 'event';
    }

    /**
     * Get timestamp.
     *
     * Returns the timestamp when this metric was recorded or the
     * aggregation period start time. Format depends on the storage backend,
     * typically ISO 8601 or database datetime format.
     *
     * @return string|null The timestamp string, or null if not set
     */
    public function getTime(): ?string
    {
        $time = $this->getAttribute('time', null);
        return is_string($time) ? $time : null;
    }

    /**
     * Get tags.
     *
     * Returns additional metadata associated with this metric as key-value pairs.
     * Tags are useful for filtering, grouping, and contextualizing metrics.
     *
     * Common tag examples:
     * - region: Geographic region (us-east, eu-west)
     * - project: Project or application identifier
     * - environment: dev, staging, production
     * - resource: Specific resource being measured
     *
     * @return array<string, mixed> Associative array of tags
     */
    public function getTags(): array
    {
        $tags = $this->getAttribute('tags', []);
        return is_array($tags) ? $tags : [];
    }

    /**
     * Get tenant ID.
     *
     * Returns the tenant identifier when using shared tables in multi-tenant
     * architectures. This allows data isolation at the application level while
     * sharing the same database tables.
     *
     * @return string|null The tenant ID, or null if not set or not using multi-tenancy
     */
    public function getTenant(): ?string
    {
        $tenant = $this->getAttribute('tenant');

        if ($tenant === null) {
            return null;
        }

        return (string) $tenant;
    }

    /**
     * Get all attributes.
     *
     * Returns all metric data as an associative array.
     * This includes both standard fields (id, metric, value, etc.) and
     * any custom attributes that were set on the metric.
     *
     * @return array<string, mixed> All metric attributes
     */
    public function getAttributes(): array
    {
        $attributes = [];

        foreach ($this as $key => $value) {
            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * Get a specific attribute.
     *
     * Retrieves the value of a named attribute. If the attribute doesn't exist,
     * returns the provided default value.
     *
     * This is a generic accessor - prefer using the type-safe getters
     * (getId(), getMetric(), etc.) for standard attributes.
     *
     * @param  string  $name  The attribute name to retrieve
     * @param  mixed  $default  Default value if attribute is not set
     * @return mixed The attribute value or default
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        if (isset($this[$name])) {
            return $this[$name];
        }

        return $default;
    }

    /**
     * Set a specific attribute.
     *
     * Sets or updates the value of a named attribute.
     * Returns the metric instance for method chaining.
     *
     * Example:
     * ```php
     * $metric->setAttribute('custom', 'value')
     *        ->setAttribute('another', 123);
     * ```
     *
     * @param  string  $key  The attribute name
     * @param  mixed  $value  The attribute value
     * @return static This metric instance for chaining
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this[$key] = $value;

        return $this;
    }

    /**
     * Check if an attribute exists.
     *
     * Determines whether a named attribute is set on this metric,
     * regardless of its value (including null).
     *
     * @param  string  $name  The attribute name to check
     * @return bool True if the attribute exists, false otherwise
     */
    public function hasAttribute(string $name): bool
    {
        return isset($this[$name]);
    }

    /**
     * Remove an attribute.
     *
     * Removes a named attribute from the metric.
     * Returns the metric instance for method chaining.
     *
     * @param  string  $name  The attribute name to remove
     * @return static This metric instance for chaining
     */
    public function removeAttribute(string $name): static
    {
        unset($this[$name]);

        /** @var static */
        return $this;
    }

    /**
     * Check if the metric is empty.
     *
     * A metric is considered empty if it has no ID set.
     * This is useful for checking if a query returned valid results.
     *
     * @return bool True if the metric has no ID, false otherwise
     */
    public function isEmpty(): bool
    {
        return empty($this->getId());
    }

    /**
     * Convert to array.
     *
     * Returns a plain PHP array representation of the metric.
     * This is useful for serialization, JSON encoding, or passing
     * to functions that expect arrays.
     *
     * @return array<string, mixed> Array representation of the metric
     */
    public function toArray(): array
    {
        return $this->getArrayCopy();
    }

    /**
     * Get metric schema definition.
     *
     * Returns the attribute schema that defines the structure of metric data.
     * This is used by adapters to understand the metric structure and create
     * appropriate database tables/collections.
     *
     * Each attribute definition includes:
     * - $id: string (attribute identifier)
     * - type: string (attribute data type: string, integer, datetime)
     * - size: int (max size for strings, 0 for others)
     * - required: bool (whether the attribute is required)
     * - signed: bool (for numeric types)
     * - array: bool (whether value is an array)
     * - filters: array<string> (data filters/validation rules)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getSchema(): array
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
                '$id' => 'type',
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
     * Get metric indexes definition.
     *
     * Returns the index definitions that should be created on the metric table.
     * Indexes are used to optimize query performance for common filter operations.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getIndexes(): array
    {
        return [
            [
                '$id' => 'index-metric',
                'type' => 'key',
                'attributes' => ['metric'],
            ],
            [
                '$id' => 'index-type',
                'type' => 'key',
                'attributes' => ['type'],
            ],
            [
                '$id' => 'index-time',
                'type' => 'key',
                'attributes' => ['time'],
            ],
        ];
    }

    /**
     * Validate metric data against schema.
     *
     * Validates that metric data conforms to the schema definition.
     * Checks for:
     * - Required attributes are present
     * - Data types match schema types
     * - String sizes don't exceed limits
     * - Values are in valid ranges
     *
     * @param  array<string, mixed>  $data  The metric data to validate
     * @throws \Exception If validation fails
     */
    public static function validate(array $data): void
    {
        $schema = self::getSchema();

        foreach ($schema as $attribute) {
            /** @var string $attrId */
            $attrId = $attribute['$id'];
            $required = $attribute['required'] ?? false;
            $type = $attribute['type'] ?? 'string';
            /** @var int $size */
            $size = $attribute['size'] ?? 0;

            // Check if required attribute is present
            if ($required && !isset($data[$attrId])) {
                throw new \Exception("Required attribute '{$attrId}' is missing");
            }

            // Skip validation if not present and not required
            if (!isset($data[$attrId])) {
                continue;
            }

            $value = $data[$attrId];

            // Special handling for tags: accept array (will be JSON-encoded)
            if ($attrId === 'tags') {
                if (!is_array($value)) {
                    throw new \Exception("Attribute '{$attrId}' must be an array, got " . gettype($value));
                }
                continue;
            }

            // Validate based on attribute type
            match ($type) {
                'string' => self::validateStringAttribute($attrId, $value, $size),
                'integer' => self::validateIntegerAttribute($attrId, $value),
                'datetime' => self::validateDatetimeAttribute($attrId, $value),
                default => null,
            };
        }
    }

    /**
     * Validate string attribute value.
     *
     * @throws \Exception
     */
    private static function validateStringAttribute(string $attrId, mixed $value, int $size): void
    {
        if (!is_string($value)) {
            throw new \Exception("Attribute '{$attrId}' must be a string, got " . gettype($value));
        }

        if ($size > 0 && strlen($value) > $size) {
            throw new \Exception("Attribute '{$attrId}' exceeds maximum size of {$size} characters");
        }
    }

    /**
     * Validate integer attribute value.
     *
     * @throws \Exception
     */
    private static function validateIntegerAttribute(string $attrId, mixed $value): void
    {
        if (!is_int($value)) {
            throw new \Exception("Attribute '{$attrId}' must be an integer, got " . gettype($value));
        }
    }

    /**
     * Validate datetime attribute value.
     *
     * @throws \Exception
     */
    private static function validateDatetimeAttribute(string $attrId, mixed $value): void
    {
        if ($value instanceof \DateTime) {
            return; // Valid DateTime object
        }

        if (!is_string($value)) {
            throw new \Exception("Attribute '{$attrId}' must be a DateTime object or string, got " . gettype($value));
        }

        try {
            new \DateTime($value);
        } catch (\Exception $e) {
            throw new \Exception("Attribute '{$attrId}' is not a valid datetime string: {$e->getMessage()}");
        }
    }
}
