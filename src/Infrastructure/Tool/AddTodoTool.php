<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class AddTodoTool implements ToolInterface
{
    public function getName(): string
    {
        return 'add_todo';
    }

    public function getDescription(): string
    {
        return 'Adds a new task to the to-do list for the current session.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'description' => 'The description of the task to add.',
                ],
            ],
            'required' => ['text'],
        ];
    }

    public function execute(array $arguments): string
    {
        // This tool is handled internally by the agent.
        // This method should not be called directly.
        return "Error: This tool must be handled by the agent's internal logic.";
    }
}
