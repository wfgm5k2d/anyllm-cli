<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Tool;

use AnyllmCli\Domain\Tool\ToolInterface;

class ExecuteShellCommandTool implements ToolInterface
{
    private const int MAX_OUTPUT_LENGTH = 2000;

    public function getName(): string
    {
        return 'execute_shell_command';
    }

    public function getDescription(): string
    {
        return 'Executes a shell command in the current working directory and returns its output. CRITICAL: Do not use this for reading/writing files, use read_file/write_file instead.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The shell command to execute.',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $arguments): string
    {
        $command = $arguments['command'] ?? '';
        if (!$command) {
            return json_encode([
                'command' => '',
                'stdout' => '',
                'stderr' => 'Error: Command is required.',
                'exit_code' => -1,
                'summary' => 'Error: Command is required.',
            ]);
        }

        // Basic security check
        $blocked_commands = ['rm', 'mv', 'sudo', 'su'];
        $command_parts = explode(' ', $command);
        if (in_array($command_parts[0], $blocked_commands)) {
             return json_encode([
                'command' => $command,
                'stdout' => '',
                'stderr' => "Error: The command '{$command_parts[0]}' is blocked for security reasons.",
                'exit_code' => -1,
                'summary' => "Error: The command '{$command_parts[0]}' is blocked for security reasons.",
            ]);
        }

        $descriptorSpec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"], // stderr
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, getcwd());

        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        if (is_resource($process)) {
            fclose($pipes[0]); // We don't send anything to stdin

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
        }

        $truncatedStdout = $this->truncate($stdout);
        $truncatedStderr = $this->truncate($stderr);

        $summary = $this->createSummary($command, $truncatedStdout, $truncatedStderr, $exitCode);

        return json_encode([
            'command' => $command,
            'stdout' => $truncatedStdout,
            'stderr' => $truncatedStderr,
            'exit_code' => $exitCode,
            'summary' => $summary,
        ]);
    }

    private function truncate(?string $output): string
    {
        if ($output === null) {
            return '';
        }
        if (mb_strlen($output) > self::MAX_OUTPUT_LENGTH) {
            return mb_substr($output, 0, self::MAX_OUTPUT_LENGTH) . "\n[...output truncated...]\n";
        }
        return $output;
    }

    private function createSummary(string $command, string $stdout, string $stderr, int $exitCode): string
    {
        if ($exitCode === 0) {
            $summary = "Command `{$command}` executed successfully.";
            if (!empty($stdout)) {
                $summary .= "\nSTDOUT:\n" . $stdout;
            } else {
                $summary .= " No output on STDOUT.";
            }
        } else {
            $summary = "Command `{$command}` failed with exit code {$exitCode}.";
            if (!empty($stderr)) {
                $summary .= "\nSTDERR:\n" . $stderr;
            }
        }
        return $summary;
    }
}
