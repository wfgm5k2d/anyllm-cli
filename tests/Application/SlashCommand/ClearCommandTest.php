<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Application\SlashCommand;

use AnyllmCli\Application\RunCommand;
use AnyllmCli\Application\SlashCommand\ClearCommand;
use PHPUnit\Framework\TestCase;

class ClearCommandTest extends TestCase
{
    public function testExecuteClearsScreenOnlyWhenSessionIsOff(): void
    {
        $mainAppMock = $this->createMock(RunCommand::class);
        $mainAppMock->method('isSessionMode')->willReturn(false);
        $mainAppMock->expects($this->never())->method('resetSessionContext');

        $command = new ClearCommand();

        ob_start();
        $command->execute([], $mainAppMock);
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[2J\033[H", $output);
        $this->assertStringNotContainsString("Session context has been cleared.", $output);
    }

    public function testExecuteClearsSessionWhenSessionIsOn(): void
    {
        $mainAppMock = $this->createMock(RunCommand::class);
        $mainAppMock->method('isSessionMode')->willReturn(true);
        $mainAppMock->expects($this->once())->method('resetSessionContext');

        $command = new ClearCommand();

        ob_start();
        $command->execute([], $mainAppMock);
        $output = ob_get_clean();

        $this->assertStringContainsString("\033[2J\033[H", $output);
        $this->assertStringContainsString("Session context has been cleared.", $output);
    }

    public function testGetNameAndDescription(): void
    {
        $command = new ClearCommand();
        $this->assertSame('/clear', $command->getName());
        $this->assertSame('Clear the terminal screen.', $command->getDescription());
    }
}
