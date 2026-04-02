# Couchbase PHP Client OpenTelemetry Integration

This package provides [OpenTelemetry](https://opentelemetry.io/) implementations of the
Couchbase PHP SDK's observability interfaces (`RequestTracer`, `RequestSpan`, `Meter`,
and `ValueRecorder`), enabling distributed tracing and metrics export via the OpenTelemetry
ecosystem.

## Requirements

- PHP 8.2+
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

## Development

### Running Tests

This library includes integration tests that verify the OpenTelemetry instrumentation works correctly with the Couchbase PHP SDK.

#### Prerequisites

1. **Install dependencies**:
   ```bash
   composer install
   ```

2. **Couchbase Server**: Tests require a running Couchbase Server instance. You can use:
   - Local installation
   - Docker: `docker run -d --name couchbase -p 8091-8096:8091-8096 -p 11210-11211:11210-11211 couchbase:latest`
   - Couchbase Cloud

3. **Environment Configuration** (optional):
   ```bash
   export TEST_CONNECTION_STRING="couchbase://127.0.0.1"
   export TEST_BUCKET="default"
   export TEST_USERNAME="Administrator"
   export TEST_PASSWORD="password"
   export TEST_SERVER_VERSION="8.0.0"
   ```

   **Note**: For multi-node clusters, the connection string can include multiple hosts (e.g., `couchbase://host1,host2,host3`). The tests will use the first host for administrative API calls.

#### Running Tests

```bash
# Run all tests
composer test
# or
vendor/bin/phpunit

# Run only integration tests (recommended)
composer test:integration
# or
vendor/bin/phpunit --testsuite=integration

# Run only unit tests
composer test:unit
# or
vendor/bin/phpunit --testsuite=unit

# Run with code coverage (requires Xdebug)
composer test:coverage
# or
vendor/bin/phpunit --coverage-html coverage/

# Run integration tests with coverage
composer test:integration:coverage

# Check PHP syntax
composer lint
```

### Code Quality

This project uses automated code quality tools to maintain consistent code style and catch syntax errors. These same checks run in CI, so running them locally helps ensure your contributions pass all quality gates.

#### Available Commands

```bash
# Check PHP syntax for all source files
composer lint

# Check code style without making changes (dry-run)
composer cs-check

# Automatically fix code style issues
composer cs-fix

# Run all quality checks (syntax + style)
composer quality
```

#### Code Style Configuration

The project uses [PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) to maintain consistent code formatting. The tool will automatically format your code according to project standards.

**Recommended workflow:**
1. Write your code
2. Run `composer quality` to check for issues
3. Run `composer cs-fix` to automatically fix style issues
4. Commit your changes

The `composer quality` command runs the same checks as our CI pipeline, so passing it locally means your PR should pass the automated quality checks.

#### Test Structure

- **Integration Tests** (`tests/Integration/`): Test the full OpenTelemetry integration with real Couchbase operations
- **Unit Tests** (`tests/` excluding Integration): Currently empty, future unit tests will go here

The integration tests verify:
- Distributed tracing functionality with proper span creation and context propagation
- Metrics collection and export
- OpenTelemetry semantic conventions compliance
- Error handling and span status mapping

#### Continuous Integration

Tests run automatically on GitHub Actions for:
- PHP 8.2, 8.3, 8.4
- Multiple Couchbase Server versions
- Linux environment

See `.github/workflows/tests.yml` for the complete CI configuration.

## License

Apache 2.0 — see [LICENSE](LICENSE).

    Copyright 2026-Present Couchbase, Inc.

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

        http://www.apache.org/licenses/LICENSE-2.0
