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

use Couchbase\Exception\TracerException;
use Couchbase\RequestSpan;
use Couchbase\RequestTracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use Throwable;

/**
 * OpenTelemetry implementation of the Couchbase {@see RequestTracer} interface.
 *
 * Wraps an OpenTelemetry {@see TracerProviderInterface} and uses it to create
 * OpenTelemetry spans for each Couchbase SDK operation.
 *
 * @example
 * ```php
 * use OpenTelemetry\SDK\Trace\TracerProvider;
 * use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
 * use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
 * use OpenTelemetry\Contrib\Otlp\SpanExporter;
 *
 * $transport = (new OtlpHttpTransportFactory())->create('https://<hostname>:<port>/v1/traces', 'application/x-protobuf');
 * $exporter = new SpanExporter($transport);
 * $tracerProvider = new TracerProvider(new BatchSpanProcessor($exporter));
 *
 * $tracer = new OpenTelemetryRequestTracer($tracerProvider);
 *
 * $options = new \Couchbase\ClusterOptions();
 * $options->tracer($tracer);
 * $cluster = \Couchbase\Cluster::connect('couchbase://127.0.0.1', $options);
 * ```
 */
class OpenTelemetryRequestTracer implements RequestTracer
{
    private TracerInterface $tracer;

    /**
     * Creates a new OpenTelemetry request tracer.
     *
     * @param TracerProviderInterface $tracerProvider The OpenTelemetry tracer provider to use.
     *
     * @throws TracerException If the tracer cannot be created.
     */
    public function __construct(TracerProviderInterface $tracerProvider)
    {
        try {
            $this->tracer = $tracerProvider->getTracer('com.couchbase.client/php');
        } catch (Throwable $e) {
            throw new TracerException(
                sprintf('Failed to create OpenTelemetry tracer: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     *
     * Creates a new OpenTelemetry span as a CLIENT span. If a parent
     * {@see OpenTelemetryRequestSpan} is provided, the new span will be a child of it.
     *
     * @throws TracerException If the span cannot be created.
     */
    public function requestSpan(string $name, ?RequestSpan $parent = null, ?int $startTimestampNanoseconds = null): RequestSpan
    {
        try {
            $spanBuilder = $this->tracer
                ->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_CLIENT);

            if ($parent instanceof OpenTelemetryRequestSpan) {
                // Build a Context that carries the parent OTel span so the
                // new span is properly linked as a child.
                $parentContext = $parent->unwrap()->storeInContext(Context::getCurrent());
                $spanBuilder->setParent($parentContext);
            } else {
                // Explicitly detach from any ambient context so the span
                // is always a true root when no Couchbase parent is given.
                $spanBuilder->setParent(false);
            }

            if ($startTimestampNanoseconds !== null) {
                $spanBuilder->setStartTimestamp($startTimestampNanoseconds);
            }

            return new OpenTelemetryRequestSpan($spanBuilder->startSpan());
        } catch (Throwable $e) {
            throw new TracerException(
                sprintf('Failed to create OpenTelemetry span: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
    }
}

