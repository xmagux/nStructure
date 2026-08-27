<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use NStructure\Infrastructure\Metrics\InfluxLineProtocolBuilder;
use PHPUnit\Framework\TestCase;

final class InfluxLineProtocolBuilderTest extends TestCase
{
    public function testEmptyBuilderProducesNoOutput(): void
    {
        $builder = new InfluxLineProtocolBuilder();
        self::assertTrue($builder->isEmpty());
        self::assertSame('', $builder->build());
    }

    public function testAddPointFormatsALine(): void
    {
        $builder = new InfluxLineProtocolBuilder();
        $builder->addPoint('sensor_temperature_celsius', ['sensor_id' => '1', 'sensor' => 'Server Room'], 23.5, 1_700_000_000_000);

        self::assertFalse($builder->isEmpty());
        self::assertSame(
            'sensor_temperature_celsius,sensor_id=1,sensor=Server\ Room value=23.5 1700000000000000000',
            $builder->build(),
        );
    }

    public function testIntegerValuedFloatHasNoTrailingDecimal(): void
    {
        $builder = new InfluxLineProtocolBuilder();
        $builder->addPoint('sensor_ping_up', ['sensor_id' => '1'], 1.0, 1_700_000_000_000);

        self::assertSame('sensor_ping_up,sensor_id=1 value=1 1700000000000000000', $builder->build());
    }

    public function testMultiplePointsAreNewlineSeparated(): void
    {
        $builder = new InfluxLineProtocolBuilder();
        $builder->addPoint('a', ['x' => '1'], 1.0, 1000);
        $builder->addPoint('b', ['x' => '2'], 2.0, 2000);

        self::assertSame(
            "a,x=1 value=1 1000000000\nb,x=2 value=2 2000000000",
            $builder->build(),
        );
    }

    public function testTagValuesWithCommasAndEqualsAreEscaped(): void
    {
        $builder = new InfluxLineProtocolBuilder();
        $builder->addPoint('m', ['sensor' => 'Rack=A,Row 1'], 5.0, 0);

        self::assertSame('m,sensor=Rack\=A\,Row\ 1 value=5 0', $builder->build());
    }
}
