<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\SlashCommand;

use AnyllmCli\Application\RunCommand;

interface SlashCommandInterface
{
    /**
     * The name of the command, e.g., "/summarize".
     */
    public function getName(): string;

    /**
     * A short description shown in the TUI.
     */
    public function getDescription(): string;

    /**
     * Executes the command's logic.
     * @param array $args The arguments passed after the command name.
     * @param RunCommand $mainApp A reference to the main application to access dependencies.
     */
    public function execute(array $args, RunCommand $mainApp): void;
}
