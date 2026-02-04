<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Infrastructure\Tool;

use AnyllmCli\Infrastructure\Tool\ReadFileTool;
use PHPUnit\Framework\TestCase;

class ReadFileToolTest extends TestCase
{
    private string $tempDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/anyllm_tests_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testGetNameAndDescription(): void
    {
        $tool = new ReadFileTool();
        $this->assertSame('read_file', $tool->getName());
        $this->assertIsString($tool->getDescription());
    }

    public function testExecuteSuccessfullyReadsFile(): void
    {
        $tool = new ReadFileTool();
        $path = 'readable.txt';
        $content = 'This is a test.';
        file_put_contents($path, $content);

        $result = $tool->execute(['path' => $path]);

        $this->assertSame($content, $result);
    }

    public function testExecuteFailsForNonExistentFile(): void
    {
        $tool = new ReadFileTool();
        $result = $tool->execute(['path' => 'nonexistent.txt']);
        $this->assertSame('Error: File not found at path: nonexistent.txt', $result);
    }

    public function testExecuteFailsForDirectory(): void
    {
        $tool = new ReadFileTool();
        $path = 'a_directory';
        mkdir($path);
        $result = $tool->execute(['path' => $path]);
        $this->assertSame('Error: Path is a directory, not a file: a_directory', $result);
    }

    public function testExecuteFailsWithNoPath(): void
    {
        $tool = new ReadFileTool();
        $result = $tool->execute([]);
        $this->assertSame('Error: Path is required.', $result);
    }

    public function testExecuteFailsForPathOutsideWorkingDirectory(): void
    {
        $tool = new ReadFileTool();
        $result = $tool->execute(['path' => '../test.txt']);
        $this->assertSame('Error: Path is outside the current working directory.', $result);
    }
}
