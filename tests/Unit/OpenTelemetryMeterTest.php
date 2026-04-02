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

use Couchbase\Exception\MeterException;
use Couchbase\Observability\ObservabilityConstants;
use Couchbase\OpenTelemetry\OpenTelemetryMeter;
use Couchbase\OpenTelemetry\OpenTelemetryValueRecorder;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class OpenTelemetryMeterTest extends TestCase
{
    private MeterProviderInterface&MockObject $meterProvider;
    private MeterInterface&MockObject $meter;
    private HistogramInterface&MockObject $histogram;

    protected function setUp(): void
    {
        $this->meterProvider = $this->createMock(MeterProviderInterface::class);
        $this->meter = $this->createMock(MeterInterface::class);
        $this->histogram = $this->createMock(HistogramInterface::class);
    }

    public function testConstructorSuccessfully(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->with('com.couchbase.client/php')
            ->willReturn($this->meter)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);

        $this->assertInstanceOf(OpenTelemetryMeter::class, $openTelemetryMeter);
    }

    public function testConstructorThrowsMeterExceptionOnFailure(): void
    {
        $originalException = new \RuntimeException('Provider failed');

        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->with('com.couchbase.client/php')
            ->willThrowException($originalException)
        ;

        $this->expectException(MeterException::class);
        $this->expectExceptionMessage('Failed to create OpenTelemetry meter: Provider failed');

        new OpenTelemetryMeter($this->meterProvider);
    }

    public function testValueRecorderCreatesHistogramSuccessfully(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $this->meter
            ->expects($this->once())
            ->method('createHistogram')
            ->with('test.metric', null)
            ->willReturn($this->histogram)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);
        $tags = ['operation' => 'get', 'bucket' => 'default'];

        $valueRecorder = $openTelemetryMeter->valueRecorder('test.metric', $tags);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);
    }

    public function testValueRecorderWithUnitExtractsUnit(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $this->meter
            ->expects($this->once())
            ->method('createHistogram')
            ->with('test.metric', 's')
            ->willReturn($this->histogram)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);
        $tags = [
            'operation' => 'get',
            ObservabilityConstants::ATTR_RESERVED_UNIT => 's',
            'bucket' => 'default',
        ];

        $valueRecorder = $openTelemetryMeter->valueRecorder('test.metric', $tags);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);
    }

    public function testValueRecorderCachesHistograms(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        // createHistogram should only be called once because of caching
        $this->meter
            ->expects($this->once())
            ->method('createHistogram')
            ->with('test.metric', null)
            ->willReturn($this->histogram)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);

        // First call - creates histogram
        $valueRecorder1 = $openTelemetryMeter->valueRecorder('test.metric', ['tag1' => 'value1']);

        // Second call - uses cached histogram
        $valueRecorder2 = $openTelemetryMeter->valueRecorder('test.metric', ['tag2' => 'value2']);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder1);
        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder2);
    }

    public function testValueRecorderCreatesNewHistogramForDifferentMetrics(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $histogram2 = $this->createMock(HistogramInterface::class);

        // Should create two different histograms for different metric names
        $this->meter
            ->expects($this->exactly(2))
            ->method('createHistogram')
            ->willReturnCallback(function ($name, $unit) use ($histogram2) {
                return match ($name) {
                    'metric.one' => $this->histogram,
                    'metric.two' => $histogram2,
                    default => null,
                };
            })
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);

        $valueRecorder1 = $openTelemetryMeter->valueRecorder('metric.one', []);
        $valueRecorder2 = $openTelemetryMeter->valueRecorder('metric.two', []);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder1);
        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder2);
    }

    public function testValueRecorderThrowsMeterExceptionOnHistogramCreationFailure(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $originalException = new \RuntimeException('Histogram creation failed');

        $this->meter
            ->expects($this->once())
            ->method('createHistogram')
            ->willThrowException($originalException)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);

        $this->expectException(MeterException::class);
        $this->expectExceptionMessage('Failed to create OpenTelemetry histogram: Histogram creation failed');

        $openTelemetryMeter->valueRecorder('test.metric', []);
    }

    public function testValueRecorderStripsReservedUnitFromTags(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $this->meter
            ->expects($this->once())
            ->method('createHistogram')
            ->willReturn($this->histogram)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);
        $originalTags = [
            'operation' => 'get',
            ObservabilityConstants::ATTR_RESERVED_UNIT => 's',
            'bucket' => 'default',
        ];

        $valueRecorder = $openTelemetryMeter->valueRecorder('test.metric', $originalTags);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);

        // Verify the original tags array wasn't modified
        $this->assertArrayHasKey(ObservabilityConstants::ATTR_RESERVED_UNIT, $originalTags);
    }

    public function testCloseDoesNothing(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);

        // Should not throw any exceptions
        $openTelemetryMeter->close();
        $this->assertTrue(true); // Assert test passed if no exception
    }

    public function testValueRecorderWithEmptyTags(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $this->meter
            ->expects($this->once())
            ->method('createHistogram')
            ->with('test.metric', null)
            ->willReturn($this->histogram)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);

        $valueRecorder = $openTelemetryMeter->valueRecorder('test.metric', []);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);
    }

    public function testValueRecorderWithMixedValueTypes(): void
    {
        $this->meterProvider
            ->expects($this->once())
            ->method('getMeter')
            ->willReturn($this->meter)
        ;

        $this->meter
            ->expects($this->once())
            ->method('createHistogram')
            ->willReturn($this->histogram)
        ;

        $openTelemetryMeter = new OpenTelemetryMeter($this->meterProvider);
        $tags = [
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array' => ['nested', 'values'],
        ];

        $valueRecorder = $openTelemetryMeter->valueRecorder('test.metric', $tags);

        $this->assertInstanceOf(OpenTelemetryValueRecorder::class, $valueRecorder);
    }
}
