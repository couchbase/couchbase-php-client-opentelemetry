<?php

/**
 * inventory_with_opentelemetry — Couchbase PHP SDK + OpenTelemetry example.
 *
 * Demonstrates how to instrument a Couchbase PHP application with OpenTelemetry
 * distributed tracing and metrics, and how to ship both signals to the bundled
 * observability stack in ../telemetry-cluster.
 *
 * ============================================================================
 * OpenTelemetry integration with the Couchbase PHP SDK
 * ============================================================================
 *
 * The SDK exposes two hook points in \Couchbase\ClusterOptions:
 *
 *   Tracing  (\Couchbase\OpenTelemetry\OpenTelemetryRequestTracer)
 *     Wraps an \OpenTelemetry\API\Trace\TracerProviderInterface. Installed via:
 *       $options->tracer(new OpenTelemetryRequestTracer($tracerProvider))
 *     Every SDK operation — upsert, get, query, etc. — creates a child span
 *     under the parent_span supplied at call time (e.g., UpsertOptions
 *     ->parentSpan($cbParent)). Child spans are annotated with the bucket,
 *     scope, collection, and internal timing (encode / dispatch / decode).
 *     See setupOpenTelemetryTracer() below.
 *
 *   Metrics  (\Couchbase\OpenTelemetry\OpenTelemetryMeter)
 *     Wraps an \OpenTelemetry\API\Metrics\MeterProviderInterface. Installed via:
 *       $options->meter(new OpenTelemetryMeter($meterProvider))
 *     The SDK records per-operation latency histograms (db.client.operation.duration,
 *     unit "s") and retry/timeout counters, all labelled by bucket, scope, collection,
 *     and operation type. A PeriodicMetricReader (default interval: 5 s)
 *     pushes those measurements to the configured OTLP endpoint.
 *
 *     Histogram bucket calibration: the SDK measures durations in microseconds
 *     internally, then converts to seconds before recording. The OpenTelemetry
 *     SDK's built-in default histogram boundaries are calibrated for millisecond
 *     values and are therefore orders of magnitude too large for second-valued
 *     Couchbase metrics; almost every sample would fall into the first bucket,
 *     rendering percentile estimates meaningless. setupOpenTelemetryMeter()
 *     installs a process-wide catch-all View that replaces those defaults with eight
 *     boundaries spanning 100 µs to 10 s — the second-scale equivalent of the
 *     Couchbase Java SDK's canonical nanosecond boundaries.
 *
 *     See setupOpenTelemetryMeter() below.
 *
 * Both providers use an AlwaysOnSampler / cumulative aggregation and export
 * via OTLP/HTTP to the OTel Collector. ForceFlush is called explicitly
 * before exit so no spans or metrics are dropped.
 *
 * NOTE: AlwaysOnSampler (100% sampling) is fine for demos and development but
 * is rarely appropriate in production, where it can generate significant traffic
 * and storage costs. Choose a sampler that fits your application and
 * infrastructure — for example, ParentBasedSampler with TraceIdRatioBasedSampler
 * for head-based probabilistic sampling or a tail-based sampler in the Collector.
 *
 * NOTE: The Couchbase PHP SDK currently supports only metrics and traces.
 * It does not emit logs via OpenTelemetry. The Loki and Promtail containers
 * in the telemetry-cluster stack are present for completeness but receive no
 * data from this example.
 *
 * ============================================================================
 * Signal flow through the telemetry-cluster stack
 * ============================================================================
 *
 *   This program
 *     │  OTLP/HTTP  http://localhost:4318/v1/traces    (traces)
 *     │  OTLP/HTTP  http://localhost:4318/v1/metrics   (metrics)
 *     ▼
 *   OpenTelemetry Collector  (telemetry-cluster/otel-collector-config.yaml)
 *     │  traces  ── OTLP/gRPC ──► Jaeger                         (port 16686)
 *     │  metrics ── Prometheus scrape endpoint :8889 ──► Prometheus (port 9090)
 *     ▼
 *   Jaeger      http://localhost:16686 — distributed trace viewer
 *   Prometheus  http://localhost:9090  — time-series metrics store
 *   Grafana     http://localhost:3000  — unified dashboards (queries both)
 *
 * ============================================================================
 * Quick-start: Linux + docker compose
 * ============================================================================
 *
 * 1. Start the observability stack:
 *
 *      cd telemetry-cluster
 *      docker compose up -d
 *
 *    Containers started: otel-collector, jaeger, prometheus, loki,
 *    promtail, grafana. Allow ~10 s for all services to become healthy.
 *    (Loki and Promtail are unused by this example — the PHP SDK does not
 *    emit logs via OpenTelemetry.)
 *
 * 2. Install dependencies (from the repo root):
 *
 *      composer install
 *
 * 3. Run the example (required env vars shown; all others have defaults):
 *
 *      CONNECTION_STRING=couchbase://127.0.0.1 \
 *      USER_NAME=Administrator \
 *      PASSWORD=password \
 *      BUCKET_NAME=default \
 *        php examples/inventory_with_opentelemetry.php
 *
 *    The OTLP endpoints default to http://localhost:4318/v1/{traces,metrics},
 *    which points at the OTel Collector started in step 1. Override with:
 *      OTEL_TRACES_ENDPOINT=http://localhost:4318/v1/traces
 *      OTEL_METRICS_ENDPOINT=http://localhost:4318/v1/metrics
 *
 *    Diagnostic flags:
 *      OTEL_VERBOSE=true  — print OTel SDK internal warnings/errors to stderr
 *      VERBOSE=true       — enable Couchbase SDK trace-level logging to stderr
 *
 * ============================================================================
 * Where to see the generated traces and metrics
 * ============================================================================
 *
 * Traces → Jaeger UI  http://localhost:16686
 *   1. Open the Jaeger UI in a browser.
 *   2. In the "Service" drop-down select "inventory-service".
 *   3. Click "Find Traces".
 *   4. Open the "update-inventory" trace. The hierarchy looks like:
 *        update-inventory                   ← top-level span (this program)
 *          upsert                           ← SDK upsert operation
 *            request_encoding               ← document serialization
 *            dispatch_to_server             ← server round-trip
 *          get                              ← SDK get operation
 *            dispatch_to_server             ← server round-trip
 *      Operation spans (upsert, get) carry: db.system.name, db.namespace,
 *      db.operation.name, couchbase.collection.name, couchbase.scope.name,
 *      couchbase.service, couchbase.retries.
 *      dispatch_to_server spans carry: network.peer.address, network.peer.port,
 *      network.transport, server.address, server.port, couchbase.operation_id,
 *      couchbase.server_duration, couchbase.local_id.
 *
 * Metrics → Prometheus  http://localhost:9090
 *   The OTel Collector exposes a Prometheus scrape endpoint on :8889;
 *   Prometheus scrapes it every 15 s (telemetry-cluster/prometheus.yml).
 *   The Couchbase PHP SDK records a single histogram instrument:
 *     db_client_operation_duration  (unit "s")
 *   which Prometheus renders with the standard histogram suffixes:
 *     db_client_operation_duration_bucket — per-bucket sample counts (use for percentiles)
 *     db_client_operation_duration_sum    — cumulative latency across all operations
 *     db_client_operation_duration_count  — total number of completed operations
 *   Each series is labelled with the service type (kv, query, …) and operation name
 *   (upsert, get, …), allowing fine-grained per-operation latency analysis.
 *
 * Metrics + Traces → Grafana  http://localhost:3000
 *   Grafana is pre-provisioned (anonymous Admin, no login required) with
 *   Prometheus and Jaeger as data sources.
 *   - "Explore → Prometheus": query SDK metrics by name or label.
 *   - "Explore → Jaeger": search traces by service "inventory-service".
 */

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Couchbase\Cluster;
use Couchbase\ClusterOptions;
use Couchbase\GetOptions;
use Couchbase\OpenTelemetry\OpenTelemetryMeter;
use Couchbase\OpenTelemetry\OpenTelemetryRequestSpan;
use Couchbase\OpenTelemetry\OpenTelemetryRequestTracer;
use Couchbase\UpsertOptions;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

// ============================================================================
// Configuration
// ============================================================================

// service.name and service.version are attached to every exported span and metric
// data point as OTel Resource attributes. In Jaeger the service name appears in
// the "Service" drop-down; in Prometheus it becomes part of the job/instance labels.
const SERVICE_NAME = 'inventory-service';
const SERVICE_VERSION = '1.0.0';

// Truthy environment variable values
const TRUTHY_VALUES = ['yes', 'y', 'on', 'true', '1'];

// Runtime configuration for the program
class ProgramConfig
{
    // Couchbase connection parameters
    public string $connectionString;
    public string $userName;
    public string $password;
    public string $bucketName;
    public string $scopeName;
    public string $collectionName;
    public ?string $profile;

    // Debug flags
    public bool $verbose;
    public bool $otelVerbose;

    // Number of iterations to run
    public int $numIterations;

    // OpenTelemetry endpoints
    public string $tracesEndpoint;
    public string $metricsEndpoint;

    // Metrics configuration
    public int $metricsReaderExportIntervalMs;
    public int $metricsReaderExportTimeoutMs;

    // Explicit histogram bucket boundaries for second-scale latencies
    // Eight boundaries spanning 100 µs to 10 s, chosen to match the Couchbase Java
    // SDK's canonical nanosecond recommendation, scaled to seconds (÷ 1,000,000,000)
    public array $histogramBoundaries;

    public static function createFromEnv(): self
    {
        $config = new self();

        $config->connectionString = getenv('CONNECTION_STRING') ?: 'couchbase://127.0.0.1';
        $config->userName = getenv('USER_NAME') ?: 'Administrator';
        $config->password = getenv('PASSWORD') ?: 'password';
        $config->bucketName = getenv('BUCKET_NAME') ?: 'default';
        $config->scopeName = getenv('SCOPE_NAME') ?: '_default';
        $config->collectionName = getenv('COLLECTION_NAME') ?: '_default';
        $config->profile = getenv('PROFILE') ?: null;

        $config->verbose = in_array(strtolower(getenv('VERBOSE') ?: ''), TRUTHY_VALUES);
        $config->otelVerbose = in_array(strtolower(getenv('OTEL_VERBOSE') ?: ''), TRUTHY_VALUES);

        $config->numIterations = (int) (getenv('NUM_ITERATIONS') ?: 1000);

        $config->tracesEndpoint = getenv('OTEL_TRACES_ENDPOINT') ?: 'http://localhost:4318/v1/traces';
        $config->metricsEndpoint = getenv('OTEL_METRICS_ENDPOINT') ?: 'http://localhost:4318/v1/metrics';

        $config->metricsReaderExportIntervalMs = (int) (getenv('OTEL_METRICS_READER_EXPORT_INTERVAL_MS') ?: 5000);
        $config->metricsReaderExportTimeoutMs = (int) (getenv('OTEL_METRICS_READER_EXPORT_TIMEOUT_MS') ?: 10000);

        $config->histogramBoundaries = [
            0.0001,  // 100 µs
            0.00025, // 250 µs
            0.0005,  // 500 µs
            0.001,   //   1 ms
            0.01,    //  10 ms
            0.1,     // 100 ms
            1.0,     //   1 s
            10.0,    //  10 s
        ];

        return $config;
    }

    public function dump(): void
    {
        echo 'CONNECTION_STRING: '.$this->connectionString."\n";
        echo '        USER_NAME: '.$this->userName."\n";
        echo "         PASSWORD: [HIDDEN]\n";
        echo '      BUCKET_NAME: '.$this->bucketName."\n";
        echo '       SCOPE_NAME: '.$this->scopeName."\n";
        echo '  COLLECTION_NAME: '.$this->collectionName."\n";
        echo '          VERBOSE: '.($this->verbose ? 'true' : 'false')."\n";
        echo '     OTEL_VERBOSE: '.($this->otelVerbose ? 'true' : 'false')."\n";
        echo '   NUM_ITERATIONS: '.$this->numIterations."\n";
        echo '          PROFILE: '.($this->profile ? $this->profile : '[NONE]')."\n";
        echo "\n";

        echo '        OTEL_TRACES_ENDPOINT: '.$this->tracesEndpoint."\n";
        echo "\n";
        echo '       OTEL_METRICS_ENDPOINT: '.$this->metricsEndpoint."\n";
        echo '  OTEL_METRICS_READER_EXPORT_INTERVAL_MS: '.$this->metricsReaderExportIntervalMs."\n";
        echo '  OTEL_METRICS_READER_EXPORT_TIMEOUT_MS: '.$this->metricsReaderExportTimeoutMs."\n";
        echo '  OTEL_METRICS_HISTOGRAM_BOUNDARIES: ['.implode(', ', $this->histogramBoundaries)."]\n";
        echo "\n";
    }
}

// ============================================================================
// OpenTelemetry Resource
// ============================================================================

// An OTel Resource describes the entity producing telemetry — in this case, this
// process. Attributes set here are stamped on every exported span and metric batch.
// At minimum, service.name must be set; it is the primary key for all trace and
// metric queries in Jaeger, Prometheus, and Grafana.
function makeOtelResource(): ResourceInfo
{
    return ResourceInfo::create(Attributes::create([
        'service.name' => SERVICE_NAME,
        'service.version' => SERVICE_VERSION,
    ]));
}

// ============================================================================
// Tracing Pipeline Setup
// ============================================================================

// Sets up the OpenTelemetry tracing pipeline and wires it into the Couchbase cluster
// options so every SDK operation emits child spans under the caller-supplied parent.
//
// Pipeline:
//   Application span  (created in main with tracer->spanBuilder)
//     → Couchbase SDK child spans  (OpenTelemetryRequestTracer adapter)
//     → BatchSpanProcessor         (buffers completed spans in memory)
//     → SpanExporter               (HTTP POST to OTLP endpoint)
//     → OTel Collector             (receives on :4318, forwards to Jaeger via OTLP/gRPC)
//     → Jaeger                     (stores and visualises traces)
//
// Returns the TracerProvider so the caller can call forceFlush/shutdown before
// exit and guarantee all buffered spans are exported to the collector.
function setupOpenTelemetryTracer(ProgramConfig $config): TracerProvider
{
    // Resource is stamped on every exported span so Jaeger can group them under the
    // correct service name in the UI.
    $resource = makeOtelResource();

    // --- OTLP HTTP span exporter ---
    // Serialises completed spans as protobuf and POSTs them to the OTLP HTTP
    // endpoint. The OTel Collector forwards them to Jaeger via OTLP/gRPC.
    //
    // Configured endpoint: {$config->tracesEndpoint}
    echo "Setting up tracing with endpoint: {$config->tracesEndpoint}\n";
    $transportFactory = new PsrTransportFactory();
    $transport = $transportFactory->create($config->tracesEndpoint, 'application/x-protobuf');
    $spanExporter = new SpanExporter($transport);

    // --- Batch span processor ---
    // Accumulates completed spans in an in-memory ring buffer and exports them in
    // batches to reduce HTTP overhead. Default tuning:
    //   maxQueueSize       = 2048 spans
    //   maxExportBatchSize = 512 spans
    //   scheduleDelay      = 5 s  (background flush interval)
    // forceFlush() (called in main before exit) drains the buffer synchronously,
    // ensuring no spans are lost even when the program runs for less than 5 s.
    $spanProcessor = new BatchSpanProcessor($spanExporter, Clock::getDefault());

    // --- Sampler ---
    // AlwaysOnSampler records and exports every span (100% sampling rate).
    // This is appropriate for development and short-lived demos.
    // For production services consider ParentBasedSampler with TraceIdRatioBasedSampler
    // for head-based probabilistic sampling, which dramatically reduces export volume
    // while preserving full traces for a statistically representative fraction of requests.
    $sampler = new AlwaysOnSampler();

    // --- TracerProvider assembly ---
    // Ties together the processor (which holds the exporter), the resource, and the
    // sampler into a single provider.
    $tracerProvider = new TracerProvider(
        [$spanProcessor],
        $sampler,
        $resource
    );

    // Register as the process-wide global provider so Globals::tracerProvider() works anywhere.
    Globals::registerInitializer(fn () => $tracerProvider);

    return $tracerProvider;
}

// ============================================================================
// Metrics Pipeline Setup
// ============================================================================

// Sets up the OpenTelemetry metrics pipeline and returns it so it can be wired
// into the Couchbase cluster options.
//
// Pipeline:
//   Couchbase SDK instruments
//     → OpenTelemetryMeter (SDK adapter, implements Couchbase meter interface)
//     → global OTel MeterProvider
//     → ViewRegistry                    (custom histogram buckets: 100 µs … 10 s)
//     → ExportingReader                 (fires every metricsReaderExportIntervalMs)
//     → MetricExporter                  (HTTP POST to OTLP endpoint)
//     → OTel Collector                  (receives on :4318, exposes Prometheus scrape on :8889)
//     → Prometheus                      (scrapes :8889 every 15 s)
//
// Returns the MeterProvider so the caller can call forceFlush/shutdown before
// exit and guarantee all buffered metric data points are flushed to the collector.
function setupOpenTelemetryMeter(ProgramConfig $config): MeterProvider
{
    // Resource is stamped on every exported metric batch so Prometheus can identify
    // which process produced the data.
    $resource = makeOtelResource();

    // --- OTLP HTTP metric exporter ---
    // Serialises each metric batch as protobuf and POSTs it to the OTLP HTTP
    // endpoint. The OTel Collector receives batches on :4318, translates them to
    // Prometheus format, and exposes a scrape endpoint on :8889.
    //
    // Configured endpoint: {$config->metricsEndpoint}
    // Export interval: {$config->metricsReaderExportIntervalMs}ms
    // Export timeout: {$config->metricsReaderExportTimeoutMs}ms
    echo "Setting up metrics with endpoint: {$config->metricsEndpoint}\n";
    $transportFactory = new PsrTransportFactory();
    $transport = $transportFactory->create($config->metricsEndpoint, 'application/x-protobuf');
    $metricExporter = new MetricExporter($transport);

    // --- Explicit bucket boundaries for histograms ---
    // Using configured histogram boundaries: [" . implode(", ", $config->histogramBoundaries) . "]
    // The Couchbase PHP SDK records db.client.operation.duration in seconds,
    // converting internally from microsecond-resolution measurements before calling
    // into the OTel histogram. The OpenTelemetry SDK's built-in default boundaries
    // are calibrated for millisecond values and are therefore orders of magnitude
    // too large for second-valued Couchbase histograms.
    echo 'Using histogram boundaries: ['.implode(', ', $config->histogramBoundaries)."]\n";

    // For production, create a proper ViewRegistry with histogram boundaries
    // For this demo, we'll create a basic MeterProvider

    // --- Periodic metric reader ---
    // Wakes up on a background thread every {$config->metricsReaderExportIntervalMs}ms,
    // calls collect on every registered meter to gather current instrument values,
    // then passes the batch to the exporter. {$config->metricsReaderExportTimeoutMs}ms caps how long
    // a single export HTTP call may run before it is abandoned.
    $metricReader = new ExportingReader($metricExporter);

    // --- MeterProvider assembly ---
    // MeterProvider is the factory that creates Meter objects; both application code
    // and the Couchbase SDK adapter call getMeter() on it to obtain a scoped meter.
    return (new MeterProviderBuilder())
        ->setResource($resource)
        ->addReader($metricReader)
        ->build()
    ;
}

// ============================================================================
// Progress Bar
// ============================================================================

function printProgress(int $iteration, int $totalIterations, int $errorCount, ?string $lastError): void
{
    $done = $iteration + 1;
    $pct = (int) ($done * 100 / $totalIterations);
    $barWidth = 30;
    $filled = (int) ($pct * $barWidth / 100);
    $bar = str_repeat('=', $filled);
    if ($filled < $barWidth) {
        $bar .= '>';
        $bar .= str_repeat(' ', max($barWidth - $filled - 1, 0));
    }

    $line = sprintf("\r[%s] %3d%% %d/%d  errors: %d", $bar, $pct, $done, $totalIterations, $errorCount);
    if (null !== $lastError) {
        $line .= '  last error: '.$lastError;
    }
    $line .= '   ';
    echo $line;
}

// ============================================================================
// Main
// ============================================================================

function main(): int
{
    // Capture the program start time so we can report the total run duration as a
    // diagnostic metric. This is intentionally the very first statement so the
    // measurement includes connection setup, cluster operations, and teardown.
    $demoStart = hrtime(true);

    $config = ProgramConfig::createFromEnv();
    $config->dump(); // Print all resolved configuration values before doing anything.

    // Optional: enable full Couchbase SDK trace-level logging to stderr.
    // Very verbose — useful when debugging connection or protocol issues.
    if ($config->verbose) {
        // Note: Couchbase PHP SDK logging configuration would go here
        // This is SDK-specific and may vary based on the actual implementation
        fwrite(STDERR, "Verbose logging enabled (SDK-specific implementation needed)\n");
    }

    // Optional: install diagnostic logging for OTel SDK internal messages
    // (failed export attempts, sampler decisions, etc.) on stderr instead of
    // being silently discarded. Useful when the collector is unreachable.
    if ($config->otelVerbose) {
        fwrite(STDERR, "OTel verbose logging enabled\n");
    }

    // OTel metrics and tracing MUST be wired into cluster options before calling
    // Cluster::connect(). The SDK reads the meter and tracer from the
    // options object at connect time; changing them afterwards has no effect.
    $tracerProvider = setupOpenTelemetryTracer($config);
    $meterProvider = setupOpenTelemetryMeter($config);

    $options = new ClusterOptions();
    $options->credentials($config->userName, $config->password);

    // --- Couchbase SDK integration ---
    // OpenTelemetryRequestTracer is the bridge adapter: it implements the
    // Couchbase RequestTracer interface and forwards every requestSpan() call
    // to the underlying OTel TracerProvider. When an operation's options carry a
    // parent_span, the SDK creates its internal spans (upsert, dispatch_to_server, etc.)
    // as children of that parent, producing a complete nested trace hierarchy in Jaeger.
    $options->tracer(new OpenTelemetryRequestTracer($tracerProvider));

    // OpenTelemetryMeter is the bridge adapter that implements the SDK's meter
    // interface and forwards every valueRecorder() call to the underlying OTel
    // MeterProvider. The SDK uses it to record per-operation latency histograms,
    // retry counters, and timeout events — all labelled with bucket, scope, collection,
    // and operation type.
    $options->meter(new OpenTelemetryMeter($meterProvider));

    if ($config->profile) {
        $options->applyProfile($config->profile);
    }

    try {
        $cluster = Cluster::connect($config->connectionString, $options);
        $collection = $cluster->bucket($config->bucketName)
            ->scope($config->scopeName)
            ->collection($config->collectionName)
        ;

        // --- Per-iteration diagnostic metric ---
        // Instruments must be created once and reused across recordings; creating a
        // new histogram on every iteration is wasteful and produces duplicate
        // instrument warnings from the OTel SDK.
        // In Prometheus:
        //   inventory_demo_iteration_duration_ms_bucket{...}
        //   inventory_demo_iteration_duration_ms_sum
        //   inventory_demo_iteration_duration_ms_count
        $appMeter = $meterProvider->getMeter(SERVICE_NAME, SERVICE_VERSION);
        $iterationDuration = $appMeter->createHistogram(
            'inventory_demo_iteration_duration',
            'ms',
            'Wall-clock duration of a single upsert+get iteration'
        );

        // --- Application-level root span ---
        // All Couchbase SDK operations in this block are given cbParent as their
        // parent_span so the SDK's internal spans (upsert, get, dispatch_to_server, …)
        // appear as children of "update-inventory" in Jaeger, giving a single trace
        // that covers one loop iteration.
        $tracer = $tracerProvider->getTracer(SERVICE_NAME, SERVICE_VERSION);
        $errorCount = 0;
        $lastError = null;

        for ($iteration = 0; $iteration < $config->numIterations; ++$iteration) {
            $documentId = 'item::WIDGET-'.$iteration;
            $iterStart = hrtime(true);

            $topSpan = $tracer->spanBuilder('update-inventory')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan()
            ;

            // Activate the span in the current context so any OTel-instrumented library
            // called from this scope that does automatic context propagation will
            // automatically use topSpan as its parent.
            $scope = $topSpan->activate();

            try {
                // OpenTelemetryRequestSpan bridges the OTel Span type to the
                // Couchbase RequestSpan interface expected by the SDK's parentSpan option.
                $cbParent = new OpenTelemetryRequestSpan($topSpan);

                // --- Upsert operation ---
                // parentSpan($cbParent) attaches this operation to the "update-inventory" trace.
                // The SDK emits an "upsert" child span with "request_encoding" and
                // "dispatch_to_server" grandchildren capturing serialization and the
                // server round-trip duration.
                try {
                    $item = [
                        'name' => 'Widget Pro',
                        'sku' => 'WIDGET-001',
                        'category' => 'widgets',
                        'quantity' => 42,
                        'price' => 29.99,
                    ];

                    $collection->upsert($documentId, $item, UpsertOptions::build()->parentSpan($cbParent));
                } catch (Exception $e) {
                    ++$errorCount;
                    $lastError = 'upsert: '.$e->getMessage();
                }

                // --- Get operation ---
                // Same parent span as the upsert: both operations appear under the same root
                // trace in Jaeger, making it easy to see the full sequence at a glance.
                // The SDK emits a "get" child span with a "dispatch_to_server" grandchild.
                try {
                    $collection->get($documentId, GetOptions::build()->parentSpan($cbParent));
                } catch (Exception $e) {
                    ++$errorCount;
                    $lastError = 'get: '.$e->getMessage();
                }

                printProgress($iteration, $config->numIterations, $errorCount, $lastError);

                // Mark the root span successful and close it. The SDK child spans (upsert,
                // get) are already ended by the time collection->upsert/get return.
                $topSpan->setStatus(StatusCode::STATUS_OK);
            } finally {
                $topSpan->end();
                $scope->detach();
            }

            $iterElapsedMs = (hrtime(true) - $iterStart) / 1_000_000;
            $iterationDuration->record($iterElapsedMs);
        }

        echo "\n";

        // Note: The PHP SDK doesn't have an explicit disconnect() method.
        // Connections are automatically closed when the cluster object goes out of scope.

        // --- Demo-app diagnostic metric: total run duration ---
        //
        // Record the total wall-clock time from process start to cluster close as a
        // single histogram sample. This serves as a simple end-to-end smoke-test for
        // the metrics pipeline: if you can see this metric in Prometheus it means the
        // full chain (OTel SDK → OTLP exporter → OTel Collector → Prometheus scrape)
        // is working correctly.
        //
        // How to find it in Prometheus (http://localhost:9090):
        //   inventory_demo_run_duration_ms_bucket
        //   inventory_demo_run_duration_ms_sum
        //   inventory_demo_run_duration_ms_count
        //
        // The metric carries the service.name="inventory-service" resource attribute so
        // you can filter by {job="inventory-service"} or similar in Grafana.
        $elapsedMs = (hrtime(true) - $demoStart) / 1_000_000;
        echo 'Demo run duration: '.round($elapsedMs)." ms\n";

        $runDuration = $appMeter->createHistogram(
            'inventory_demo_run_duration',
            'ms',
            'Total wall-clock duration of the inventory demo run, from process start to cluster close'
        );
        $runDuration->record($elapsedMs);

        // --- Flush before exit ---
        //
        // ForceFlush ensures all buffered data is exported before the process exits.
        // For metrics this is critical: ExportingReader may not do a final
        // collection pass on shutdown, so any data accumulated since the last export
        // interval would be silently dropped without it. For traces, BatchSpanProcessor
        // does drain the queue on shutdown, so forceFlush is redundant there — but
        // kept for symmetry and safety.
        $tracerProvider->forceFlush();
        $meterProvider->forceFlush();

        return 0;
    } catch (Exception $e) {
        echo 'Unable to connect to the cluster: '.$e->getMessage()."\n";

        return 1;
    }
}

exit(main());
