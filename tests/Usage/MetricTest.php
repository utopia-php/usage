<?php

namespace Utopia\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Usage\Metric;

class MetricTest extends TestCase
{
    /**
     * Test Metric::getEventSchema() returns correct attribute definitions
     */
    public function testGetEventSchemaReturnsAttributeDefinitions(): void
    {
        $schema = Metric::getEventSchema();

        $this->assertIsArray($schema);
        $this->assertCount(9, $schema);

        // Test metric attribute
        $metricAttr = $schema[0];
        $this->assertEquals('metric', $metricAttr['$id']);
        $this->assertEquals('string', $metricAttr['type']);
        $this->assertEquals(255, $metricAttr['size']);
        $this->assertTrue($metricAttr['required']);

        // Test value attribute
        $valueAttr = $schema[1];
        $this->assertEquals('value', $valueAttr['$id']);
        $this->assertEquals('integer', $valueAttr['type']);
        $this->assertTrue($valueAttr['required']);

        // Test time attribute (optional)
        $timeAttr = $schema[2];
        $this->assertEquals('time', $timeAttr['$id']);
        $this->assertEquals('datetime', $timeAttr['type']);
        $this->assertFalse($timeAttr['required']);

        // Test event-specific columns
        $pathAttr = $schema[3];
        $this->assertEquals('path', $pathAttr['$id']);
        $this->assertFalse($pathAttr['required']);

        $methodAttr = $schema[4];
        $this->assertEquals('method', $methodAttr['$id']);
        $this->assertFalse($methodAttr['required']);

        $statusAttr = $schema[5];
        $this->assertEquals('status', $statusAttr['$id']);
        $this->assertFalse($statusAttr['required']);

        $resourceAttr = $schema[6];
        $this->assertEquals('resource', $resourceAttr['$id']);
        $this->assertFalse($resourceAttr['required']);

        $resourceIdAttr = $schema[7];
        $this->assertEquals('resourceId', $resourceIdAttr['$id']);
        $this->assertFalse($resourceIdAttr['required']);

        // Test tags attribute (optional)
        $tagsAttr = $schema[8];
        $this->assertEquals('tags', $tagsAttr['$id']);
        $this->assertEquals('string', $tagsAttr['type']);
        $this->assertFalse($tagsAttr['required']);
    }

    /**
     * Test Metric::getGaugeSchema() returns correct attribute definitions
     */
    public function testGetGaugeSchemaReturnsAttributeDefinitions(): void
    {
        $schema = Metric::getGaugeSchema();

        $this->assertIsArray($schema);
        $this->assertCount(4, $schema);

        $this->assertEquals('metric', $schema[0]['$id']);
        $this->assertEquals('value', $schema[1]['$id']);
        $this->assertEquals('time', $schema[2]['$id']);
        $this->assertEquals('tags', $schema[3]['$id']);
    }

    /**
     * Test backward-compatible getSchema() returns event schema
     */
    public function testGetSchemaReturnsEventSchema(): void
    {
        $schema = Metric::getSchema();
        $eventSchema = Metric::getEventSchema();
        $this->assertEquals($eventSchema, $schema);
    }

    /**
     * Test Metric::getEventIndexes() returns correct index definitions
     */
    public function testGetEventIndexesReturnsIndexDefinitions(): void
    {
        $indexes = Metric::getEventIndexes();

        $this->assertIsArray($indexes);
        $this->assertCount(7, $indexes);

        // Test metric index
        $metricIndex = $indexes[0];
        $this->assertEquals('index-metric', $metricIndex['$id']);
        $this->assertEquals('key', $metricIndex['type']);
        $this->assertEquals(['metric'], $metricIndex['attributes']);

        // Test time index
        $timeIndex = $indexes[1];
        $this->assertEquals('index-time', $timeIndex['$id']);
        $this->assertEquals(['time'], $timeIndex['attributes']);

        // Test event-specific indexes
        $this->assertEquals('index-path', $indexes[2]['$id']);
        $this->assertEquals('index-method', $indexes[3]['$id']);
        $this->assertEquals('index-status', $indexes[4]['$id']);
        $this->assertEquals('index-resource', $indexes[5]['$id']);
        $this->assertEquals('index-resourceId', $indexes[6]['$id']);
    }

    /**
     * Test Metric::getGaugeIndexes() returns correct index definitions
     */
    public function testGetGaugeIndexesReturnsIndexDefinitions(): void
    {
        $indexes = Metric::getGaugeIndexes();

        $this->assertIsArray($indexes);
        $this->assertCount(2, $indexes);

        $this->assertEquals('index-metric', $indexes[0]['$id']);
        $this->assertEquals('index-time', $indexes[1]['$id']);
    }

    /**
     * Test backward-compatible getIndexes() returns event indexes
     */
    public function testGetIndexesReturnsEventIndexes(): void
    {
        $indexes = Metric::getIndexes();
        $eventIndexes = Metric::getEventIndexes();
        $this->assertEquals($eventIndexes, $indexes);
    }

    /**
     * Test Metric::validate() accepts valid event data
     */
    public function testValidateAcceptsValidEventData(): void
    {
        $validData = [
            'metric' => 'requests',
            'value' => 100,
            'time' => '2024-01-01 12:00:00',
            'path' => '/v1/storage/files',
            'method' => 'POST',
            'status' => '201',
            'resource' => 'bucket',
            'resourceId' => 'abc123',
            'tags' => ['region' => 'us-east', 'env' => 'prod'],
        ];

        // Should not throw exception
        Metric::validate($validData, 'event');
        $this->assertTrue(true);
    }

    /**
     * Test Metric::validate() accepts valid gauge data
     */
    public function testValidateAcceptsValidGaugeData(): void
    {
        $validData = [
            'metric' => 'storage',
            'value' => 10000,
            'time' => '2024-01-01 12:00:00',
            'tags' => ['region' => 'us-east'],
        ];

        Metric::validate($validData, 'gauge');
        $this->assertTrue(true);
    }

    /**
     * Test Metric::validate() accepts minimal required data
     */
    public function testValidateAcceptsMinimalData(): void
    {
        $minimalData = [
            'metric' => 'requests',
            'value' => 50,
        ];

        Metric::validate($minimalData, 'event');
        $this->assertTrue(true);
    }

    /**
     * Test Metric::validate() rejects missing required metric
     */
    public function testValidateRejectsMissingMetric(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Required attribute 'metric' is missing");

        Metric::validate([
            'value' => 100,
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects missing required value
     */
    public function testValidateRejectsMissingValue(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Required attribute 'value' is missing");

        Metric::validate([
            'metric' => 'requests',
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects non-string metric
     */
    public function testValidateRejectsNonStringMetric(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attribute 'metric' must be a string");

        Metric::validate([
            'metric' => 123,
            'value' => 100,
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects oversized metric string
     */
    public function testValidateRejectsOversizedMetric(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("exceeds maximum size of 255 characters");

        Metric::validate([
            'metric' => str_repeat('a', 256),
            'value' => 100,
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects non-integer value
     */
    public function testValidateRejectsNonIntegerValue(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attribute 'value' must be an integer");

        Metric::validate([
            'metric' => 'requests',
            'value' => '100',
        ], 'event');
    }

    /**
     * Test Metric::validate() accepts DateTime object for time
     */
    public function testValidateAcceptsDateTimeForTime(): void
    {
        $data = [
            'metric' => 'requests',
            'value' => 100,
            'time' => new \DateTime('2024-01-01 12:00:00'),
        ];

        Metric::validate($data, 'event');
        $this->assertTrue(true);
    }

    /**
     * Test Metric::validate() accepts datetime string for time
     */
    public function testValidateAcceptsDatetimeStringForTime(): void
    {
        $data = [
            'metric' => 'requests',
            'value' => 100,
            'time' => '2024-01-01 12:00:00',
        ];

        Metric::validate($data, 'event');
        $this->assertTrue(true);
    }

    /**
     * Test Metric::validate() rejects invalid datetime string
     */
    public function testValidateRejectsInvalidDatetimeString(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("not a valid datetime string");

        Metric::validate([
            'metric' => 'requests',
            'value' => 100,
            'time' => 'invalid-date',
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects non-array tags
     */
    public function testValidateRejectsNonArrayTags(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attribute 'tags' must be an array");

        Metric::validate([
            'metric' => 'requests',
            'value' => 100,
            'tags' => 'not-an-array',
        ], 'event');
    }

    /**
     * Test Metric::validate() accepts empty tags array
     */
    public function testValidateAcceptsEmptyTags(): void
    {
        $data = [
            'metric' => 'requests',
            'value' => 100,
            'tags' => [],
        ];

        Metric::validate($data, 'event');
        $this->assertTrue(true);
    }

    /**
     * Test Metric constructor initializes with data
     */
    public function testConstructorInitializesWithData(): void
    {
        $data = [
            '$id' => 'metric-1',
            'metric' => 'requests',
            'value' => 100,
            'type' => 'event',
            'path' => '/v1/storage/files',
            'method' => 'POST',
            'status' => '201',
            'resource' => 'bucket',
            'resourceId' => 'abc123',
            'tags' => ['env' => 'prod'],
        ];

        $metric = new Metric($data);

        $this->assertEquals('metric-1', $metric->getId());
        $this->assertEquals('requests', $metric->getMetric());
        $this->assertEquals(100, $metric->getValue());
        $this->assertEquals('event', $metric->getType());
        $this->assertEquals('/v1/storage/files', $metric->getPath());
        $this->assertEquals('POST', $metric->getMethod());
        $this->assertEquals('201', $metric->getStatus());
        $this->assertEquals('bucket', $metric->getResource());
        $this->assertEquals('abc123', $metric->getResourceId());
        $this->assertEquals(['env' => 'prod'], $metric->getTags());
    }

    /**
     * Test Metric::getId() returns metric ID
     */
    public function testGetIdReturnsMetricId(): void
    {
        $metric = new Metric(['$id' => 'metric-123']);
        $this->assertEquals('metric-123', $metric->getId());
    }

    /**
     * Test Metric::getId() returns empty string when ID not set
     */
    public function testGetIdReturnsEmptyStringWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertEquals('', $metric->getId());
    }

    /**
     * Test Metric::getMetric() returns metric name
     */
    public function testGetMetricReturnsMetricName(): void
    {
        $metric = new Metric(['metric' => 'bandwidth']);
        $this->assertEquals('bandwidth', $metric->getMetric());
    }

    /**
     * Test Metric::getValue() returns metric value
     */
    public function testGetValueReturnsValue(): void
    {
        $metric = new Metric(['value' => 1024]);
        $this->assertEquals(1024, $metric->getValue());
    }

    /**
     * Test Metric::getValue() returns default when not set
     */
    public function testGetValueReturnsDefaultWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertEquals(0, $metric->getValue());
    }

    /**
     * Test Metric::getType() returns type
     */
    public function testGetTypeReturnsType(): void
    {
        $metric = new Metric(['type' => 'gauge']);
        $this->assertEquals('gauge', $metric->getType());
    }

    /**
     * Test Metric::getType() returns default type
     */
    public function testGetTypeReturnsDefaultType(): void
    {
        $metric = new Metric([]);
        $this->assertEquals('event', $metric->getType());
    }

    /**
     * Test event-specific getters return null when not set
     */
    public function testEventGettersReturnNullWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertNull($metric->getPath());
        $this->assertNull($metric->getMethod());
        $this->assertNull($metric->getStatus());
        $this->assertNull($metric->getResource());
        $this->assertNull($metric->getResourceId());
    }

    /**
     * Test event-specific getters return correct values
     */
    public function testEventGettersReturnCorrectValues(): void
    {
        $metric = new Metric([
            'path' => '/v1/databases',
            'method' => 'GET',
            'status' => '200',
            'resource' => 'database',
            'resourceId' => 'db123',
        ]);
        $this->assertEquals('/v1/databases', $metric->getPath());
        $this->assertEquals('GET', $metric->getMethod());
        $this->assertEquals('200', $metric->getStatus());
        $this->assertEquals('database', $metric->getResource());
        $this->assertEquals('db123', $metric->getResourceId());
    }

    /**
     * Test Metric::getTime() returns timestamp
     */
    public function testGetTimeReturnsTimestamp(): void
    {
        $time = '2024-01-01 12:00:00';
        $metric = new Metric(['time' => $time]);
        $this->assertEquals($time, $metric->getTime());
    }

    /**
     * Test Metric::getTime() returns null when not set
     */
    public function testGetTimeReturnsNullWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertNull($metric->getTime());
    }

    /**
     * Test Metric::getTags() returns tags
     */
    public function testGetTagsReturnsTags(): void
    {
        $tags = ['region' => 'us-east', 'env' => 'prod'];
        $metric = new Metric(['tags' => $tags]);
        $this->assertEquals($tags, $metric->getTags());
    }

    /**
     * Test Metric::getTags() returns empty array when not set
     */
    public function testGetTagsReturnsEmptyArrayWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertEquals([], $metric->getTags());
    }

    /**
     * Test Metric::getTenant() returns tenant ID as string
     */
    public function testGetTenantReturnsTenantId(): void
    {
        $metric = new Metric(['tenant' => '123']);
        $this->assertEquals('123', $metric->getTenant());
        $this->assertIsString($metric->getTenant());
    }

    /**
     * Test Metric::getTenant() returns null when not set
     */
    public function testGetTenantReturnsNullWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertNull($metric->getTenant());
    }

    /**
     * Test Metric::getTenant() converts numeric tenant to string
     */
    public function testGetTenantConvertsNumericToString(): void
    {
        $metric = new Metric(['tenant' => 456]);
        $this->assertEquals('456', $metric->getTenant());
        $this->assertIsString($metric->getTenant());
    }

    /**
     * Test Metric::getAttributes() returns all attributes
     */
    public function testGetAttributesReturnsAllAttributes(): void
    {
        $data = [
            '$id' => 'metric-1',
            'metric' => 'requests',
            'value' => 100,
        ];

        $metric = new Metric($data);
        $attributes = $metric->getAttributes();

        $this->assertIsArray($attributes);
        $this->assertEquals('metric-1', $attributes['$id']);
        $this->assertEquals('requests', $attributes['metric']);
        $this->assertEquals(100, $attributes['value']);
    }

    /**
     * Test Metric::getAttribute() returns attribute value
     */
    public function testGetAttributeReturnsValue(): void
    {
        $metric = new Metric(['custom' => 'custom-value']);
        $this->assertEquals('custom-value', $metric->getAttribute('custom'));
    }

    /**
     * Test Metric::getAttribute() returns default when not set
     */
    public function testGetAttributeReturnsDefaultWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertEquals('default', $metric->getAttribute('missing', 'default'));
    }

    /**
     * Test Metric::setAttribute() sets attribute and returns self
     */
    public function testSetAttributeSetsAndReturnsSelf(): void
    {
        $metric = new Metric([]);
        $result = $metric->setAttribute('custom', 'value');

        $this->assertSame($metric, $result);
        $this->assertEquals('value', $metric->getAttribute('custom'));
    }

    /**
     * Test Metric::setAttribute() supports method chaining
     */
    public function testSetAttributeSupportsChaining(): void
    {
        $metric = (new Metric([]))
            ->setAttribute('attr1', 'value1')
            ->setAttribute('attr2', 'value2');

        $this->assertEquals('value1', $metric->getAttribute('attr1'));
        $this->assertEquals('value2', $metric->getAttribute('attr2'));
    }

    /**
     * Test Metric::hasAttribute() returns true when attribute exists
     */
    public function testHasAttributeReturnsTrueWhenExists(): void
    {
        $metric = new Metric(['key' => 'value']);
        $this->assertTrue($metric->hasAttribute('key'));
    }

    /**
     * Test Metric::hasAttribute() returns false when attribute doesn't exist
     */
    public function testHasAttributeReturnsFalseWhenNotExists(): void
    {
        $metric = new Metric([]);
        $this->assertFalse($metric->hasAttribute('missing'));
    }

    /**
     * Test Metric::removeAttribute() removes attribute and returns self
     */
    public function testRemoveAttributeRemovesAndReturnsSelf(): void
    {
        $metric = new Metric(['key' => 'value']);
        $result = $metric->removeAttribute('key');

        $this->assertSame($metric, $result);
        $this->assertFalse($metric->hasAttribute('key'));
    }

    /**
     * Test Metric::isEmpty() returns false when ID is set
     */
    public function testIsEmptyReturnsFalseWhenIdSet(): void
    {
        $metric = new Metric(['$id' => 'metric-1']);
        $this->assertFalse($metric->isEmpty());
    }

    /**
     * Test Metric::isEmpty() returns true when ID is not set
     */
    public function testIsEmptyReturnsTrueWhenNoId(): void
    {
        $metric = new Metric([]);
        $this->assertTrue($metric->isEmpty());
    }

    /**
     * Test Metric::toArray() returns array representation
     */
    public function testToArrayReturnsArray(): void
    {
        $data = [
            '$id' => 'metric-1',
            'metric' => 'requests',
            'value' => 100,
        ];

        $metric = new Metric($data);
        $array = $metric->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('metric-1', $array['$id']);
        $this->assertEquals('requests', $array['metric']);
        $this->assertEquals(100, $array['value']);
    }

    /**
     * Test EVENT_COLUMNS constant
     */
    public function testEventColumnsConstant(): void
    {
        $expected = ['path', 'method', 'status', 'resource', 'resourceId'];
        $this->assertEquals($expected, Metric::EVENT_COLUMNS);
    }
}
