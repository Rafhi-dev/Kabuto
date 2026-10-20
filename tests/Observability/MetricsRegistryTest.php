<?php

declare(strict_types=1);

namespace Kabuto\Tests\Observability;

use Kabuto\Observability\MetricsRegistry;
use Kabuto\Tests\TestCase;

final class MetricsRegistryTest extends TestCase
{
    public function testCountersGaugesAndHistogramsAreRecorded(): void
    {
        $metrics = new MetricsRegistry();
        $metrics->increment('requests_total', labels: ['method' => 'GET']);
        $metrics->increment('requests_total', 2, ['method' => 'GET']);
        $metrics->gauge('connections', 3, ['driver' => 'redis']);
        $metrics->observe('duration_ms', 10.5, ['route' => '/health']);
        $metrics->observe('duration_ms', 20.5, ['route' => '/health']);

        $snapshot = $metrics->snapshot();
        self::assertSame(3, $snapshot['counters']['requests_total{method="GET"}']);
        self::assertSame(3, $snapshot['gauges']['connections{driver="redis"}']);
        self::assertSame(2, $snapshot['histograms']['duration_ms{route="/health"}']['count']);
        self::assertSame(31.0, $snapshot['histograms']['duration_ms{route="/health"}']['sum']);
        self::assertStringContains('requests_total{method="GET"} 3', $metrics->prometheus());
        self::assertStringContains('duration_ms_bucket{le="25",route="/health"} 2', $metrics->prometheus());
        self::assertStringContains('duration_ms_bucket{le="+Inf",route="/health"} 2', $metrics->prometheus());
        self::assertStringContains('duration_ms_sum{route="/health"} 31', $metrics->prometheus());
        self::assertStringContains('duration_ms_count{route="/health"} 2', $metrics->prometheus());
    }

    public function testLabelsAreCanonicalized(): void
    {
        $metrics = new MetricsRegistry();
        $metrics->increment('jobs_total', labels: ['z' => 'last', 'a' => 'first']);
        self::assertSame(1, $metrics->counter('jobs_total', ['a' => 'first', 'z' => 'last']));
    }

    public function testHistogramStorageIsBoundedAndOverflowSeriesAggregates(): void
    {
        $metrics = new MetricsRegistry([10, 100], maxHistogramSeries: 3);
        $metrics->observe('latency', 5, ['route' => '/a']);
        $metrics->observe('latency', 50, ['route' => '/b']);
        $metrics->observe('latency', 500, ['route' => '/c']);
        $metrics->observe('latency', 5000, ['route' => '/d']);

        $snapshot = $metrics->snapshot();
        self::assertCount(3, $snapshot['histograms']);
        self::assertSame(2, $snapshot['histograms']['latency{__overflow__="true"}']['count']);
        self::assertSame(5500.0, $snapshot['histograms']['latency{__overflow__="true"}']['sum']);
        self::assertSame(0, $snapshot['histograms']['latency{__overflow__="true"}']['buckets']['10']);
        self::assertSame(0, $snapshot['histograms']['latency{__overflow__="true"}']['buckets']['100']);
        self::assertSame(2, $snapshot['histograms']['latency{__overflow__="true"}']['buckets']['+Inf']);
    }

    public function testHistogramRejectsNonFiniteValues(): void
    {
        $metrics = new MetricsRegistry();
        $metrics->observe('latency', INF);
        $metrics->observe('latency', NAN);
        self::assertSame([], $metrics->snapshot()['histograms']);
    }
}
