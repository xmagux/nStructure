<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use NStructure\Infrastructure\Snmp\SnmpClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SnmpClientTest extends TestCase
{
    private function invokePrivate(SnmpClient $client, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass($client);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);
        return $methodReflection->invokeArgs($client, $arguments);
    }

    public function testEncodeGetRequestProducesExpectedBerStructure(): void
    {
        $client = new SnmpClient();
        $packet = $this->invokePrivate($client, 'encodeGetRequest', ['public', '1.3.6.1.2.1.1.1.0', 12345]);

        self::assertSame(0x30, ord($packet[0]), 'Packet must start with a SEQUENCE tag');
        self::assertStringContainsString('public', $packet, 'Community string must be present verbatim');
        self::assertStringContainsString("\xA0", $packet, 'PDU must use the GetRequest tag');
        // OID 1.3.6.1.2.1.1.1.0 (sysDescr.0) encodes to 2B 06 01 02 01 01 01 00
        self::assertStringContainsString("\x2B\x06\x01\x02\x01\x01\x01\x00", $packet);
    }

    public function testEncodeOidHandlesMultiByteComponents(): void
    {
        $client = new SnmpClient();
        // The HW group enterprise number 21796 requires 3 base-128 bytes (0x81 0xAA 0x24).
        $packet = $this->invokePrivate($client, 'encodeGetRequest', ['public', '1.3.6.1.4.1.21796.4.1.3.1.4.1', 1]);

        self::assertStringContainsString("\x2B\x06\x01\x04\x01\x81\xAA\x24\x04\x01\x03\x01\x04\x01", $packet);
    }

    public function testDecodeGetResponseParsesIntegerValue(): void
    {
        // Hand-built SNMPv1 GetResponse for OID 1.3.6.1.4.1.21796.4.1.3.1.4.1 => INTEGER 235,
        // request-id 12345, community "public".
        $response = "\x30\x2F"
            . "\x02\x01\x00"
            . "\x04\x06public"
            . "\xA2\x22"
            . "\x02\x02\x30\x39"
            . "\x02\x01\x00"
            . "\x02\x01\x00"
            . "\x30\x16"
            . "\x30\x14"
            . "\x06\x0E\x2B\x06\x01\x04\x01\x81\xAA\x24\x04\x01\x03\x01\x04\x01"
            . "\x02\x02\x00\xEB";

        $client = new SnmpClient();
        $result = $this->invokePrivate($client, 'decodeGetResponse', [$response, 12345]);

        self::assertTrue($result['ok']);
        self::assertSame('235', $result['value']);
        self::assertSame('integer', $result['type']);
        self::assertNull($result['error']);
    }

    public function testDecodeGetResponseRejectsMismatchedRequestId(): void
    {
        $response = "\x30\x2F"
            . "\x02\x01\x00"
            . "\x04\x06public"
            . "\xA2\x22"
            . "\x02\x02\x30\x39"
            . "\x02\x01\x00"
            . "\x02\x01\x00"
            . "\x30\x16"
            . "\x30\x14"
            . "\x06\x0E\x2B\x06\x01\x04\x01\x81\xAA\x24\x04\x01\x03\x01\x04\x01"
            . "\x02\x02\x00\xEB";

        $client = new SnmpClient();
        $result = $this->invokePrivate($client, 'decodeGetResponse', [$response, 999]);

        self::assertFalse($result['ok']);
        self::assertNotNull($result['error']);
    }

    public function testDecodeGetResponseReportsNoSuchObject(): void
    {
        // OID 1.3.6.1.4.1.21796.4.1.1 with a noSuchObject exception value.
        $response = "\x30\x2A"
            . "\x02\x01\x00"
            . "\x04\x06public"
            . "\xA2\x1D"
            . "\x02\x02\x30\x39"
            . "\x02\x01\x00"
            . "\x02\x01\x00"
            . "\x30\x11"
            . "\x30\x0F"
            . "\x06\x0B\x2B\x06\x01\x04\x01\x81\xAA\x24\x04\x01\x01"
            . "\x80\x00";

        $client = new SnmpClient();
        $result = $this->invokePrivate($client, 'decodeGetResponse', [$response, 12345]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('No such object', (string) $result['error']);
    }

    public function testGetReturnsErrorForEmptyOid(): void
    {
        $client = new SnmpClient();
        $result = $client->get('127.0.0.1', 161, 'public', '', 0.2);

        self::assertFalse($result['ok']);
        self::assertSame('No OID configured', $result['error']);
    }
}
