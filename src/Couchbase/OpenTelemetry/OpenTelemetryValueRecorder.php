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

use Couchbase\Observability\ObservabilityConstants;
use Couchbase\ValueRecorder;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * OpenTelemetry implementation of the Couchbase {@see ValueRecorder} interface.
 *
 * Wraps an OpenTelemetry {@see HistogramInterface} and records values against it,
 * converting units where necessary (e.g. microseconds → seconds).
 */
class OpenTelemetryValueRecorder implements ValueRecorder
{
    private HistogramInterface $histogram;
    /** @var array<non-empty-string, string|bool|float|int|array|null> */
    private array $attributes;
    private ?string $unit;

    /**
     * @param HistogramInterface $histogram  The underlying OTel histogram instrument.
     * @param array<non-empty-string, string|bool|float|int|array|null> $attributes
     *        Metric attributes / dimensions (the tags passed by the SDK, with the
     *        reserved {@see ObservabilityConstants::ATTR_RESERVED_UNIT} key removed).
     * @param string|null $unit  The unit string extracted from the tags (e.g. {@code "s"}).
     */
    public function __construct(HistogramInterface $histogram, array $attributes, ?string $unit = null)
    {
        $this->histogram = $histogram;
        $this->attributes = $attributes;
        $this->unit = $unit;
    }

    /**
     * {@inheritDoc}
     *
     * Values are recorded in the unit expected by the histogram instrument.
     * When the unit is {@code "s"} (seconds) the raw value – which the SDK
     * always supplies in **microseconds** – is converted to seconds before
     * recording.
     */
    public function recordValue(int $value): void
    {
        $converted = match ($this->unit) {
            ObservabilityConstants::ATTR_VALUE_RESERVED_UNIT_SECONDS => $value / 1_000_000.0,
            default => $value,
        };

        $this->histogram->record($converted, $this->attributes);
    }
}

