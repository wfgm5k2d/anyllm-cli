<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Infrastructure\Tool;

use AnyllmCli\Infrastructure\Tool\ListDirectoryTool;
use PHPUnit\Framework\TestCase;

class ListDirectoryToolTest extends TestCase
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
        $tool = new ListDirectoryTool();
        $this->assertSame('list_directory', $tool->getName());
        $this->assertIsString($tool->getDescription());
    }

    public function testExecuteSuccessfullyListsContents(): void
    {
        $tool = new ListDirectoryTool();
        mkdir('subdir');
        file_put_contents('file1.txt', 'content');
        file_put_contents('subdir/file2.txt', 'content');

        $result = $tool->execute(['path' => '.']);

        $this->assertStringContainsString('Contents of .:', $result);
        $this->assertStringContainsString('file1.txt', $result);
        // ls -F appends a slash to directories
        $this->assertStringContainsString('subdir/', $result);
    }

    public function testExecuteHandlesEmptyDirectory(): void
    {
        $tool = new ListDirectoryTool();
        $result = $tool->execute(['path' => '.']);
        $this->assertStringContainsString('Contents of .:', $result);
    }

    public function testExecuteFailsForNonExistentPath(): void
    {
        $tool = new ListDirectoryTool();
        $result = $tool->execute(['path' => 'nonexistent_dir']);
        $this->assertSame('Error: Path is invalid or outside the current working directory.', $result);
    }

    public function testExecuteFailsForFilePath(): void
    {
        $tool = new ListDirectoryTool();
        $path = 'a_file.txt';
        file_put_contents($path, 'content');
        $result = $tool->execute(['path' => $path]);
        $this->assertSame('Error: Path is not a directory: a_file.txt', $result);
    }

    public function testExecuteFailsForPathOutsideWorkingDirectory(): void
    {
        $tool = new ListDirectoryTool();
        $result = $tool->execute(['path' => '../']);
        $this->assertSame('Error: Path is invalid or outside the current working directory.', $result);
    }
}
