<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use NStructure\Infrastructure\Metrics\SeriesStateTracker;
use PHPUnit\Framework\TestCase;

final class SeriesStateTrackerTest extends TestCase
{
    public function testFirstReadingIsAlwaysSent(): void
    {
        $tracker = new SeriesStateTracker();
        self::assertTrue($tracker->shouldSend('temp:1', 23.5, 0.2, 3600, 1000));
    }

    public function testWithinHysteresisIsSuppressedUntilKeepalive(): void
    {
        $tracker = new SeriesStateTracker();
        $tracker->recordSent('temp:1', 23.5, 1000);

        // Small change within the 0.2 hysteresis band, well before keepalive.
        self::assertFalse($tracker->shouldSend('temp:1', 23.6, 0.2, 3600, 1100));
    }

    public function testChangeBeyondHysteresisIsSent(): void
    {
        $tracker = new SeriesStateTracker();
        $tracker->recordSent('temp:1', 23.5, 1000);

        self::assertTrue($tracker->shouldSend('temp:1', 24.0, 0.2, 3600, 1100));
    }

    public function testKeepaliveFiresEvenWithoutChange(): void
    {
        $tracker = new SeriesStateTracker();
        $tracker->recordSent('temp:1', 23.5, 1000);

        // Same value, but far beyond the keepalive window (jitter is at most 300s by default).
        self::assertTrue($tracker->shouldSend('temp:1', 23.5, 0.2, 3600, 1000 + 3600 + 301));
    }

    public function testZeroHysteresisRequiresExactEquality(): void
    {
        $tracker = new SeriesStateTracker();
        $tracker->recordSent('ping_up:1', 1.0, 1000);

        self::assertTrue($tracker->shouldSend('ping_up:1', 0.0, 0.0, 3600, 1001));
        self::assertFalse($tracker->shouldSend('ping_up:1', 1.0, 0.0, 3600, 1001));
    }

    public function testIndependentSeriesDoNotAffectEachOther(): void
    {
        $tracker = new SeriesStateTracker();
        $tracker->recordSent('temp:1', 23.5, 1000);

        self::assertTrue($tracker->shouldSend('temp:2', 23.5, 0.2, 3600, 1001));
    }
}
