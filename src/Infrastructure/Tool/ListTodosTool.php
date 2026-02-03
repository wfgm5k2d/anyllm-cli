<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class ListTodosTool implements ToolInterface
{
    public function getName(): string
    {
        return 'list_todos';
    }

    public function getDescription(): string
    {
        return 'Lists all tasks in the current to-do list with their statuses.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(), // No parameters, must be an empty object
        ];
    }

    public function execute(array $arguments): string
    {
        // This tool is handled internally by the agent.
        return "Error: This tool must be handled by the agent's internal logic.";
    }
}
