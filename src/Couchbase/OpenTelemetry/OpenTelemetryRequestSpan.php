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

namespace Couchbase\OpenTelemetry;

use Couchbase\Observability\StatusCode;
use Couchbase\RequestSpan;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode as OtelStatusCode;

/**
 * OpenTelemetry implementation of the Couchbase {@see RequestSpan} interface.
 *
 * Wraps an OpenTelemetry {@see SpanInterface} and delegates all operations to it,
 * mapping Couchbase-specific status codes to their OpenTelemetry equivalents.
 */
class OpenTelemetryRequestSpan implements RequestSpan
{
    private SpanInterface $wrapped;

    public function __construct(SpanInterface $span)
    {
        $this->wrapped = $span;
    }

    /**
     * Returns the underlying OpenTelemetry span.
     *
     * @internal
     */
    public function unwrap(): SpanInterface
    {
        return $this->wrapped;
    }

    public function addTag(string $key, mixed $value): void
    {
        $this->wrapped->setAttribute($key, $value);
    }

    public function end(?int $endTimestampNanoseconds = null): void
    {
        $this->wrapped->end($endTimestampNanoseconds);
    }

    /**
     * {@inheritDoc}
     *
     * Maps Couchbase {@see StatusCode} values to OpenTelemetry status codes:
     * - {@see StatusCode::OK} → {@see OtelStatusCode::STATUS_OK}
     * - {@see StatusCode::ERROR} → {@see OtelStatusCode::STATUS_ERROR}
     * - {@see StatusCode::UNSET} → {@see OtelStatusCode::STATUS_UNSET}
     */
    public function setStatus(StatusCode $statusCode): void
    {
        $otelStatusCode = match ($statusCode) {
            StatusCode::OK => OtelStatusCode::STATUS_OK,
            StatusCode::ERROR => OtelStatusCode::STATUS_ERROR,
            StatusCode::UNSET => OtelStatusCode::STATUS_UNSET,
        };

        $this->wrapped->setStatus($otelStatusCode);
    }
}
