<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Domain\Api;

use AnyllmCli\Domain\Api\UsageStats;
use PHPUnit\Framework\TestCase;

class UsageStatsTest extends TestCase
{
    public function testConstructorInitializesProperties(): void
    {
        $usage = new UsageStats(100, 200);

        $this->assertSame(100, $usage->promptTokens);
        $this->assertSame(200, $usage->completionTokens);
        $this->assertSame(300, $usage->totalTokens);
    }

    public function testAddMethodAggregatesStats(): void
    {
        $usage1 = new UsageStats(10, 20);
        $usage2 = new UsageStats(5, 15);

        $usage1->add($usage2);

        $this->assertSame(15, $usage1->promptTokens);
        $this->assertSame(35, $usage1->completionTokens);
        $this->assertSame(50, $usage1->totalTokens);
    }

    public function testConstructorWithDefaults(): void
    {
        $usage = new UsageStats();

        $this->assertSame(0, $usage->promptTokens);
        $this->assertSame(0, $usage->completionTokens);
        $this->assertSame(0, $usage->totalTokens);
    }
}
