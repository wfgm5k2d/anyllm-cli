<?php

declare(strict_types=1);

namespace AnyllmCli\Application\SlashCommand;

use AnyllmCli\Domain\SlashCommand\SlashCommandInterface;
use AnyllmCli\Application\RunCommand;
use AnyllmCli\Infrastructure\Terminal\Style;

class ExitCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return '/exit';
    }

    public function getDescription(): string
    {
        return 'Exit the application.';
    }

    public function execute(array $args, RunCommand $mainApp): void
    {
        echo Style::GRAY . "Goodbye." . Style::RESET . PHP_EOL;
        // The main loop will check for this command name and break.
        // This is a special case. A better way would be to return a status.
        // For now, the main loop will have a hardcoded check.
        exit(0);
    }
}
