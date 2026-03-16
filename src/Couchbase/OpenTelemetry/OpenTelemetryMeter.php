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

use Couchbase\Exception\MeterException;
use Couchbase\Meter;
use Couchbase\Observability\ObservabilityConstants;
use Couchbase\ValueRecorder;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use Throwable;

/**
 * OpenTelemetry implementation of the Couchbase {@see Meter} interface.
 *
 * Wraps an OpenTelemetry {@see MeterProviderInterface} and creates histogram
 * instruments for each metric requested by the Couchbase SDK.  Histogram
 * instruments are cached so that the same instrument is reused for every call
 * with the same metric name.
 *
 * @example
 * ```php
 * use OpenTelemetry\SDK\Metrics\MeterProvider;
 * use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
 * use OpenTelemetry\Contrib\Otlp\MetricExporter;
 * use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
 *
 * $transport = (new OtlpHttpTransportFactory())->create('https://<hostname>:<port>/v1/metrics', 'application/x-protobuf');
 * $exporter = new MetricExporter($transport);
 * $meterProvider = MeterProvider::builder()
 *     ->addReader(new ExportingReader($exporter))
 *     ->build();
 *
 * $meter = new OpenTelemetryMeter($meterProvider);
 *
 * $options = new \Couchbase\ClusterOptions();
 * $options->meter($meter);
 * $cluster = \Couchbase\Cluster::connect('couchbase://127.0.0.1', $options);
 * ```
 */
class OpenTelemetryMeter implements Meter
{
    private MeterInterface $meter;
    /** @var array<string, \OpenTelemetry\API\Metrics\HistogramInterface> */
    private array $histogramCache = [];

    /**
     * Creates a new OpenTelemetry meter.
     *
     * @param MeterProviderInterface $meterProvider The OpenTelemetry meter provider to use.
     *
     * @throws MeterException If the meter cannot be created.
     */
    public function __construct(MeterProviderInterface $meterProvider)
    {
        try {
            $this->meter = $meterProvider->getMeter('com.couchbase.client/php');
        } catch (Throwable $e) {
            throw new MeterException(
                sprintf('Failed to create OpenTelemetry meter: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     *
     * Creates (or retrieves from cache) an OpenTelemetry histogram instrument
     * for the given metric name, then returns an {@see OpenTelemetryValueRecorder}
     * bound to that histogram with the supplied tags as attributes.
     *
     * The reserved tag key {@see ObservabilityConstants::ATTR_RESERVED_UNIT} is
     * stripped from the attributes and used as the histogram unit instead.
     *
     * @throws MeterException If the histogram instrument cannot be created.
     */
    public function valueRecorder(string $name, array $tags): ValueRecorder
    {
        try {
            $unit = null;
            if (isset($tags[ObservabilityConstants::ATTR_RESERVED_UNIT])) {
                $unit = $tags[ObservabilityConstants::ATTR_RESERVED_UNIT];
                unset($tags[ObservabilityConstants::ATTR_RESERVED_UNIT]);
            }

            if (!isset($this->histogramCache[$name])) {
                $this->histogramCache[$name] = $this->meter->createHistogram($name, $unit);
            }

            return new OpenTelemetryValueRecorder($this->histogramCache[$name], $tags, $unit);
        } catch (Throwable $e) {
            throw new MeterException(
                sprintf('Failed to create OpenTelemetry histogram: %s', $e->getMessage()),
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
        // Lifecycle management is the responsibility of the MeterProvider
        // passed in by the user; we do not shut it down here.
    }
}
