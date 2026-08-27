<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Metrics;

/**
 * In-memory retry buffer for line-protocol points that failed to reach
 * VictoriaMetrics. Bounded so a prolonged outage can't grow the daemon's
 * memory without limit — once full, the oldest points are dropped (logged)
 * to make room for new ones.
 */
final class MetricsBuffer
{
    /** @var string[] */
    private array $lines = [];

    public function __construct(private readonly int $maxLines = 5000)
    {
    }

    public function add(string $linesBlock): void
    {
        if (trim($linesBlock) === '') {
            return;
        }
        foreach (explode("\n", $linesBlock) as $line) {
            if ($line !== '') {
                $this->lines[] = $line;
            }
        }
        $overflow = count($this->lines) - $this->maxLines;
        if ($overflow > 0) {
            $this->lines = array_slice($this->lines, $overflow);
            fwrite(STDERR, sprintf("[%s] Metrics buffer full, dropped %d oldest point(s).\n", date('c'), $overflow));
        }
    }

    public function flush(VictoriaMetricsClient $client): bool
    {
        if ($this->lines === []) {
            return true;
        }
        if ($client->writeLineProtocol(implode("\n", $this->lines))) {
            $this->lines = [];
            return true;
        }
        return false;
    }

    public function count(): int
    {
        return count($this->lines);
    }
}
