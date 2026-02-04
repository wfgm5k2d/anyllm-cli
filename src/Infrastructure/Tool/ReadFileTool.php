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

        $baseDir = getcwd();
        $fullPathUnresolved = $baseDir . DIRECTORY_SEPARATOR . $path;

        // Resolve the real path and check if it's within the CWD
        $realBaseDir = realpath($baseDir);
        $realFullPath = realpath($fullPathUnresolved);

        if ($realFullPath === false || strpos($realFullPath, $realBaseDir) !== 0) {
            // Check for directory traversal even if file doesn't exist yet for the check
            if (strpos($path, '..') !== false) {
                 return "Error: Path is outside the current working directory.";
            }
             return "Error: File not found at path: {$path}";
        }

        if (is_dir($realFullPath)) {
            return "Error: Path is a directory, not a file: {$path}";
        }

        return file_get_contents($realFullPath);
    }
}
