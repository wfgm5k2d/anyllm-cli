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
    protected int $maxIterations;

    public function __construct(
        protected ApiClientInterface $apiClient,
        protected ToolRegistryInterface $toolRegistry,
        protected DiffRenderer $diffRenderer,
        string $systemPrompt,
        protected SessionContext $sessionContext,
        int $maxIterations = 10
    ) {
        $this->maxIterations = $maxIterations;
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

                // --- Intercept internal to-do commands ---
                if (in_array($toolName, ['add_todo', 'mark_todo_done', 'list_todos'])) {
                    $toolSummaryForLlm = $this->handleTodoCommand($toolName, $arguments);
                    echo Style::GRAY . "│ " . Style::CYAN . "Internal: " . trim($toolSummaryForLlm) . Style::RESET . PHP_EOL;

                    $this->sessionContext->conversation_history[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $toolName,
                        'content' => $toolSummaryForLlm,
                    ];
                    // Update current context after internal command
                    $this->updateCurrentContext($toolName, $arguments, $toolSummaryForLlm);
                    continue; // Skip the rest of the loop for this tool call
                }
                // --- End interception ---

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
                    $this->updateCurrentContext($toolName, $arguments, $toolOutput);

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

    private function handleTodoCommand(string $toolName, array $arguments): string
    {
        switch ($toolName) {
            case 'add_todo':
                $text = $arguments['text'] ?? null;
                if (!$text) {
                    return "Error: Task text is required to add a to-do.";
                }
                // Avoid adding duplicate tasks
                foreach ($this->sessionContext->todo as $item) {
                    if ($item['text'] === $text) {
                        return "Task '{$text}' already exists in the to-do list.";
                    }
                }
                $this->sessionContext->todo[] = ['text' => $text, 'status' => 'pending'];
                return "Task '{$text}' added to the to-do list.";

            case 'mark_todo_done':
                $text = $arguments['text'] ?? null;
                if (!$text) {
                    return "Error: Task text is required to mark a to-do as done.";
                }
                $found = false;
                foreach ($this->sessionContext->todo as &$item) {
                    if ($item['text'] === $text) {
                        $item['status'] = 'done';
                        $found = true;
                        break;
                    }
                }
                unset($item); // Unset reference
                return $found ? "Task '{$text}' marked as done." : "Error: Task '{$text}' not found in the to-do list.";

            case 'list_todos':
                if (empty($this->sessionContext->todo)) {
                    return "The to-do list is empty.";
                }
                $list = "Current To-Do List:\n";
                foreach ($this->sessionContext->todo as $item) {
                    $statusIcon = $item['status'] === 'done' ? '[x]' : '[ ]';
                    $list .= "- {$statusIcon} {$item['text']}\n";
                }
                return $list;
        }
        return "Error: Unknown to-do command '{$toolName}'.";
    }

    private function updateCurrentContext(string $toolName, array $arguments, string $toolOutput): void
    {
        $lastAction = $toolName;
        $lastFile = null;
        $lastResult = 'FAILURE';

        // Determine primary argument and last file
        if (!empty($arguments['path'])) {
            $lastAction .= ':' . $arguments['path'];
            $lastFile = $arguments['path'];
        } elseif (!empty($arguments['command'])) {
            $lastAction .= ':' . $arguments['command'];
        }

        // Determine result status
        if ($toolName === 'execute_shell_command') {
            $commandResult = json_decode($toolOutput, true);
            if (isset($commandResult['exit_code']) && $commandResult['exit_code'] === 0) {
                $lastResult = 'SUCCESS';
            }
        } elseif ($toolName === 'search_content') {
            $lastResult = 'SUCCESS'; // Search is successful even if there are no matches.
        } else {
            // For other tools (mostly file-based), check for a generic error message.
            if (strpos($toolOutput, 'Error:') !== 0) {
                $lastResult = 'SUCCESS';
            }
        }

        $this->sessionContext->current = [
            'last_action' => $lastAction,
            'last_result' => $lastResult,
            'last_file' => $lastFile,
        ];
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
