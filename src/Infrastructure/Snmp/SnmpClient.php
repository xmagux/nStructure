<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Snmp;

/**
 * Minimal SNMP v1 GET client using raw BER/ASN.1 encoding over UDP.
 *
 * Written from scratch because neither the php-snmp extension nor the
 * net-snmp CLI tools are guaranteed to be installed on a self-hosted
 * target — this keeps the sensor module dependency-free.
 */
final class SnmpClient
{
    private const TAG_INTEGER = 0x02;
    private const TAG_OCTET_STRING = 0x04;
    private const TAG_NULL = 0x05;
    private const TAG_OID = 0x06;
    private const TAG_SEQUENCE = 0x30;
    private const TAG_GET_REQUEST = 0xA0;
    private const TAG_GET_RESPONSE = 0xA2;
    private const TAG_IP_ADDRESS = 0x40;
    private const TAG_COUNTER32 = 0x41;
    private const TAG_GAUGE32 = 0x42;
    private const TAG_TIME_TICKS = 0x43;
    private const TAG_OPAQUE = 0x44;
    private const TAG_COUNTER64 = 0x46;
    private const TAG_NO_SUCH_OBJECT = 0x80;
    private const TAG_NO_SUCH_INSTANCE = 0x81;
    private const TAG_END_OF_MIB_VIEW = 0x82;

    /**
     * @return array{ok: bool, value: string|null, type: string|null, error: string|null}
     */
    public function get(string $host, int $port, string $community, string $oid, float $timeoutSeconds = 2.0): array
    {
        $oid = trim($oid);
        if ($oid === '') {
            return ['ok' => false, 'value' => null, 'type' => null, 'error' => 'No OID configured'];
        }

        try {
            $requestId = random_int(1, 2_000_000_000);
            $packet = $this->encodeGetRequest($community, $oid, $requestId);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'value' => null, 'type' => null, 'error' => 'Invalid OID: ' . $exception->getMessage()];
        }

        $socket = @stream_socket_client(
            sprintf('udp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeoutSeconds,
        );
        if ($socket === false) {
            return ['ok' => false, 'value' => null, 'type' => null, 'error' => trim($errstr) ?: 'Could not open socket'];
        }

        stream_set_timeout($socket, (int) floor($timeoutSeconds), (int) round(($timeoutSeconds - floor($timeoutSeconds)) * 1_000_000));

        try {
            if (@fwrite($socket, $packet) === false) {
                return ['ok' => false, 'value' => null, 'type' => null, 'error' => 'Could not send SNMP request'];
            }

            $response = @fread($socket, 4096);
            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out'] || $response === false || $response === '') {
                return ['ok' => false, 'value' => null, 'type' => null, 'error' => 'No response (timeout)'];
            }
        } finally {
            fclose($socket);
        }

        try {
            return $this->decodeGetResponse($response, $requestId);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'value' => null, 'type' => null, 'error' => 'Malformed SNMP response: ' . $exception->getMessage()];
        }
    }

    private function encodeGetRequest(string $community, string $oid, int $requestId): string
    {
        $varBind = $this->encodeSequence($this->encodeOid($oid) . $this->encodeNull());
        $varBindList = $this->encodeSequence($varBind);
        $pdu = $this->encodeInteger($requestId) . $this->encodeInteger(0) . $this->encodeInteger(0) . $varBindList;
        $getRequest = $this->encodeTlv(self::TAG_GET_REQUEST, $pdu);
        $message = $this->encodeInteger(0) . $this->encodeOctetString($community) . $getRequest;

        return $this->encodeSequence($message);
    }

    /**
     * @return array{ok: bool, value: string|null, type: string|null, error: string|null}
     */
    private function decodeGetResponse(string $data, int $expectedRequestId): array
    {
        $offset = 0;
        [$tag, $content] = $this->readTlv($data, $offset);
        if ($tag !== self::TAG_SEQUENCE) {
            throw new \RuntimeException('Expected top-level SEQUENCE');
        }

        $pos = 0;
        $this->readTlv($content, $pos); // version
        $this->readTlv($content, $pos); // community
        [$pduTag, $pdu] = $this->readTlv($content, $pos);
        if ($pduTag !== self::TAG_GET_RESPONSE) {
            return ['ok' => false, 'value' => null, 'type' => null, 'error' => 'Unexpected PDU type in response'];
        }

        $pduPos = 0;
        [, $requestIdRaw] = $this->readTlv($pdu, $pduPos);
        [, $errorStatusRaw] = $this->readTlv($pdu, $pduPos);
        [, $errorIndexRaw] = $this->readTlv($pdu, $pduPos);
        [, $varBindList] = $this->readTlv($pdu, $pduPos);

        $requestId = $this->decodeInteger($requestIdRaw);
        if ($requestId !== $expectedRequestId) {
            return ['ok' => false, 'value' => null, 'type' => null, 'error' => 'Response request-id mismatch'];
        }

        $errorStatus = $this->decodeInteger($errorStatusRaw);
        if ($errorStatus !== 0) {
            $errorIndex = $this->decodeInteger($errorIndexRaw);
            return ['ok' => false, 'value' => null, 'type' => null, 'error' => sprintf('SNMP error status %d at index %d', $errorStatus, $errorIndex)];
        }

        $vbPos = 0;
        [, $varBind] = $this->readTlv($varBindList, $vbPos);
        $inner = 0;
        $this->readTlv($varBind, $inner); // oid
        [$valueTag, $valueRaw] = $this->readTlv($varBind, $inner);

        return match ($valueTag) {
            self::TAG_INTEGER => ['ok' => true, 'value' => (string) $this->decodeInteger($valueRaw), 'type' => 'integer', 'error' => null],
            self::TAG_OCTET_STRING, self::TAG_OPAQUE => ['ok' => true, 'value' => $this->cleanOctetString($valueRaw), 'type' => 'string', 'error' => null],
            self::TAG_COUNTER32, self::TAG_GAUGE32, self::TAG_TIME_TICKS, self::TAG_COUNTER64 => ['ok' => true, 'value' => (string) $this->decodeUnsignedInteger($valueRaw), 'type' => 'counter', 'error' => null],
            self::TAG_IP_ADDRESS => ['ok' => true, 'value' => implode('.', array_map('ord', str_split($valueRaw))), 'type' => 'ip', 'error' => null],
            self::TAG_NULL => ['ok' => false, 'value' => null, 'type' => null, 'error' => 'Empty value'],
            self::TAG_NO_SUCH_OBJECT => ['ok' => false, 'value' => null, 'type' => null, 'error' => 'No such object (wrong OID?)'],
            self::TAG_NO_SUCH_INSTANCE => ['ok' => false, 'value' => null, 'type' => null, 'error' => 'No such instance (wrong OID index?)'],
            self::TAG_END_OF_MIB_VIEW => ['ok' => false, 'value' => null, 'type' => null, 'error' => 'End of MIB view'],
            default => ['ok' => false, 'value' => null, 'type' => null, 'error' => sprintf('Unsupported value type 0x%02X', $valueTag)],
        };
    }

    private function cleanOctetString(string $raw): string
    {
        $printable = preg_replace('/[^\x20-\x7E]+/', ' ', $raw) ?? $raw;
        return trim($printable);
    }

    // --- BER encoding -----------------------------------------------------

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function encodeTlv(int $tag, string $content): string
    {
        return chr($tag) . $this->encodeLength(strlen($content)) . $content;
    }

    private function encodeSequence(string $content): string
    {
        return $this->encodeTlv(self::TAG_SEQUENCE, $content);
    }

    private function encodeInteger(int $value): string
    {
        if ($value === 0) {
            return $this->encodeTlv(self::TAG_INTEGER, "\x00");
        }
        $negative = $value < 0;
        $bytes = [];
        $remaining = $negative ? ~$value : $value;
        while ($remaining > 0) {
            array_unshift($bytes, $remaining & 0xFF);
            $remaining >>= 8;
        }
        if ($negative) {
            $bytes = array_map(static fn (int $byte): int => (~$byte) & 0xFF, $bytes);
            for ($i = count($bytes) - 1; $i >= 0; $i--) {
                $bytes[$i] = ($bytes[$i] + 1) & 0xFF;
                if ($bytes[$i] !== 0) {
                    break;
                }
            }
            if (($bytes[0] & 0x80) === 0) {
                array_unshift($bytes, 0xFF);
            }
        } elseif (($bytes[0] & 0x80) !== 0) {
            array_unshift($bytes, 0x00);
        }
        return $this->encodeTlv(self::TAG_INTEGER, implode('', array_map('chr', $bytes)));
    }

    private function encodeOctetString(string $value): string
    {
        return $this->encodeTlv(self::TAG_OCTET_STRING, $value);
    }

    private function encodeNull(): string
    {
        return $this->encodeTlv(self::TAG_NULL, '');
    }

    private function encodeOid(string $oid): string
    {
        $parts = array_map('intval', array_filter(explode('.', trim($oid, '.')), static fn (string $p): bool => $p !== ''));
        if (count($parts) < 2) {
            throw new \InvalidArgumentException('OID must have at least two components');
        }
        $first = array_shift($parts);
        $second = array_shift($parts);
        $bytes = chr($first * 40 + $second);
        foreach ($parts as $part) {
            $bytes .= $this->encodeOidComponent($part);
        }
        return $this->encodeTlv(self::TAG_OID, $bytes);
    }

    private function encodeOidComponent(int $value): string
    {
        if ($value < 0x80) {
            return chr($value);
        }
        $chunks = [];
        while ($value > 0) {
            array_unshift($chunks, $value & 0x7F);
            $value >>= 7;
        }
        $encoded = '';
        foreach ($chunks as $index => $chunk) {
            $encoded .= chr($index < count($chunks) - 1 ? ($chunk | 0x80) : $chunk);
        }
        return $encoded;
    }

    // --- BER decoding -------------------------------------------------------

    /**
     * @return array{0: int, 1: string}
     */
    private function readTlv(string $data, int &$offset): array
    {
        if (!isset($data[$offset])) {
            throw new \RuntimeException('Unexpected end of data');
        }
        $tag = ord($data[$offset]);
        $offset++;
        $length = $this->readLength($data, $offset);
        $content = substr($data, $offset, $length);
        if (strlen($content) !== $length) {
            throw new \RuntimeException('Truncated TLV content');
        }
        $offset += $length;
        return [$tag, $content];
    }

    private function readLength(string $data, int &$offset): int
    {
        if (!isset($data[$offset])) {
            throw new \RuntimeException('Unexpected end of data reading length');
        }
        $first = ord($data[$offset]);
        $offset++;
        if (($first & 0x80) === 0) {
            return $first;
        }
        $numBytes = $first & 0x7F;
        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            if (!isset($data[$offset])) {
                throw new \RuntimeException('Unexpected end of data reading long-form length');
            }
            $length = ($length << 8) | ord($data[$offset]);
            $offset++;
        }
        return $length;
    }

    private function decodeInteger(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        $value = ord($bytes[0]) & 0x80 ? -1 : 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }
        return $value;
    }

    private function decodeUnsignedInteger(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }
        return $value;
    }
}
