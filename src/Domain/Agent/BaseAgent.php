<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Agent;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Domain\Tool\ToolRegistryInterface;
use AnyllmCli\Infrastructure\Terminal\DiffRenderer;
use AnyllmCli\Infrastructure\Terminal\Style;

abstract class BaseAgent implements AgentInterface
{
    protected int $maxIterations = 10;

    public function __construct(
        protected ApiClientInterface $apiClient,
        protected ToolRegistryInterface $toolRegistry,
        protected DiffRenderer $diffRenderer,
        string $systemPrompt,
        protected SessionContext $sessionContext
    ) {
        // Ensure system prompt is the first message, and only if history is empty.
        if (empty($this->sessionContext->conversation_history) || $this->sessionContext->conversation_history[0]['role'] !== 'system') {
            array_unshift($this->sessionContext->conversation_history, ['role' => 'system', 'content' => $systemPrompt]);
        }
    }

    public function execute(string $prompt, callable $onProgress): void
    {
        $this->sessionContext->conversation_history[] = ['role' => 'user', 'content' => $prompt];

        $loopCount = 0;
        $keepGoing = true;

        while ($keepGoing && $loopCount < $this->maxIterations) {
            $loopCount++;

            $response = $this->apiClient->chat(
                $this->sessionContext->conversation_history,
                $this->toolRegistry->getToolsAsJsonSchema(),
                $onProgress
            );

            $log = "--- Parsed Response ---\n";
            $log .= "Has Tool Calls: " . ($response->hasToolCalls() ? 'Yes' : 'No') . "\n";
            $log .= "Message Content: " . ($response->getMessageContent() ?? 'N/A') . "\n";
            $log .= "Tool Calls: " . json_encode($response->getToolCalls(), JSON_PRETTY_PRINT) . "\n";
            file_put_contents(getcwd() . '/llm_log.txt', $log . "\n\n", FILE_APPEND);

            if (!$response->hasToolCalls()) {
                // No tool calls, so it's the final answer.
                $keepGoing = false;
                // The onProgress callback already handled the streaming output.
                continue;
            }

            $this->sessionContext->conversation_history[] = $response->getMessage();
            $toolCalls = $response->getToolCalls();

            $onProgress(PHP_EOL);

            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true);

                $log = "--- Tool Execution ---\n";
                $log .= "Tool Name: " . $toolName . "\n";
                $log .= "Arguments: " . json_encode($arguments, JSON_PRETTY_PRINT) . "\n";
                file_put_contents(getcwd() . '/llm_log.txt', $log, FILE_APPEND);

                Style::tool("Using tool: " . Style::BOLD . $toolName . Style::RESET);

                $tool = $this->toolRegistry->getTool($toolName);

                if ($tool) {
                    $toolOutput = "";
                    $toolSummaryForLlm = "";

                    // Special handling for write_file to show a diff
                    if ($toolName === 'write_file') {
                        $path = $arguments['path'] ?? null;
                        $newContent = $arguments['content'] ?? '';
                        $oldContent = '';
                        if ($path && file_exists(getcwd() . DIRECTORY_SEPARATOR . $path)) {
                            $oldContent = file_get_contents(getcwd() . DIRECTORY_SEPARATOR . $path);
                        }

                        $toolOutput = $tool->execute($arguments);
                        $this->diffRenderer->render($oldContent, $newContent);
                        $toolSummaryForLlm = $toolOutput; // For write_file, the output is already a summary

                    } elseif ($toolName === 'execute_shell_command') {
                        $jsonOutput = $tool->execute($arguments);
                        $commandResult = json_decode($jsonOutput, true);

                        $this->updateTerminalContext(
                            $commandResult['command'] ?? $arguments['command'],
                            $commandResult['stdout'],
                            $commandResult['stderr'],
                            $commandResult['exit_code']
                        );

                        // Display output to user
                        if (!empty($commandResult['stdout'])) {
                            echo Style::GRAY . "│ STDOUT: " . trim($commandResult['stdout']) . Style::RESET . PHP_EOL;
                        }
                        if (!empty($commandResult['stderr'])) {
                            echo Style::RED . "│ STDERR: " . trim($commandResult['stderr']) . Style::RESET . PHP_EOL;
                        }

                        $toolSummaryForLlm = $commandResult['summary'];
                        $toolOutput = $jsonOutput; // Keep the full JSON for logging
                    } else {
                        $toolOutput = $tool->execute($arguments);
                        // Display generic tool output to the user
                        echo Style::GRAY . "│ Tool Output: " . trim($toolOutput) . Style::RESET . PHP_EOL;
                        $toolSummaryForLlm = $toolOutput;
                    }

                    $this->updateFileContext($toolName, $arguments, $toolOutput);

                    file_put_contents(getcwd() . '/llm_log.txt', "Output: " . $toolOutput . "\n\n", FILE_APPEND);

                    $this->sessionContext->conversation_history[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $toolName,
                        'content' => $toolSummaryForLlm, // Use the summary here
                    ];

                } else {
                    $errorOutput = "Error: Tool '{$toolName}' not found.";
                    file_put_contents(getcwd() . '/llm_log.txt', "Output: " . $errorOutput . "\n\n", FILE_APPEND);
                    $this->sessionContext->conversation_history[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $toolName,
                        'content' => $errorOutput,
                    ];
                    Style::error("Tool '{$toolName}' not found.");
                }
            }
        }

        if ($loopCount >= $this->maxIterations) {
            Style::error("Agent reached maximum number of iterations ({$this->maxIterations}).");
        }
    }

    private function updateTerminalContext(string $command, string $stdout, string $stderr, int $exitCode): void
    {
        $this->sessionContext->terminal[] = [
            'command' => $command,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];

        // Enforce history limit, keeping the most recent 20
        if (count($this->sessionContext->terminal) > 20) {
            $this->sessionContext->terminal = array_slice($this->sessionContext->terminal, -20);
        }
    }

    private function updateFileContext(string $toolName, array $arguments, string $toolOutput): void
    {
        $path = $arguments['path'] ?? null;
        if (!$path) {
            return;
        }

        // Helper function to remove existing entries to avoid duplicates
        $removeExisting = function (string $type, string $path) {
            $this->sessionContext->files[$type] = array_filter(
                $this->sessionContext->files[$type],
                fn ($file) => $file['path'] !== $path
            );
        };

        switch ($toolName) {
            case 'write_file':
                $content = $arguments['content'] ?? '';
                $status = file_exists(getcwd() . DIRECTORY_SEPARATOR . $path) ? 'modified' : 'created';
                $preview = mb_substr($content, 0, 200) . (mb_strlen($content) > 200 ? '...' : '');

                $removeExisting('modified', $path);
                $this->sessionContext->files['modified'][] = [
                    'path' => $path,
                    'status' => $status,
                    'lines' => count(explode("\n", $content)),
                    'preview' => $preview,
                ];
                break;

            case 'read_file':
                $preview = mb_substr($toolOutput, 0, 200) . (mb_strlen($toolOutput) > 200 ? '...' : '');

                $removeExisting('read', $path);
                $this->sessionContext->files['read'][] = [
                    'path' => $path,
                    'preview' => $preview,
                ];
                break;
        }
    }
}
