<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Metrics;

/**
 * Minimal client for a single-node VictoriaMetrics instance: write batches
 * of points via its InfluxDB-compatible /write endpoint (line protocol) and
 * read them back with PromQL through the Prometheus-compatible query API.
 */
final class VictoriaMetricsClient
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * Writes one pre-built batch of line-protocol points in a single request.
     */
    public function writeLineProtocol(string $body): bool
    {
        if (trim($body) === '') {
            return true;
        }
        [, $status] = $this->request('POST', '/write?precision=ms', $body, 5, 'text/plain');
        return $status >= 200 && $status < 300;
    }

    /**
     * @return array<int, array{timestampMs: int, value: float}>
     */
    public function queryRange(string $promql, int $startUnix, int $endUnix, string $step): array
    {
        $query = http_build_query([
            'query' => $promql,
            'start' => $startUnix,
            'end' => $endUnix,
            'step' => $step,
        ]);
        [$body, $status] = $this->request('GET', '/api/v1/query_range?' . $query, null);
        if ($status < 200 || $status >= 300 || $body === null) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $series = $decoded['data']['result'][0]['values'] ?? [];
        return array_map(static fn (array $pair): array => [
            'timestampMs' => (int) ((float) $pair[0] * 1000),
            'value' => (float) $pair[1],
        ], $series);
    }

    public function isReachable(): bool
    {
        [, $status] = $this->request('GET', '/health', null, 2);
        return $status >= 200 && $status < 300;
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    private function request(string $method, string $path, ?string $body, int $timeoutSeconds = 5, string $contentType = 'application/x-ndjson'): array
    {
        $handle = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: ' . $contentType];
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return [$response === false ? null : (string) $response, $status];
    }
}
