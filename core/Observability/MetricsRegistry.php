<?php

declare(strict_types=1);

namespace Kabuto\Observability;

final class MetricsRegistry
{
    /** @var array<string,int|float> */
    private array $counters = [];

    /** @var array<string,int|float> */
    private array $gauges = [];

    /** @var array<string,array{count:int,sum:float,min:?float,max:?float,buckets:array<string,int>}> */
    private array $histograms = [];

    /** @var array<string,array<string,string>> */
    private array $histogramLabels = [];

    /** @var list<float> */
    private array $histogramBounds;

    public function __construct(
        array $histogramBounds = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000],
        private readonly int $maxHistogramSeries = 1000,
    ) {
        $bounds = array_values(array_map('floatval', $histogramBounds));
        sort($bounds, SORT_NUMERIC);
        if (count(array_unique($bounds, SORT_REGULAR)) !== count($bounds) || array_filter($bounds, static fn (float $bound): bool => !is_finite($bound)) !== []) {
            throw new \InvalidArgumentException('Histogram bounds must contain unique finite numbers.');
        }
        if ($maxHistogramSeries < 1) throw new \InvalidArgumentException('Maximum histogram series must be positive.');
        $this->histogramBounds = $bounds;
    }

    public function increment(string $name, int|float $value = 1, array $labels = []): void
    {
        $key = $this->key($name, $labels);
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $value;
    }

    public function gauge(string $name, int|float $value, array $labels = []): void
    {
        $this->gauges[$this->key($name, $labels)] = $value;
    }

    public function observe(string $name, float $value, array $labels = []): void
    {
        if (!is_finite($value)) return;
        $key = $this->key($name, $labels);
        if (!isset($this->histograms[$key]) && count($this->histograms) >= $this->maxHistogramSeries - 1) {
            $key = $this->key($name, ['__overflow__' => 'true']);
            $labels = ['__overflow__' => 'true'];
        }
        if (!isset($this->histograms[$key])) {
            $bucketCounts = [];
            foreach ($this->histogramBounds as $bound) $bucketCounts[$this->boundKey($bound)] = 0;
            $this->histograms[$key] = ['count' => 0, 'sum' => 0.0, 'min' => null, 'max' => null, 'buckets' => $bucketCounts];
            $this->histogramLabels[$key] = array_map('strval', $labels);
        }

        $histogram = &$this->histograms[$key];
        $histogram['count']++;
        $histogram['sum'] += $value;
        $histogram['min'] = $histogram['min'] === null ? $value : min($histogram['min'], $value);
        $histogram['max'] = $histogram['max'] === null ? $value : max($histogram['max'], $value);
        foreach ($this->histogramBounds as $bound) {
            if ($value <= $bound) $histogram['buckets'][$this->boundKey($bound)]++;
        }
        unset($histogram);
    }

    public function counter(string $name, array $labels = []): int|float
    {
        return $this->counters[$this->key($name, $labels)] ?? 0;
    }

    /** @return array{counters:array<string,int|float>,gauges:array<string,int|float>,histograms:array<string,array<string,float|int>>} */
    public function snapshot(): array
    {
        $histograms = [];
        foreach ($this->histograms as $key => $values) {
            $histogram = $values;
            $histogram['min'] ??= 0;
            $histogram['max'] ??= 0;
            $histogram['buckets']['+Inf'] = $histogram['count'];
            $histograms[$key] = $histogram;
        }
        return ['counters' => $this->counters, 'gauges' => $this->gauges, 'histograms' => $histograms];
    }

    public function prometheus(): string
    {
        $lines = [];
        $declared = [];
        foreach ($this->counters as $key => $value) {
            [$name, $labels] = $this->splitKey($key);
            if (!isset($declared[$name])) {
                $lines[] = '# TYPE ' . $name . ' counter';
                $declared[$name] = true;
            }
            $lines[] = $this->formatLabeled($name, $labels, $value);
        }
        foreach ($this->gauges as $key => $value) {
            [$name, $labels] = $this->splitKey($key);
            if (!isset($declared[$name])) {
                $lines[] = '# TYPE ' . $name . ' gauge';
                $declared[$name] = true;
            }
            $lines[] = $this->formatLabeled($name, $labels, $value);
        }
        foreach ($this->histograms as $key => $histogram) {
            $labels = $this->histogramLabels[$key] ?? [];
            [$name] = $this->splitKey($key);
            if (!isset($declared[$name])) {
                $lines[] = '# TYPE ' . $name . ' histogram';
                $declared[$name] = true;
            }
            foreach ($this->histogramBounds as $bound) {
                $le = $this->boundKey($bound);
                $lines[] = $this->formatHistogram($name, $labels, $le, $histogram['buckets'][$le]);
            }
            $lines[] = $this->formatHistogram($name, $labels, '+Inf', $histogram['count']);
            $lines[] = $this->formatLabeled($name . '_sum', $labels, $histogram['sum']);
            $lines[] = $this->formatLabeled($name . '_count', $labels, $histogram['count']);
        }
        return implode("\n", $lines) . ($lines === [] ? '' : "\n");
    }

    private function key(string $name, array $labels): string
    {
        ksort($labels);
        $suffix = [];
        foreach ($labels as $label => $value) $suffix[] = $label . '=' . $this->label((string) $value);
        return $name . ($suffix === [] ? '' : '{' . implode(',', $suffix) . '}');
    }

    private function label(string $value): string
    {
        return '"' . addcslashes($value, "\\\"\n") . '"';
    }

    private function formatHistogram(string $series, array $labels, string $le, int $value): string
    {
        $labels['le'] = $le;
        return $this->formatLabeled($series . '_bucket', $labels, $value);
    }

    private function formatLabeled(string $name, array $labels, int|float $value): string
    {
        ksort($labels);
        $formatted = [];
        foreach ($labels as $label => $labelValue) $formatted[] = $label . '=' . $this->label((string) $labelValue);
        return $name . ($formatted === [] ? '' : '{' . implode(',', $formatted) . '}') . ' ' . $value;
    }

    /** @return array{string,array<string,string>} */
    private function splitKey(string $key): array
    {
        $start = strpos($key, '{');
        if ($start === false || !str_ends_with($key, '}')) return [$key, []];
        $labels = [];
        $text = substr($key, $start + 1, -1);
        if ($text !== '' && preg_match_all('/([^=,]+)="((?:\\\\.|[^"])*)"/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) $labels[$match[1]] = stripcslashes($match[2]);
        }
        return [substr($key, 0, $start), $labels];
    }

    private function boundKey(float $bound): string
    {
        return rtrim(rtrim(sprintf('%.14F', $bound), '0'), '.');
    }
}
