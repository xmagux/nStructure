<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Network;

/**
 * Runs a single fping process against a whole batch of hosts per call
 * instead of spawning one ping per host — the point of fping. Needs the
 * `fping` package installed and (on Linux) the cap_net_raw capability set
 * on the binary so it can open raw ICMP sockets without running as root —
 * see docs/DEPLOYMENT.md.
 */
final class FpingClient
{
    /**
     * @param string[] $hosts
     * @return array<string, array{ok: bool, latency_ms: float|null}>
     */
    public function pingBatch(array $hosts, int $timeoutMs = 1000): array
    {
        return $this->collectBatch($this->startBatch($hosts, $timeoutMs));
    }

    /**
     * Launches fping and returns immediately without waiting for it to
     * finish — call collectBatch() to get the results once you're ready
     * for them. Doing other work (an SNMP round, say) in between means
     * fping's ~1-3s runs concurrently with that work instead of stacking
     * on top of it.
     *
     * @param string[] $hosts
     * @return array{process: resource, pipes: array<int, resource>, hosts: string[]}|null
     */
    public function startBatch(array $hosts, int $timeoutMs = 1000): ?array
    {
        $validHosts = array_values(array_unique(array_filter($hosts, $this->isValidHost(...))));
        if ($validHosts === []) {
            return null;
        }

        // fping retries an unreachable host 3 times by default — harmless
        // for the daemon's background cadence, but costly (up to 3x the
        // timeout per dead host) for an interactive "poll everything now"
        // call. One retry matches the SNMP client's own timeout+1 policy;
        // a single missed reply just gets caught on the next check anyway.
        $command = array_merge(['fping', '-e', '-r', '1', '-t', (string) $timeoutMs], $validHosts);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        return ['process' => $process, 'pipes' => $pipes, 'hosts' => $validHosts];
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>, hosts: string[]}|null $batch
     * @return array<string, array{ok: bool, latency_ms: float|null}>
     */
    public function collectBatch(?array $batch): array
    {
        if ($batch === null) {
            return [];
        }

        $results = [];
        foreach ($batch['hosts'] as $host) {
            $results[$host] = ['ok' => false, 'latency_ms' => null];
        }

        $stdout = stream_get_contents($batch['pipes'][1]) ?: '';
        $stderr = stream_get_contents($batch['pipes'][2]) ?: '';
        fclose($batch['pipes'][1]);
        fclose($batch['pipes'][2]);
        proc_close($batch['process']);

        foreach (explode("\n", $stdout . "\n" . $stderr) as $line) {
            if (preg_match('/^(\S+)\s+is\s+(alive|unreachable)(?:\s+\(([\d.]+)\s*ms\))?/', trim($line), $matches) === 1
                && isset($results[$matches[1]])
            ) {
                $results[$matches[1]] = [
                    'ok' => $matches[2] === 'alive',
                    'latency_ms' => isset($matches[3]) ? (float) $matches[3] : null,
                ];
            }
        }

        return $results;
    }

    private function isValidHost(string $host): bool
    {
        return $host !== '' && preg_match('/^[a-zA-Z0-9.\-:]+$/', $host) === 1;
    }
}
