<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Service;

class ProjectIdentifierService
{
    private string $projectRoot;

    private const array ENTRY_POINTS = [
        'index.php', 'main.py', 'app.py', 'index.js', 'main.js', 'server.js',
        'main.go', 'main.c', 'main.rs', 'Program.cs', 'main.ts', 'app.ts',
        'main.java', 'Main.java', 'app.php',
    ];

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * Identifies the project's name, path, and entry point.
     *
     * @return array{name: string, path: string, entry_point: ?string}
     */
    public function identify(): array
    {
        $path = $this->projectRoot;
        $name = basename($path);
        $entryPoint = $this->findEntryPoint();

        return [
            'name' => $name,
            'path' => $path,
            'entry_point' => $entryPoint,
        ];
    }

    private function findEntryPoint(): ?string
    {
        return array_find(
            self::ENTRY_POINTS,
            fn($filename) => file_exists($this->projectRoot . DIRECTORY_SEPARATOR . $filename)
        );
    }
}
