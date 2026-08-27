<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Snmp;

/**
 * Thin wrapper around the php-snmp extension's snmp2_get(). Requires the
 * `snmp` PHP extension to be installed (not bundled by default on every
 * distribution) — see docs/DEPLOYMENT.md.
 */
final class Snmp2Client
{
    private bool $configured = false;

    /**
     * @return array{ok: bool, value: string|null, error: string|null}
     */
    public function get(string $host, int $port, string $community, string $oid, int $timeoutMicroseconds = 1_000_000, int $retries = 1): array
    {
        if (!function_exists('snmp2_get')) {
            return ['ok' => false, 'value' => null, 'error' => 'php-snmp extension is not installed'];
        }
        if (trim($oid) === '') {
            return ['ok' => false, 'value' => null, 'error' => 'No OID configured'];
        }

        $this->configureOutputFormat();

        $target = $port !== 161 ? sprintf('%s:%d', $host, $port) : $host;
        $result = @snmp2_get($target, $community, $oid, $timeoutMicroseconds, $retries);

        if ($result === false) {
            return ['ok' => false, 'value' => null, 'error' => 'SNMP request failed or timed out'];
        }

        return ['ok' => true, 'value' => trim((string) $result), 'error' => null];
    }

    private function configureOutputFormat(): void
    {
        if ($this->configured) {
            return;
        }
        // Plain values, no "TYPE: " prefix and no surrounding quotes to strip.
        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        $this->configured = true;
    }
}
