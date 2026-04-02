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

use Couchbase\Observability\StatusCode;
use Couchbase\OpenTelemetry\OpenTelemetryRequestSpan;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode as OtelStatusCode;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class OpenTelemetryRequestSpanTest extends TestCase
{
    private MockObject&SpanInterface $span;
    private OpenTelemetryRequestSpan $requestSpan;

    protected function setUp(): void
    {
        $this->span = $this->createMock(SpanInterface::class);
        $this->requestSpan = new OpenTelemetryRequestSpan($this->span);
    }

    public function testConstructorSetsWrappedSpan(): void
    {
        $this->assertSame($this->span, $this->requestSpan->unwrap());
    }

    public function testUnwrapReturnsWrappedSpan(): void
    {
        $wrappedSpan = $this->requestSpan->unwrap();

        $this->assertSame($this->span, $wrappedSpan);
    }

    public function testAddTagCallsSetAttributeOnWrappedSpan(): void
    {
        $key = 'operation.name';
        $value = 'get';

        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with($key, $value)
            ->willReturn($this->span)
        ;

        $this->requestSpan->addTag($key, $value);
    }

    public function testAddTagWithStringValue(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('string.tag', 'string.value')
            ->willReturn($this->span)
        ;

        $this->requestSpan->addTag('string.tag', 'string.value');
    }

    public function testAddTagWithIntValue(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('int.tag', 42)
            ->willReturn($this->span)
        ;

        $this->requestSpan->addTag('int.tag', 42);
    }

    public function testAddTagWithFloatValue(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('float.tag', 3.14)
            ->willReturn($this->span)
        ;

        $this->requestSpan->addTag('float.tag', 3.14);
    }

    public function testAddTagWithBoolValue(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('bool.tag', true)
            ->willReturn($this->span)
        ;

        $this->requestSpan->addTag('bool.tag', true);
    }

    public function testAddTagWithNullValue(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('null.tag', null)
            ->willReturn($this->span)
        ;

        $this->requestSpan->addTag('null.tag', null);
    }

    public function testAddTagWithArrayValue(): void
    {
        $arrayValue = ['item1', 'item2', 'item3'];

        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('array.tag', $arrayValue)
            ->willReturn($this->span)
        ;

        $this->requestSpan->addTag('array.tag', $arrayValue);
    }

    public function testEndWithoutTimestamp(): void
    {
        $this->span
            ->expects($this->once())
            ->method('end')
            ->with(null)
        ;

        $this->requestSpan->end();
    }

    public function testEndWithTimestamp(): void
    {
        $timestamp = 1234567890123456789;

        $this->span
            ->expects($this->once())
            ->method('end')
            ->with($timestamp)
        ;

        $this->requestSpan->end($timestamp);
    }

    public function testSetStatusOk(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setStatus')
            ->with(OtelStatusCode::STATUS_OK)
        ;

        $this->requestSpan->setStatus(StatusCode::OK);
    }

    public function testSetStatusError(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setStatus')
            ->with(OtelStatusCode::STATUS_ERROR)
        ;

        $this->requestSpan->setStatus(StatusCode::ERROR);
    }

    public function testSetStatusUnset(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setStatus')
            ->with(OtelStatusCode::STATUS_UNSET)
        ;

        $this->requestSpan->setStatus(StatusCode::UNSET);
    }

    public function testStatusCodeMapping(): void
    {
        // Test StatusCode::OK
        $span = $this->createMock(SpanInterface::class);
        $span->expects($this->once())
            ->method('setStatus')
            ->with(OtelStatusCode::STATUS_OK)
        ;
        $requestSpan = new OpenTelemetryRequestSpan($span);
        $requestSpan->setStatus(StatusCode::OK);

        // Test StatusCode::ERROR
        $span = $this->createMock(SpanInterface::class);
        $span->expects($this->once())
            ->method('setStatus')
            ->with(OtelStatusCode::STATUS_ERROR)
        ;
        $requestSpan = new OpenTelemetryRequestSpan($span);
        $requestSpan->setStatus(StatusCode::ERROR);

        // Test StatusCode::UNSET
        $span = $this->createMock(SpanInterface::class);
        $span->expects($this->once())
            ->method('setStatus')
            ->with(OtelStatusCode::STATUS_UNSET)
        ;
        $requestSpan = new OpenTelemetryRequestSpan($span);
        $requestSpan->setStatus(StatusCode::UNSET);
    }

    public function testMultipleOperationsOnSameSpan(): void
    {
        $this->span
            ->expects($this->exactly(2))
            ->method('setAttribute')
            ->willReturnCallback(function ($key, $value) {
                static $callCount = 0;
                ++$callCount;
                if (1 === $callCount) {
                    $this->assertEquals('operation', $key);
                    $this->assertEquals('get', $value);
                } elseif (2 === $callCount) {
                    $this->assertEquals('bucket', $key);
                    $this->assertEquals('default', $value);
                }

                return $this->span; // Return SpanInterface as required by the interface
            })
        ;

        $this->span
            ->expects($this->once())
            ->method('setStatus')
            ->with(OtelStatusCode::STATUS_OK)
            ->willReturn($this->span)
        ;

        $this->span
            ->expects($this->once())
            ->method('end')
        ;

        $this->requestSpan->addTag('operation', 'get');
        $this->requestSpan->addTag('bucket', 'default');
        $this->requestSpan->setStatus(StatusCode::OK);
        $this->requestSpan->end();
    }

    public function testSpanCanBeUnwrappedMultipleTimes(): void
    {
        $unwrapped1 = $this->requestSpan->unwrap();
        $unwrapped2 = $this->requestSpan->unwrap();

        $this->assertSame($this->span, $unwrapped1);
        $this->assertSame($this->span, $unwrapped2);
        $this->assertSame($unwrapped1, $unwrapped2);
    }
}
