<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use NStructure\Infrastructure\Heartbeat\HeartbeatStore;
use PHPUnit\Framework\TestCase;

final class HeartbeatStoreTest extends TestCase
{
    private string $filePath;

    protected function setUp(): void
    {
        $this->filePath = sys_get_temp_dir() . '/nstructure-heartbeat-test-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->filePath);
    }

    public function testNoFileMeansNoActiveSensors(): void
    {
        $store = new HeartbeatStore($this->filePath);
        self::assertSame([], $store->activeSensorIds());
    }

    public function testTouchedSensorIsActive(): void
    {
        $store = new HeartbeatStore($this->filePath);
        $store->touch(42, ttlSeconds: 15);

        self::assertSame([42], $store->activeSensorIds());
    }

    public function testExpiredEntryIsNotActive(): void
    {
        $store = new HeartbeatStore($this->filePath);
        $store->touch(7, ttlSeconds: -1); // already expired

        self::assertSame([], $store->activeSensorIds());
    }

    public function testMultipleSensorsAreTrackedIndependently(): void
    {
        $store = new HeartbeatStore($this->filePath);
        $store->touch(1, ttlSeconds: 15);
        $store->touch(2, ttlSeconds: 15);

        $active = $store->activeSensorIds();
        sort($active);
        self::assertSame([1, 2], $active);
    }

    public function testWriteIsAtomicViaRenameAndLeavesNoTempFiles(): void
    {
        $store = new HeartbeatStore($this->filePath);
        $store->touch(1, ttlSeconds: 15);

        $directory = dirname($this->filePath);
        $leftoverTempFiles = glob($directory . '/.' . basename($this->filePath) . '.*.tmp') ?: [];
        self::assertSame([], $leftoverTempFiles);
        self::assertFileExists($this->filePath);
    }
}
