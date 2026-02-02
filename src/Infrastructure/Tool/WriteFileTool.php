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

        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $path;

        if (strpos($fullPath, getcwd()) !== 0) {
            return "Error: Path is outside the current working directory.";
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                return "Error: Failed to create directory: {$dir}";
            }
        }

        if (file_put_contents($fullPath, $content) === false) {
            return "Error: Failed to write to file: {$path}";
        }

        return "Successfully wrote " . strlen($content) . " bytes to {$path}";
    }
}
