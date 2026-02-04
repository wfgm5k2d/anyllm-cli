<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Infrastructure\Tool;

use AnyllmCli\Infrastructure\Tool\ExecuteShellCommandTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExecuteShellCommandToolTest extends TestCase
{
    private ExecuteShellCommandTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ExecuteShellCommandTool();
    }

    public function testGetNameAndDescription(): void
    {
        $this->assertSame('execute_shell_command', $this->tool->getName());
        $this->assertIsString($this->tool->getDescription());
    }

    public function testExecuteSuccessfullyRunsCommand(): void
    {
        $resultJson = $this->tool->execute(['command' => 'echo "hello world"']);
        $result = json_decode($resultJson, true);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame("hello world\n", $result['stdout']);
        $this->assertEmpty($result['stderr']);
        $this->assertStringContainsString('executed successfully', $result['summary']);
    }

    public function testExecuteCapturesStderr(): void
    {
        // 'ls' to a nonexistent directory should write to stderr and return a non-zero exit code.
        $resultJson = $this->tool->execute(['command' => 'ls ' . uniqid('nonexistent_')]);
        $result = json_decode($resultJson, true);

        $this->assertIsArray($result);
        $this->assertNotSame(0, $result['exit_code']);
        $this->assertEmpty($result['stdout']);
        $this->assertNotEmpty($result['stderr']);
        $this->assertStringContainsString('failed with exit code', $result['summary']);
    }

    public function testExecuteFailsWithNoCommand(): void
    {
        $resultJson = $this->tool->execute([]);
        $result = json_decode($resultJson, true);
        $this->assertSame('Error: Command is required.', $result['summary']);
    }

    #[DataProvider('dangerousCommandsProvider')]
    public function testExecuteBlocksDangerousCommands(string $command): void
    {
        $resultJson = $this->tool->execute(['command' => $command]);
        $result = json_decode($resultJson, true);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('is blocked for security reasons', $result['stderr']);
        $this->assertStringContainsString('is blocked for security reasons', $result['summary']);
    }

    public static function dangerousCommandsProvider(): array
    {
        return [
            'rm' => ['rm -rf /'],
            'mv' => ['mv file1 file2'],
            'sudo' => ['sudo ls'],
            'su' => ['su - root'],
        ];
    }
}
