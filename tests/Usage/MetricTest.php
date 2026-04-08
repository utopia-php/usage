<?php

namespace Utopia\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Usage\Metric;

class MetricTest extends TestCase
{
    /**
     * Test Metric::getSchema() returns correct attribute definitions
     */
    public function testGetSchemaReturnsAttributeDefinitions(): void
    {
        $schema = Metric::getSchema();

        $this->assertIsArray($schema);
        $this->assertCount(5, $schema);

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

        // Test type attribute
        $typeAttr = $schema[2];
        $this->assertEquals('type', $typeAttr['$id']);
        $this->assertEquals('string', $typeAttr['type']);
        $this->assertEquals(16, $typeAttr['size']);
        $this->assertTrue($typeAttr['required']);

        // Test time attribute (optional)
        $timeAttr = $schema[3];
        $this->assertEquals('time', $timeAttr['$id']);
        $this->assertEquals('datetime', $timeAttr['type']);
        $this->assertFalse($timeAttr['required']);

        // Test tags attribute (optional)
        $tagsAttr = $schema[4];
        $this->assertEquals('tags', $tagsAttr['$id']);
        $this->assertEquals('string', $tagsAttr['type']);
        $this->assertFalse($tagsAttr['required']);
    }

    /**
     * Test Metric::getIndexes() returns correct index definitions
     */
    public function testGetIndexesReturnsIndexDefinitions(): void
    {
        $indexes = Metric::getIndexes();

        $this->assertIsArray($indexes);
        $this->assertCount(3, $indexes);

        // Test metric index
        $metricIndex = $indexes[0];
        $this->assertEquals('index-metric', $metricIndex['$id']);
        $this->assertEquals('key', $metricIndex['type']);
        $this->assertEquals(['metric'], $metricIndex['attributes']);

        // Test type index
        $typeIndex = $indexes[1];
        $this->assertEquals('index-type', $typeIndex['$id']);
        $this->assertEquals(['type'], $typeIndex['attributes']);

        // Test time index
        $timeIndex = $indexes[2];
        $this->assertEquals('index-time', $timeIndex['$id']);
        $this->assertEquals(['time'], $timeIndex['attributes']);
    }

    /**
     * Test Metric::validate() accepts valid data
     */
    public function testValidateAcceptsValidData(): void
    {
        $validData = [
            'metric' => 'requests',
            'value' => 100,
            'type' => 'event',
            'time' => '2024-01-01 12:00:00',
            'tags' => ['region' => 'us-east', 'env' => 'prod'],
        ];

        // Should not throw exception
        Metric::validate($validData);
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
            'type' => 'event',
        ];

        Metric::validate($minimalData);
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
            'type' => 'event',
        ]);
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
            'type' => 'event',
        ]);
    }

    /**
     * Test Metric::validate() rejects missing required type
     */
    public function testValidateRejectsMissingType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Required attribute 'type' is missing");

        Metric::validate([
            'metric' => 'requests',
            'value' => 100,
        ]);
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
            'type' => 'event',
        ]);
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
            'type' => 'event',
        ]);
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
            'type' => 'event',
        ]);
    }

    /**
     * Test Metric::validate() rejects non-string type
     */
    public function testValidateRejectsNonStringType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attribute 'type' must be a string");

        Metric::validate([
            'metric' => 'requests',
            'value' => 100,
            'type' => 123,
        ]);
    }

    /**
     * Test Metric::validate() accepts DateTime object for time
     */
    public function testValidateAcceptsDateTimeForTime(): void
    {
        $data = [
            'metric' => 'requests',
            'value' => 100,
            'type' => 'event',
            'time' => new \DateTime('2024-01-01 12:00:00'),
        ];

        Metric::validate($data);
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
            'type' => 'event',
            'time' => '2024-01-01 12:00:00',
        ];

        Metric::validate($data);
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
            'type' => 'event',
            'time' => 'invalid-date',
        ]);
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
            'type' => 'event',
            'tags' => 'not-an-array',
        ]);
    }

    /**
     * Test Metric::validate() accepts empty tags array
     */
    public function testValidateAcceptsEmptyTags(): void
    {
        $data = [
            'metric' => 'requests',
            'value' => 100,
            'type' => 'event',
            'tags' => [],
        ];

        Metric::validate($data);
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
            'tags' => ['env' => 'prod'],
        ];

        $metric = new Metric($data);

        $this->assertEquals('metric-1', $metric->getId());
        $this->assertEquals('requests', $metric->getMetric());
        $this->assertEquals(100, $metric->getValue());
        $this->assertEquals('event', $metric->getType());
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
}
