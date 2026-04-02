<?php

/**
 * Copyright 2026-Present Couchbase, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace Couchbase\OpenTelemetry\Tests\Unit;

use Couchbase\Observability\ObservabilityConstants;
use Couchbase\OpenTelemetry\OpenTelemetryValueRecorder;
use OpenTelemetry\API\Metrics\HistogramInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class OpenTelemetryValueRecorderTest extends TestCase
{
    private HistogramInterface&MockObject $histogram;

    protected function setUp(): void
    {
        $this->histogram = $this->createMock(HistogramInterface::class);
    }

    public function testConstructorWithBasicAttributes(): void
    {
        $attributes = ['operation' => 'get', 'bucket' => 'default'];

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $attributes);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);
    }

    public function testConstructorWithUnitParameter(): void
    {
        $attributes = ['operation' => 'get'];
        $unit = 's';

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $attributes, $unit);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);
    }

    public function testConstructorWithEmptyAttributes(): void
    {
        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, []);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);
    }

    public function testRecordValueWithoutUnitConversion(): void
    {
        $attributes = ['operation' => 'get', 'bucket' => 'default'];
        $value = 1500;

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($value, $attributes)
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $attributes);
        $valueRecorder->recordValue($value);
    }

    public function testRecordValueWithSecondsUnitConversion(): void
    {
        $attributes = ['operation' => 'get', 'bucket' => 'default'];
        $originalValueMicroseconds = 1_500_000; // 1.5 seconds in microseconds
        $expectedValueSeconds = 1.5; // Expected converted value in seconds

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($expectedValueSeconds, $attributes)
        ;

        $valueRecorder = new OpenTelemetryValueRecorder(
            $this->histogram,
            $attributes,
            ObservabilityConstants::ATTR_VALUE_RESERVED_UNIT_SECONDS
        );
        $valueRecorder->recordValue($originalValueMicroseconds);
    }

    public function testRecordValueWithNullUnit(): void
    {
        $attributes = ['operation' => 'get'];
        $value = 1000;

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($value, $attributes)
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $attributes, null);
        $valueRecorder->recordValue($value);
    }

    public function testRecordValueWithUnknownUnit(): void
    {
        $attributes = ['operation' => 'get'];
        $value = 1000;

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($value, $attributes) // Should not convert unknown units
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $attributes, 'ms');
        $valueRecorder->recordValue($value);
    }

    public function testRecordValueMultipleTimes(): void
    {
        $attributes = ['operation' => 'get'];
        $values = [100, 200, 300];

        $this->histogram
            ->expects($this->exactly(3))
            ->method('record')
            ->willReturnCallback(function ($value, $attr) use ($attributes) {
                static $callCount = 0;
                ++$callCount;
                $expectedValue = match ($callCount) {
                    1 => 100,
                    2 => 200,
                    3 => 300,
                    default => null,
                };
                $this->assertEquals($expectedValue, $value);
                $this->assertEquals($attributes, $attr);
            })
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $attributes);

        foreach ($values as $value) {
            $valueRecorder->recordValue($value);
        }
    }

    public function testRecordValueWithComplexAttributes(): void
    {
        $attributes = [
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array' => ['nested', 'values'],
        ];
        $value = 1000;

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($value, $attributes)
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $attributes);
        $valueRecorder->recordValue($value);
    }

    public function testSecondsConversionPrecision(): void
    {
        $testCases = [
            1_000_000 => 1.0,        // 1 second
            500_000 => 0.5,          // 0.5 seconds
            100_000 => 0.1,          // 0.1 seconds
            1_000 => 0.001,          // 1 millisecond
            1 => 0.000001,           // 1 microsecond
            0 => 0.0,                // 0 microseconds
        ];

        foreach ($testCases as $microseconds => $expectedSeconds) {
            $histogram = $this->createMock(HistogramInterface::class);
            $histogram
                ->expects($this->once())
                ->method('record')
                ->with($expectedSeconds, [])
            ;

            $valueRecorder = new OpenTelemetryValueRecorder(
                $histogram,
                [],
                ObservabilityConstants::ATTR_VALUE_RESERVED_UNIT_SECONDS
            );
            $valueRecorder->recordValue($microseconds);
        }
    }

    public function testRecordValueWithZero(): void
    {
        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with(0, [])
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, []);
        $valueRecorder->recordValue(0);
    }

    public function testRecordValueWithNegativeValue(): void
    {
        $value = -100;

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($value, [])
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, []);
        $valueRecorder->recordValue($value);
    }

    public function testRecordValueWithLargeValue(): void
    {
        $value = PHP_INT_MAX;

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($value, [])
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, []);
        $valueRecorder->recordValue($value);
    }

    public function testRecordValueSecondsConversionWithLargeValue(): void
    {
        $largeMicroseconds = 1_000_000_000_000; // 1 million seconds in microseconds
        $expectedSeconds = 1_000_000.0; // Expected converted value

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with($expectedSeconds, [])
        ;

        $valueRecorder = new OpenTelemetryValueRecorder(
            $this->histogram,
            [],
            ObservabilityConstants::ATTR_VALUE_RESERVED_UNIT_SECONDS
        );
        $valueRecorder->recordValue($largeMicroseconds);
    }

    public function testAttributesNotModifiedDuringRecording(): void
    {
        $originalAttributes = ['operation' => 'get', 'bucket' => 'default'];
        $attributesCopy = $originalAttributes; // Make a copy to verify it doesn't change

        $this->histogram
            ->expects($this->once())
            ->method('record')
            ->with(1000, $originalAttributes)
        ;

        $valueRecorder = new OpenTelemetryValueRecorder($this->histogram, $originalAttributes);
        $valueRecorder->recordValue(1000);

        // Verify original attributes weren't modified
        $this->assertEquals($attributesCopy, $originalAttributes);
    }
}
