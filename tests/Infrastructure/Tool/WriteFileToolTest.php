<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Infrastructure\Tool;

use AnyllmCli\Infrastructure\Tool\WriteFileTool;
use PHPUnit\Framework\TestCase;

class WriteFileToolTest extends TestCase
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
        $tool = new WriteFileTool();
        $this->assertSame('write_file', $tool->getName());
        $this->assertIsString($tool->getDescription());
    }

    public function testExecuteSuccessfullyWritesToFile(): void
    {
        $tool = new WriteFileTool();
        $path = 'test.txt';
        $content = 'Hello, world!';

        $result = $tool->execute(['path' => $path, 'content' => $content]);

        $this->assertFileExists($path);
        $this->assertSame($content, file_get_contents($path));
        $this->assertStringContainsString("Successfully wrote", $result);
    }

    public function testExecuteOverwritesExistingFile(): void
    {
        $tool = new WriteFileTool();
        $path = 'overwrite.txt';
        file_put_contents($path, 'initial content');

        $newContent = 'new content';
        $tool->execute(['path' => $path, 'content' => $newContent]);

        $this->assertSame($newContent, file_get_contents($path));
    }

    public function testExecuteCreatesDirectory(): void
    {
        $tool = new WriteFileTool();
        $path = 'new_dir/another_dir/test.txt';
        $content = 'deep file';

        $tool->execute(['path' => $path, 'content' => $content]);

        $this->assertFileExists($path);
        $this->assertTrue(is_dir('new_dir/another_dir'));
        $this->assertSame($content, file_get_contents($path));
    }

    public function testExecuteFailsWithNoPath(): void
    {
        $tool = new WriteFileTool();
        $result = $tool->execute(['content' => 'some content']);
        $this->assertSame('Error: Path is required.', $result);
    }

    public function testExecuteFailsForPathOutsideWorkingDirectory(): void
    {
        $tool = new WriteFileTool();
        // Note: The tool's check is based on realpath and getcwd().
        // Since we chdir into the temp dir, '..' will resolve outside it.
        $result = $tool->execute(['path' => '../test.txt', 'content' => 'invalid']);
        $this->assertSame('Error: Path is outside the current working directory.', $result);
    }
}
