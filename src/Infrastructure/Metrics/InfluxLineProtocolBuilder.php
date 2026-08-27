<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Metrics;

/**
 * Builds a batch of InfluxDB line-protocol points for a single HTTP write.
 * VictoriaMetrics's /write endpoint speaks this format natively.
 */
final class InfluxLineProtocolBuilder
{
    /** @var string[] */
    private array $lines = [];

    /**
     * @param array<string, string> $tags
     */
    public function addPoint(string $measurement, array $tags, float $value, int $timestampMs): self
    {
        $tagString = '';
        foreach ($tags as $key => $tagValue) {
            $tagString .= ',' . $this->escape((string) $key) . '=' . $this->escape($tagValue);
        }

        $this->lines[] = sprintf(
            '%s%s value=%s %d',
            $this->escape($measurement),
            $tagString,
            $this->formatFloat($value),
            $timestampMs,
        );

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function build(): string
    {
        return implode("\n", $this->lines);
    }

    private function escape(string $value): string
    {
        return str_replace([' ', ',', '='], ['\\ ', '\\,', '\\='], $value);
    }

    private function formatFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }
}
