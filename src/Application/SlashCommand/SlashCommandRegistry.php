<?php

declare(strict_types=1);

namespace AnyllmCli\Application\SlashCommand;

use AnyllmCli\Domain\SlashCommand\SlashCommandInterface;

class SlashCommandRegistry
{
    /**
     * @var SlashCommandInterface[]
     */
    private array $commands = [];

    public function register(SlashCommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    public function find(string $name): ?SlashCommandInterface
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * @return SlashCommandInterface[]
     */
    public function getAllCommands(): array
    {
        return $this->commands;
    }
}
