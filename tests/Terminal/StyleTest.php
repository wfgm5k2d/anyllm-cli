<?php

declare(strict_types=1);

namespace Tests\Terminal;

use AnyllmCli\Terminal\Style;
use PHPUnit\Framework\TestCase;

class StyleTest extends TestCase
{
    public function testInfo()
    {
        $this->expectOutputString(Style::GRAY . "• test" . Style::RESET . PHP_EOL);
        Style::info('test');
    }

    public function testTool()
    {
        $this->expectOutputString(Style::YELLOW . "🛠  test" . Style::RESET . PHP_EOL);
        Style::tool('test');
    }

    public function testError()
    {
        $this->expectOutputString(Style::RED . "Error: test" . Style::RESET . PHP_EOL);
        Style::error('test');
    }

    public function testSuccess()
    {
        $this->expectOutputString(Style::GREEN . "✓ " . "test" . Style::RESET . PHP_EOL);
        Style::success('test');
    }
}
