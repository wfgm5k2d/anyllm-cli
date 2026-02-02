<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class ReadFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Reads the entire content of a specified file and returns it as a string.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'The relative path to the file that needs to be read.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        if (!$path) {
            return "Error: Path is required.";
        }

        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $path;

        if (strpos($fullPath, getcwd()) !== 0) {
            return "Error: Path is outside the current working directory.";
        }

        if (!file_exists($fullPath)) {
            return "Error: File not found at path: {$path}";
        }

        if (is_dir($fullPath)) {
            return "Error: Path is a directory, not a file: {$path}";
        }

        return file_get_contents($fullPath);
    }
}
