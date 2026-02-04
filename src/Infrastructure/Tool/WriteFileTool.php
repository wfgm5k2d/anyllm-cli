<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class WriteFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Writes content to a specified file. This will create the file if it does not exist, or completely overwrite it if it does.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'The relative path to the file that needs to be written to.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to write into the file.',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        $content = $arguments['content'] ?? '';

        if (!$path) {
            return "Error: Path is required.";
        }

        $baseDir = getcwd();
        $fullPathUnresolved = $baseDir . DIRECTORY_SEPARATOR . $path;

        // Create the directory first to ensure realpath works
        $dir = dirname($fullPathUnresolved);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                return "Error: Failed to create directory: {$dir}";
            }
        }

        // Resolve the real path and check if it's within the CWD
        $realBaseDir = realpath($baseDir);
        $realFullPath = realpath($dir);

        if ($realFullPath === false || strpos($realFullPath, $realBaseDir) !== 0) {
            return "Error: Path is outside the current working directory.";
        }

        if (file_put_contents($fullPathUnresolved, $content) === false) {
            return "Error: Failed to write to file: {$path}";
        }

        return "Successfully wrote " . strlen($content) . " bytes to {$path}";
    }
}
