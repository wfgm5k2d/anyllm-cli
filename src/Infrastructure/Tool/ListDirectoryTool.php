<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class ListDirectoryTool implements ToolInterface
{
    public function getName(): string
    {
        return 'list_directory';
    }

    public function getDescription(): string
    {
        return 'Lists the contents of a specified directory.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'The relative path to the directory to list. Defaults to the current directory.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '.';

        $fullPath = realpath(getcwd() . DIRECTORY_SEPARATOR . $path);

        if ($fullPath === false || strpos($fullPath, getcwd()) !== 0) {
            return "Error: Path is invalid or outside the current working directory.";
        }

        if (!is_dir($fullPath)) {
            return "Error: Path is not a directory: {$path}";
        }

        $command = 'ls -F ' . escapeshellarg($fullPath);
        $result = shell_exec($command);

        // shell_exec can return null on error or if the command produces no output.
        // For an empty directory, no output is valid.
        if ($result === null) {
            $result = '';
        }

        return "Contents of {$path}:\n" . $result;
    }
}
