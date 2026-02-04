<?php

declare(strict_types=1);

namespace AnyllmCli\Application\SlashCommand;

use AnyllmCli\Domain\SlashCommand\SlashCommandInterface;
use AnyllmCli\Application\RunCommand;
use AnyllmCli\Infrastructure\Terminal\Style;

class ClearCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return '/clear';
    }

    public function getDescription(): string
    {
        return 'Clear the terminal screen.';
    }

    public function execute(array $args, RunCommand $mainApp): void
    {
        // Clear screen first
        echo "\033[2J\033[H";
        Style::banner();

        // Check if session mode is on and reset if it is
        if ($mainApp->isSessionMode()) {
            $mainApp->resetSessionContext();
            Style::info("Session context has been cleared.");
        }
    }
}
