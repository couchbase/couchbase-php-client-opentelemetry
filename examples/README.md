# Couchbase PHP SDK + OpenTelemetry Examples

This directory contains examples demonstrating how to instrument Couchbase PHP applications with OpenTelemetry for distributed tracing and metrics collection.

## Prerequisites

### 1. Couchbase PHP Extension

The examples require the Couchbase PHP extension to be installed and enabled:

```bash
php -m | grep couchbase
```

If not present, install it following the [Couchbase PHP SDK documentation](https://docs.couchbase.com/php-sdk/current/hello-world/start-using-sdk.html).

### 2. Dependencies

Install the required packages:

```bash
composer install
```

**For production use with OTLP export** (required to actually send telemetry to the observability stack):

```bash
composer require open-telemetry/exporter-otlp
```

**Note**: The example uses in-memory exporters for demonstration purposes. To actually export telemetry to the observability stack, you need the OTLP exporters package above.

### 3. Observability Stack

The examples export telemetry to a local observability stack. Start it using the Docker Compose files from the [couchbase-cxx-client-demo](https://github.com/couchbaselabs/couchbase-cxx-client-demo) repository:

```bash
git clone https://github.com/couchbaselabs/couchbase-cxx-client-demo.git
cd couchbase-cxx-client-demo/telemetry-cluster
docker compose up -d
```

This starts:
- **OpenTelemetry Collector** (port 4318) - receives traces and metrics via OTLP
- **Jaeger** (port 16686) - distributed trace viewer
- **Prometheus** (port 9090) - time-series metrics store
- **Grafana** (port 3000) - unified dashboards (anonymous login enabled)
- Loki + Promtail (unused by PHP SDK - for completeness)

Allow ~10 seconds for all services to become healthy.

## Examples

### inventory_with_opentelemetry.php

A complete demonstration of OpenTelemetry integration showing:

- **Distributed Tracing**: Every Couchbase operation (upsert, get) creates child spans under application-level traces
- **Metrics Collection**: SDK records operation latency histograms with custom bucket boundaries optimized for sub-second operations
- **In-Memory Export**: For demonstration, telemetry is collected in memory (install `open-telemetry/exporter-otlp` for real OTLP export)

#### Usage

**Basic usage** (with defaults):
```bash
php examples/inventory_with_opentelemetry.php
```

**Full configuration** (all supported environment variables):
```bash
CONNECTION_STRING=couchbase://127.0.0.1 \
USER_NAME=Administrator \
PASSWORD=password \
BUCKET_NAME=default \
SCOPE_NAME=_default \
COLLECTION_NAME=_default \
NUM_ITERATIONS=1000 \
OTEL_TRACES_ENDPOINT=http://localhost:4318/v1/traces \
OTEL_METRICS_ENDPOINT=http://localhost:4318/v1/metrics \
OTEL_METRICS_READER_EXPORT_INTERVAL_MS=5000 \
OTEL_METRICS_READER_EXPORT_TIMEOUT_MS=10000 \
VERBOSE=false \
OTEL_VERBOSE=false \
  php examples/inventory_with_opentelemetry.php
```

#### Environment Variables

| Variable                                 | Default                             | Description                                      |
|------------------------------------------|-------------------------------------|--------------------------------------------------|
| `CONNECTION_STRING`                      | `couchbase://127.0.0.1`             | Couchbase cluster connection string              |
| `USER_NAME`                              | `Administrator`                     | Couchbase RBAC username                          |
| `PASSWORD`                               | `password`                          | Couchbase RBAC password                          |
| `BUCKET_NAME`                            | `default`                           | Target bucket name                               |
| `SCOPE_NAME`                             | `_default`                          | Target scope name                                |
| `COLLECTION_NAME`                        | `_default`                          | Target collection name                           |
| `PROFILE`                                | *none*                              | SDK connection profile (e.g., `wan_development`) |
| `NUM_ITERATIONS`                         | `1000`                              | Number of upsert+get operations to perform       |
| `OTEL_TRACES_ENDPOINT`                   | `http://localhost:4318/v1/traces`   | OTLP traces endpoint                             |
| `OTEL_METRICS_ENDPOINT`                  | `http://localhost:4318/v1/metrics`  | OTLP metrics endpoint                            |
| `OTEL_METRICS_READER_EXPORT_INTERVAL_MS` | `5000`                              | Metrics export interval (ms)                     |
| `OTEL_METRICS_READER_EXPORT_TIMEOUT_MS`  | `10000`                             | Metrics export timeout (ms)                      |
| `VERBOSE`                                | `false`                             | Enable Couchbase SDK debug logging               |
| `OTEL_VERBOSE`                           | `false`                             | Enable OpenTelemetry SDK debug logging           |

**Truthy values** for boolean flags: `yes`, `y`, `on`, `true`, `1` (case-insensitive)

## Viewing Telemetry Data

### Traces → Jaeger UI (http://localhost:16686)

1. Open the Jaeger UI in a browser
2. In the "Service" drop-down, select "inventory-service"
3. Click "Find Traces"
4. Open an "update-inventory" trace to see the span hierarchy:

```
update-inventory                   ← application root span
├── upsert                         ← SDK upsert operation
│   ├── request_encoding           ← document serialization
│   └── dispatch_to_server         ← server round-trip
└── get                            ← SDK get operation
    └── dispatch_to_server         ← server round-trip
```

**Span attributes include:**
- Operation spans: `db.system.name`, `db.namespace`, `db.operation.name`, `couchbase.collection.name`, `couchbase.scope.name`, `couchbase.service`, `couchbase.retries`
- Network spans: `network.peer.address`, `network.peer.port`, `network.transport`, `server.address`, `server.port`, `couchbase.operation_id`, `couchbase.server_duration`, `couchbase.local_id`

### Metrics → Prometheus (http://localhost:9090)

The Couchbase PHP SDK records operation latency histograms:

- `db_client_operation_duration_bucket` — per-bucket sample counts (use for percentiles)
- `db_client_operation_duration_sum` — cumulative latency across all operations
- `db_client_operation_duration_count` — total number of completed operations

Each series is labeled with:
- `db_system_name="couchbase"`
- `couchbase_service` (kv, query, analytics, etc.)
- `db_operation_name` (upsert, get, query, etc.)
- `couchbase_bucket_name`, `couchbase_scope_name`, `couchbase_collection_name`

**Example queries:**
```promql
# P99 latency for all operations
histogram_quantile(0.99, rate(db_client_operation_duration_bucket[5m]))

# Operations per second by type
rate(db_client_operation_duration_count[5m])

# Average latency by operation type
rate(db_client_operation_duration_sum[5m]) / rate(db_client_operation_duration_count[5m])
```

The demo also emits application-level metrics:
- `inventory_demo_iteration_duration_*` — per-iteration wall-clock time
- `inventory_demo_run_duration_*` — total demo execution time

### Unified View → Grafana (http://localhost:3000)

Grafana provides unified dashboards combining traces and metrics:

- **Explore → Prometheus**: Query SDK metrics by name or label
- **Explore → Jaeger**: Search traces by service "inventory-service"
- Anonymous Admin login is enabled (no credentials required)

## Signal Flow Architecture

```
PHP Application
│  OTLP/HTTP  http://localhost:4318/v1/traces    (traces)
│  OTLP/HTTP  http://localhost:4318/v1/metrics   (metrics)
▼
OpenTelemetry Collector
│  traces  ── OTLP/gRPC ──► Jaeger         (port 16686)
│  metrics ── Prometheus scrape :8889 ──► Prometheus (port 9090)
▼
Grafana  (port 3000) ── queries both Jaeger and Prometheus
```

## Histogram Bucket Configuration

The examples configure custom histogram boundaries optimized for Couchbase operation latencies. The SDK measures durations in microseconds internally, then converts to seconds before recording in OpenTelemetry histograms.

**Default OTel boundaries** (calibrated for millisecond HTTP latencies):
```
[0, 5, 10, 25, 50, 75, 100, 250, 500, 750, 1000, 2500, 5000, 7500, 10000] seconds
```

**Couchbase-optimized boundaries** (sub-second database operations):
```
[0.0001, 0.00025, 0.0005, 0.001, 0.01, 0.1, 1.0, 10.0] seconds
```

This ensures meaningful percentile calculations for typical Couchbase operation latencies (100µs - 10s range).

## Production Considerations

### Sampling

The examples use `AlwaysOnSampler` (100% sampling) for demonstration purposes. In production:

- Use **ParentBasedSampler** with **TraceIdRatioBasedSampler** for head-based probabilistic sampling (e.g., 1%)
- Consider **tail-based sampling** in the OpenTelemetry Collector for more sophisticated sampling strategies
- Monitor sampling impact on performance and costs

### Resource Management

- The OpenTelemetry SDK providers (`TracerProvider`, `MeterProvider`) manage background threads and network resources
- Call `forceFlush()` before application shutdown to ensure telemetry export completion
- In long-running applications, monitor memory usage of span processors and metric readers

### Security

- Use TLS endpoints for production OTLP exports
- Implement proper authentication/authorization for observability backends
- Consider network policies for telemetry data flows
- Be mindful of sensitive data in span attributes and metric labels

## Troubleshooting

### No traces in Jaeger
1. Verify the observability stack is running: `docker ps`
2. Check OTLP endpoint connectivity: `curl -v http://localhost:4318/v1/traces`
3. Enable verbose logging: `OTEL_VERBOSE=true`
4. Verify spans are being created in application code

### No metrics in Prometheus
1. Check OTel Collector metrics endpoint: `curl http://localhost:8889/metrics`
2. Verify Prometheus scraping: check Prometheus UI → Status → Targets
3. Confirm metric export interval hasn't expired
4. Enable verbose logging: `OTEL_VERBOSE=true`

### Connection issues
1. Verify Couchbase cluster connectivity: `telnet 127.0.0.1 11210`
2. Check SDK credentials and RBAC permissions
3. Enable SDK logging: `VERBOSE=true`
4. Verify bucket/scope/collection existence

### Performance impact
1. Reduce sampling rate for high-throughput applications
2. Increase export batch sizes and intervals
3. Monitor span processor queue sizes
4. Consider async export modes where available

## License

These examples are licensed under the Apache License 2.0 — see [LICENSE](../LICENSE).
