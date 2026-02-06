<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Application\SlashCommand;

use AnyllmCli\Application\RunCommand;
use AnyllmCli\Application\SlashCommand\ModelsCommand;
use PHPUnit\Framework\TestCase;

class ModelsCommandTest extends TestCase
{
    public function testExecuteCallsSwitchModelOnMainApp(): void
    {
        // Create a mock of the main RunCommand application class
        $runCommandMock = $this->createMock(RunCommand::class);

        // We expect the `switchModel` method to be called exactly once
        $runCommandMock->expects($this->once())
            ->method('switchModel');

        // Instantiate the command we are testing
        $modelsCommand = new ModelsCommand();

        // Execute the command, passing the mock application object
        $modelsCommand->execute([], $runCommandMock);
    }
}
