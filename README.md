# Couchbase PHP Client OpenTelemetry Integration

This package provides [OpenTelemetry](https://opentelemetry.io/) implementations of the
Couchbase PHP SDK's observability interfaces (`RequestTracer`, `RequestSpan`, `Meter`,
and `ValueRecorder`), enabling distributed tracing and metrics export via the OpenTelemetry
ecosystem.

## Requirements

- PHP 8.1+
- `couchbase/couchbase` ^4.5
- `open-telemetry/api` ^1.0

## Installation

```bash
composer require couchbase/couchbase-opentelemetry
```

## Usage

### Tracing

```php
<?php

use Couchbase\ClusterOptions;
use Couchbase\Cluster;
use Couchbase\OpenTelemetry\OpenTelemetryRequestTracer;

// Set up an OpenTelemetry TracerProvider (using the SDK + OTLP exporter as an example)
$transport = (new \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())
    ->create('https://<hostname>:<port>/v1/traces', 'application/x-protobuf');
$exporter   = new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport);
$tracerProvider = new \OpenTelemetry\SDK\Trace\TracerProvider(
    new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor($exporter)
);

// Wrap it in the Couchbase tracer
$tracer = new OpenTelemetryRequestTracer($tracerProvider);

// Pass it to ClusterOptions
$options = new ClusterOptions();
$options->credentials('Administrator', 'password');
$options->tracer($tracer);

$cluster = Cluster::connect('couchbase://127.0.0.1', $options);
```

### Metrics

```php
<?php

use Couchbase\ClusterOptions;
use Couchbase\Cluster;
use Couchbase\OpenTelemetry\OpenTelemetryMeter;

// Set up an OpenTelemetry MeterProvider (using the SDK + OTLP exporter as an example)
$transport = (new \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())
    ->create('https://<hostname>:<port>/v1/metrics', 'application/x-protobuf');
$exporter = new \OpenTelemetry\Contrib\Otlp\MetricExporter($transport);
$meterProvider = \OpenTelemetry\SDK\Metrics\MeterProvider::builder()
    ->addReader(new \OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader($exporter))
    ->build();

// Wrap it in the Couchbase meter
$meter = new OpenTelemetryMeter($meterProvider);

// Pass it to ClusterOptions
$options = new ClusterOptions();
$options->credentials('Administrator', 'password');
$options->meter($meter);

$cluster = Cluster::connect('couchbase://127.0.0.1', $options);
```

### Tracing and Metrics together

```php
<?php

use Couchbase\ClusterOptions;
use Couchbase\Cluster;
use Couchbase\OpenTelemetry\OpenTelemetryRequestTracer;
use Couchbase\OpenTelemetry\OpenTelemetryMeter;

// ... set up $tracerProvider and $meterProvider as shown above ...

$tracer = new OpenTelemetryRequestTracer($tracerProvider);
$meter  = new OpenTelemetryMeter($meterProvider);

$options = new ClusterOptions();
$options->credentials('Administrator', 'password');
$options->tracer($tracer);
$options->meter($meter);

$cluster = Cluster::connect('couchbase://127.0.0.1', $options);
```

## Classes

| Class | Couchbase Interface | Description |
|---|---|---|
| `OpenTelemetryRequestTracer` | `RequestTracer` | Creates OTel CLIENT spans for each SDK operation |
| `OpenTelemetryRequestSpan` | `RequestSpan` | Wraps an OTel `SpanInterface`; maps Couchbase status codes |
| `OpenTelemetryMeter` | `Meter` | Creates and caches OTel histograms for SDK metrics |
| `OpenTelemetryValueRecorder` | `ValueRecorder` | Records values against an OTel histogram, converting units |

## Unit Conversion

The Couchbase SDK reports operation durations internally in **microseconds**.
`OpenTelemetryValueRecorder` automatically converts values to **seconds** when the
metric's unit tag is `"s"` (as used for `db.client.operation.duration`), matching the
OpenTelemetry semantic conventions for duration metrics.

## Instrumentation Scope

Both the tracer and meter register under the instrumentation scope name
`com.couchbase.client/php`.

## License

Apache 2.0 — see [LICENSE](LICENSE).

    Copyright 2026-Present Couchbase, Inc.

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

        http://www.apache.org/licenses/LICENSE-2.0

