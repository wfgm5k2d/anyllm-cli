<?php

declare(strict_types=1);

namespace AnyllmCli\Application\SlashCommand;

use AnyllmCli\Application\RunCommand;
use AnyllmCli\Domain\SlashCommand\SlashCommandInterface;

class ModelsCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return '/models';
    }

    public function getDescription(): string
    {
        return 'Switch the active AI model.';
    }

    public function execute(array $args, RunCommand $mainApp): void
    {
        $mainApp->switchModel();
    }
}
