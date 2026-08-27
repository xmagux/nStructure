<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Heartbeat;

/**
 * Tracks which sensors currently have someone actively watching their chart,
 * so the collector daemon can poll those at a much tighter interval. Shared
 * between PHP-FPM (writer, via the heartbeat endpoint) and the standalone
 * CLI daemon (reader) through a single JSON file on tmpfs — APCu would not
 * work here since php-fpm workers and a CLI process never share memory.
 *
 * Writes are atomic (write to a temp file, then rename over the target) so
 * the daemon never reads a half-written file.
 */
final class HeartbeatStore
{
    public function __construct(private readonly string $filePath)
    {
    }

    public function touch(int $sensorId, int $ttlSeconds = 15): void
    {
        $data = $this->read();
        $data[(string) $sensorId] = ['expires_at' => time() + $ttlSeconds];
        $this->write($data);
    }

    /**
     * @return int[]
     */
    public function activeSensorIds(): array
    {
        return array_map('intval', array_keys($this->read()));
    }

    /**
     * @return array<string, array{expires_at: int}>
     */
    private function read(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $contents = @file_get_contents($this->filePath);
        if ($contents === false || trim($contents) === '') {
            return [];
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $now = time();
        return array_filter($decoded, static fn (mixed $entry): bool => is_array($entry) && ($entry['expires_at'] ?? 0) > $now);
    }

    /**
     * @param array<string, array{expires_at: int}> $data
     */
    private function write(array $data): void
    {
        $directory = dirname($this->filePath);
        $temporaryPath = $directory . '/.' . basename($this->filePath) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($temporaryPath, json_encode($data, JSON_THROW_ON_ERROR));
        if ($written === false) {
            return;
        }
        @rename($temporaryPath, $this->filePath);
    }
}
