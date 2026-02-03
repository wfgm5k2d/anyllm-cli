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
        echo "\033[2J\033[H";
        Style::banner();
    }
}
