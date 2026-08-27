<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use NStructure\Infrastructure\Metrics\MetricsBuffer;
use NStructure\Infrastructure\Metrics\VictoriaMetricsClient;
use PHPUnit\Framework\TestCase;

final class MetricsBufferTest extends TestCase
{
    public function testFlushOnEmptyBufferSucceedsWithoutCallingClient(): void
    {
        $buffer = new MetricsBuffer();
        $client = new VictoriaMetricsClient('http://127.0.0.1:1'); // unreachable on purpose
        self::assertTrue($buffer->flush($client));
    }

    public function testAddSplitsMultilineBlocksIntoIndividualLines(): void
    {
        $buffer = new MetricsBuffer();
        $buffer->add("a value=1 1\nb value=2 2\nc value=3 3");
        self::assertSame(3, $buffer->count());
    }

    public function testOverCapacityDropsOldestLines(): void
    {
        $buffer = new MetricsBuffer(maxLines: 2);
        $buffer->add("first value=1 1\nsecond value=2 2\nthird value=3 3");
        self::assertSame(2, $buffer->count());
    }

    public function testFailedFlushKeepsLinesBuffered(): void
    {
        $buffer = new MetricsBuffer();
        $buffer->add('m value=1 1');
        $client = new VictoriaMetricsClient('http://127.0.0.1:1');

        self::assertFalse($buffer->flush($client));
        self::assertSame(1, $buffer->count());
    }
}
