<?php

declare(strict_types=1);

namespace Tests\Service;

use AnyllmCli\Service\DiffService;
use AnyllmCli\Service\ToolExecutor;
use PHPUnit\Framework\TestCase;

class ToolExecutorTest extends TestCase
{
    private ToolExecutor $toolExecutor;
    private string $testFile;
    private string $testDir;

    protected function setUp(): void
    {
        $this->toolExecutor = new ToolExecutor(new DiffService());
        $this->testDir = getcwd() . '/test_dir';
        $this->testFile = $this->testDir . '/test.txt';

        // Create a directory for testing
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
    }

    public function testExecuteToolsFileWrite()
    {
        $content = "[[FILE:test_dir/test.txt]]hello world[[ENDFILE]]";
        $output = $this->toolExecutor->executeTools($content);

        $this->assertStringContainsString('[File created/updated: test_dir/test.txt]', $output);
        $this->assertFileExists($this->testFile);
        $this->assertEquals('hello world', file_get_contents($this->testFile));
    }

    public function testExecuteToolsFileRead()
    {
        file_put_contents($this->testFile, 'hello reader');
        $content = "[[READ:test_dir/test.txt]]";
        $output = $this->toolExecutor->executeTools($content);

        $this->assertStringContainsString('Content of test_dir/test.txt:', $output);
        $this->assertStringContainsString('hello reader', $output);
    }

    public function testExecuteToolsLs()
    {
        file_put_contents($this->testFile, 'dummy');
        $content = "[[LS:test_dir]]";
        $output = $this->toolExecutor->executeTools($content);

        $this->assertStringContainsString('[LS]:', $output);
        $this->assertStringContainsString('test.txt', $output);
    }
}
