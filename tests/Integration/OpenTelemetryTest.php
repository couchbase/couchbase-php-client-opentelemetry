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

namespace Couchbase\OpenTelemetry\Tests\Integration;

use Couchbase\BucketInterface;
use Couchbase\Cluster;
use Couchbase\ClusterInterface;
use Couchbase\ClusterOptions;
use Couchbase\CollectionInterface;
use Couchbase\Exception\DocumentNotFoundException;
use Couchbase\OpenTelemetry\OpenTelemetryMeter;
use Couchbase\OpenTelemetry\OpenTelemetryRequestTracer;
use Couchbase\UpsertOptions;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanInMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class OpenTelemetryTest extends TestCase
{
    private SpanInMemoryExporter $spanExporter;
    private TracerProvider $tracerProvider;
    private OpenTelemetryRequestTracer $tracer;

    private MetricInMemoryExporter $metricExporter;
    private ExportingReader $metricReader;
    private MeterProviderInterface $meterProvider;
    private OpenTelemetryMeter $meter;

    private ClusterInterface $cluster;
    private BucketInterface $bucket;
    private CollectionInterface $collection;

    private string $connectionString;
    private string $bucketName;
    private string $username;
    private string $password;
    private string $serverVersion;

    private ?array $clusterLabels = null;

    protected function setUp(): void
    {
        $this->connectionString = getenv('TEST_CONNECTION_STRING') ?: 'couchbase://192.168.106.130';
        $this->bucketName = getenv('TEST_BUCKET') ?: 'default';
        $this->username = getenv('TEST_USERNAME') ?: 'Administrator';
        $this->password = getenv('TEST_PASSWORD') ?: 'password';
        $this->serverVersion = getenv('TEST_SERVER_VERSION') ?: '8.0.0';

        $this->spanExporter = new SpanInMemoryExporter();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($this->spanExporter)
        );
        $this->tracer = new OpenTelemetryRequestTracer($this->tracerProvider);

        $this->metricExporter = new MetricInMemoryExporter();
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = MeterProvider::builder()
            ->addReader($this->metricReader)
            ->build();
        $this->meter = new OpenTelemetryMeter($this->meterProvider);

        $options = new ClusterOptions();
        $options->credentials($this->username, $this->password);
        $options->tracer($this->tracer);
        $options->meter($this->meter);

        $this->cluster = Cluster::connect($this->connectionString, $options);
        $this->bucket = $this->cluster->bucket($this->bucketName);
        $this->collection = $this->bucket->defaultCollection();
    }

    protected function tearDown(): void
    {
        unset($this->collection, $this->bucket, $this->cluster);
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
    }

    private function assertOtelSpan(
        SpanDataInterface $spanData,
        string $name,
        array $attributes = [],
        ?string $parentSpanId = null,
        string $statusCode = StatusCode::STATUS_UNSET,
    ): void {
        $this->assertSame($name, $spanData->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $spanData->getKind());
        $this->assertSame($statusCode, $spanData->getStatus()->getCode());

        if ($parentSpanId === null) {
            $this->assertTrue(
                hexdec($spanData->getParentSpanId()) === 0,
                "Expected parent span ID to be zero"
            );
        } else {
            $this->assertSame($parentSpanId, $spanData->getParentSpanId());
        }

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                $this->assertTrue(
                    $spanData->getAttributes()->has($key),
                    "Expected attribute {$key} to be present"
                );
            } else {
                $this->assertSame(
                    $value,
                    $spanData->getAttributes()->get($key),
                    "Expected attribute {$key} to have value {$value}"
                );
            }
        }
    }

    private function uniqId(string $prefix): string
    {
        return sprintf('%s_%s', $prefix, bin2hex(random_bytes(8)));
    }

    private function supportsClusterLabels(): bool
    {
        return version_compare($this->serverVersion, '7.6.4', '>=');
    }

    private function fetchClusterLabels(): array
    {
        if ($this->clusterLabels !== null) {
            return $this->clusterLabels;
        }

        $parsed = parse_url($this->connectionString);
        $host = $parsed['host'] ?? '192.168.106.130';
        $scheme = ($parsed['scheme'] ?? '') === 'couchbases' ? 'https' : 'http';
        $port = $scheme === 'https' ? 18091 : 8091;

        $url = sprintf('%s://%s:%d/pools/default/nodeServices', $scheme, $host, $port);
        $context = stream_context_create([
            'http' => [
                'header' => 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        $body = json_decode($response, true);

        $this->clusterLabels = [
            'cluster_name' => $body['clusterName'] ?? '',
            'cluster_uuid' => $body['clusterUUID'] ?? '',
        ];

        return $this->clusterLabels;
    }

    private function clusterName(): string
    {
        return $this->fetchClusterLabels()['cluster_name'];
    }

    private function clusterUuid(): string
    {
        return $this->fetchClusterLabels()['cluster_uuid'];
    }

    public function testOpenTelemetryTracer(): void
    {
        $parentSpan = $this->tracer->requestSpan("parent_span");

        $opts = new UpsertOptions();
        $opts->parentSpan($parentSpan);
        $res = $this->collection->upsert($this->uniqId('otel_test'), ['foo' => 'bar'], $opts);

        $this->assertNotNull($res->cas());

        $parentSpan->end();
        $spans = $this->spanExporter->getSpans();
        usort($spans, fn($a, $b) => $a->getStartEpochNanos() <=> $b->getStartEpochNanos());

        $this->assertOtelSpan(
            $spans[0],
            'parent_span',
        );

        $expectedAttributes = [
            'db.system.name' => 'couchbase',
            'db.operation.name' => 'upsert',
            'db.namespace' => $this->bucketName,
            'couchbase.scope.name' => '_default',
            'couchbase.collection.name' => '_default',
      //      'couchbase.retries' => null,
        ];
        if ($this->supportsClusterLabels()) {
            $expectedAttributes['couchbase.cluster.name'] = $this->clusterName();
            $expectedAttributes['couchbase.cluster.uuid'] = $this->clusterUuid();
        }

        $this->assertOtelSpan(
            $spans[1],
            'upsert',
            attributes: $expectedAttributes,
            parentSpanId: $spans[0]->getSpanId(),
            statusCode: StatusCode::STATUS_OK,
        );

        $expectedAttributes = [
            'db.system.name' => 'couchbase',
        ];
        if ($this->supportsClusterLabels()) {
            $expectedAttributes['couchbase.cluster.name'] = $this->clusterName();
            $expectedAttributes['couchbase.cluster.uuid'] = $this->clusterUuid();
        }

        $this->assertOtelSpan(
            $spans[2],
            'request_encoding',
            attributes: $expectedAttributes,
            parentSpanId: $spans[1]->getSpanId(),
        );
// TODO: Uncomment once done
//        $expectedAttributes = [
//            'db.system.name' => 'couchbase',
//            'network.peer.address' => null,
//            'network.peer.port' => null,
//            'network.transport' => 'tcp',
//            'server.address' => null,
//            'server.port' => null,
//            'couchbase.local_id' => null,
//        ];
//        if ($this->supportsClusterLabels()) {
//            $expectedAttributes['couchbase.cluster.name'] = $this->clusterName();
//            $expectedAttributes['couchbase.cluster.uuid'] = $this->clusterUuid();
//        }
//
//        $this->assertOtelSpan(
//            $spans[3],
//            'dispatch_to_server',
//            attributes: $expectedAttributes,
//            parentSpanId: $spans[1]->getSpanId(),
//        );
    }

    public function testOpenTelemetryMeter(): void
    {
        try {
            $this->collection->get($this->uniqId('does_not_exist'));
        } catch (DocumentNotFoundException) {
        }

        $this->collection->insert($this->uniqId('otel_test'), ['foo' => 'bar']);

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect(true);

        $this->assertCount(1, $metrics);

        $metric = $metrics[0];

        $this->assertSame('db.client.operation.duration', $metric->name);
        $this->assertSame('s', $metric->unit);
        $this->assertInstanceOf(Histogram::class, $metric->data);

        $dataPoints = iterator_to_array($metric->data->dataPoints);
        $this->assertCount(2, $dataPoints);

        foreach ($dataPoints as $idx => $point) {
            $this->assertSame('couchbase', $point->attributes->get('db.system.name'));

            if ($this->supportsClusterLabels()) {
                $this->assertSame($this->clusterName(), $point->attributes->get('couchbase.cluster.name'));
                $this->assertSame($this->clusterUuid(), $point->attributes->get('couchbase.cluster.uuid'));
            } else {
                $this->assertNull($point->attributes->get('couchbase.cluster.name'));
                $this->assertNull($point->attributes->get('couchbase.cluster.uuid'));
            }

            $this->assertSame($this->bucketName, $point->attributes->get('db.namespace'));
            $this->assertSame('_default', $point->attributes->get('couchbase.scope.name'));
            $this->assertSame('_default', $point->attributes->get('couchbase.collection.name'));
            $this->assertSame('kv', $point->attributes->get('couchbase.service'));

            switch ($idx) {
                case 0:
                    $this->assertSame('get', $point->attributes->get('db.operation.name'));
                    $this->assertSame('DocumentNotFound', $point->attributes->get('error.type'));
                    break;
                case 1:
                    $this->assertSame('insert', $point->attributes->get('db.operation.name'));
                    $this->assertNull($point->attributes->get('error.type'));
                    break;
            }
        }
    }
}
