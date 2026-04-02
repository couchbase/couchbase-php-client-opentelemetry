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

use Couchbase\Exception\TracerException;
use Couchbase\OpenTelemetry\OpenTelemetryRequestSpan;
use Couchbase\OpenTelemetry\OpenTelemetryRequestTracer;
use Couchbase\RequestSpan;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class OpenTelemetryRequestTracerTest extends TestCase
{
    private MockObject&TracerProviderInterface $tracerProvider;
    private MockObject&TracerInterface $tracer;
    private MockObject&SpanBuilderInterface $spanBuilder;
    private MockObject&SpanInterface $span;
    private ContextInterface&MockObject $context;

    protected function setUp(): void
    {
        $this->tracerProvider = $this->createMock(TracerProviderInterface::class);
        $this->tracer = $this->createMock(TracerInterface::class);
        $this->spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $this->span = $this->createMock(SpanInterface::class);
        $this->context = $this->createMock(ContextInterface::class);
    }

    public function testConstructorSuccessfully(): void
    {
        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->with('com.couchbase.client/php')
            ->willReturn($this->tracer)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);

        $this->assertInstanceOf(OpenTelemetryRequestTracer::class, $requestTracer);
    }

    public function testConstructorThrowsTracerExceptionOnFailure(): void
    {
        $originalException = new \RuntimeException('Provider failed');

        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->with('com.couchbase.client/php')
            ->willThrowException($originalException)
        ;

        $this->expectException(TracerException::class);
        $this->expectExceptionMessage('Failed to create OpenTelemetry tracer: Provider failed');

        new OpenTelemetryRequestTracer($this->tracerProvider);
    }

    public function testRequestSpanCreatesRootSpanWithoutParent(): void
    {
        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('test.operation')
            ->willReturn($this->spanBuilder)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setSpanKind')
            ->with(SpanKind::KIND_CLIENT)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setParent')
            ->with(false)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($this->span)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);
        $result = $requestTracer->requestSpan('test.operation');

        $this->assertInstanceOf(OpenTelemetryRequestSpan::class, $result);
    }

    public function testRequestSpanCreatesChildSpanWithOpenTelemetryParent(): void
    {
        $parentSpan = $this->createMock(SpanInterface::class);
        $parentRequestSpan = new OpenTelemetryRequestSpan($parentSpan);

        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('child.operation')
            ->willReturn($this->spanBuilder)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setSpanKind')
            ->with(SpanKind::KIND_CLIENT)
            ->willReturnSelf()
        ;

        $parentSpan
            ->expects($this->once())
            ->method('storeInContext')
            ->willReturn($this->context)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setParent')
            ->with($this->context)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($this->span)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);
        $result = $requestTracer->requestSpan('child.operation', $parentRequestSpan);

        $this->assertInstanceOf(OpenTelemetryRequestSpan::class, $result);
    }

    public function testRequestSpanIgnoresNonOpenTelemetryParent(): void
    {
        $nonOtelParent = $this->createMock(RequestSpan::class);

        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('test.operation')
            ->willReturn($this->spanBuilder)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setSpanKind')
            ->with(SpanKind::KIND_CLIENT)
            ->willReturnSelf()
        ;

        // Should set parent to false when parent is not OpenTelemetryRequestSpan
        $this->spanBuilder
            ->expects($this->once())
            ->method('setParent')
            ->with(false)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($this->span)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);
        $result = $requestTracer->requestSpan('test.operation', $nonOtelParent);

        $this->assertInstanceOf(OpenTelemetryRequestSpan::class, $result);
    }

    public function testRequestSpanWithStartTimestamp(): void
    {
        $startTimestamp = 1234567890123456789;

        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->willReturn($this->spanBuilder)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setSpanKind')
            ->with(SpanKind::KIND_CLIENT)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setParent')
            ->with(false)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setStartTimestamp')
            ->with($startTimestamp)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($this->span)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);
        $result = $requestTracer->requestSpan('test.operation', null, $startTimestamp);

        $this->assertInstanceOf(OpenTelemetryRequestSpan::class, $result);
    }

    public function testRequestSpanWithParentAndStartTimestamp(): void
    {
        $parentSpan = $this->createMock(SpanInterface::class);
        $parentRequestSpan = new OpenTelemetryRequestSpan($parentSpan);
        $startTimestamp = 1234567890123456789;

        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->willReturn($this->spanBuilder)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setSpanKind')
            ->with(SpanKind::KIND_CLIENT)
            ->willReturnSelf()
        ;

        $parentSpan
            ->expects($this->once())
            ->method('storeInContext')
            ->willReturn($this->context)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setParent')
            ->with($this->context)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setStartTimestamp')
            ->with($startTimestamp)
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($this->span)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);
        $result = $requestTracer->requestSpan('test.operation', $parentRequestSpan, $startTimestamp);

        $this->assertInstanceOf(OpenTelemetryRequestSpan::class, $result);
    }

    public function testRequestSpanThrowsTracerExceptionOnSpanCreationFailure(): void
    {
        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $originalException = new \RuntimeException('Span creation failed');

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->willReturn($this->spanBuilder)
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setSpanKind')
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('setParent')
            ->willReturnSelf()
        ;

        $this->spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willThrowException($originalException)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);

        $this->expectException(TracerException::class);
        $this->expectExceptionMessage('Failed to create OpenTelemetry span: Span creation failed');

        $requestTracer->requestSpan('test.operation');
    }

    public function testCloseDoesNothing(): void
    {
        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);

        // Should not throw any exceptions
        $requestTracer->close();
        $this->assertTrue(true); // Assert test passed if no exception
    }

    public function testMultipleSpansFromSameTracer(): void
    {
        $this->tracerProvider
            ->expects($this->once())
            ->method('getTracer')
            ->willReturn($this->tracer)
        ;

        $spanBuilder2 = $this->createMock(SpanBuilderInterface::class);
        $span2 = $this->createMock(SpanInterface::class);

        $this->tracer
            ->expects($this->exactly(2))
            ->method('spanBuilder')
            ->willReturnCallback(function ($name) use ($spanBuilder2) {
                return match ($name) {
                    'operation1' => $this->spanBuilder,
                    'operation2' => $spanBuilder2,
                    default => null,
                };
            })
        ;

        // Configure first span builder
        $this->spanBuilder
            ->expects($this->once())
            ->method('setSpanKind')
            ->with(SpanKind::KIND_CLIENT)
            ->willReturnSelf()
        ;
        $this->spanBuilder
            ->expects($this->once())
            ->method('setParent')
            ->with(false)
            ->willReturnSelf()
        ;
        $this->spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($this->span)
        ;

        // Configure second span builder
        $spanBuilder2
            ->expects($this->once())
            ->method('setSpanKind')
            ->with(SpanKind::KIND_CLIENT)
            ->willReturnSelf()
        ;
        $spanBuilder2
            ->expects($this->once())
            ->method('setParent')
            ->with(false)
            ->willReturnSelf()
        ;
        $spanBuilder2
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($span2)
        ;

        $requestTracer = new OpenTelemetryRequestTracer($this->tracerProvider);

        $result1 = $requestTracer->requestSpan('operation1');
        $result2 = $requestTracer->requestSpan('operation2');

        $this->assertInstanceOf(OpenTelemetryRequestSpan::class, $result1);
        $this->assertInstanceOf(OpenTelemetryRequestSpan::class, $result2);
        $this->assertNotSame($result1->unwrap(), $result2->unwrap());
    }
}
