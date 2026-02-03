<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class MarkTodoDoneTool implements ToolInterface
{
    public function getName(): string
    {
        return 'mark_todo_done';
    }

    public function getDescription(): string
    {
        return 'Marks an existing task as "done".';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'description' => 'The exact text of the task to mark as done.',
                ],
            ],
            'required' => ['text'],
        ];
    }

    public function execute(array $arguments): string
    {
        // This tool is handled internally by the agent.
        return "Error: This tool must be handled by the agent's internal logic.";
    }
}
