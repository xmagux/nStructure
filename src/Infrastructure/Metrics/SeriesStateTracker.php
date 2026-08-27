<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Metrics;

/**
 * Per-series delta-on-change + hysteresis + keepalive decision, kept in
 * process memory only — after a daemon restart every series is unknown
 * again, so its first reading is always sent.
 *
 * Keepalive deadlines get a random per-series jitter so that many series
 * created around the same time (daemon startup) don't all fire their
 * "still alive" point in the same tick once the keepalive window elapses.
 */
final class SeriesStateTracker
{
    /** @var array<string, array{value: float, sentAt: int, jitter: int}> */
    private array $state = [];

    public function shouldSend(string $seriesKey, float $value, float $hysteresis, int $keepaliveSeconds, int $now): bool
    {
        $previous = $this->state[$seriesKey] ?? null;
        if ($previous === null) {
            return true;
        }
        if (abs($value - $previous['value']) > $hysteresis) {
            return true;
        }

        return ($now - $previous['sentAt']) >= max(1, $keepaliveSeconds - $previous['jitter']);
    }

    public function recordSent(string $seriesKey, float $value, int $now, int $maxJitterSeconds = 300): void
    {
        $jitter = $this->state[$seriesKey]['jitter'] ?? random_int(0, $maxJitterSeconds);
        $this->state[$seriesKey] = ['value' => $value, 'sentAt' => $now, 'jitter' => $jitter];
    }
}
