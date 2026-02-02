<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class SearchFileContentTool implements ToolInterface
{
    public function getName(): string
    {
        return 'search_content';
    }

    public function getDescription(): string
    {
        return 'Searches for a specific string within files in the current directory and its subdirectories.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The string or pattern to search for.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments): string
    {
        $query = $arguments['query'] ?? '';
        if (!$query) {
            return "Error: Search query is required.";
        }

        // Using -rnI to search recursively, with line numbers, and ignoring binary files.
        // --exclude-dir is used to avoid searching in common dependency/VCS directories.
        $command = "grep -rnI --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules " . escapeshellarg($query) . " .";
        $result = shell_exec($command);

        if ($result === null) {
            return "Error: Failed to execute 'grep' command.";
        }

        if (empty($result)) {
            return "No matches found for '{$query}'.";
        }

        return "Search results for '{$query}':\n" . $result;
    }
}
