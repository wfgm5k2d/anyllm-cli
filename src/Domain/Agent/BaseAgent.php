<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Agent;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Api\UsageStats;
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

    public function execute(string $prompt, callable $onProgress): ?UsageStats
    {
        $turnUsage = new UsageStats();

        // This is the history for the current turn's interaction loop.
        // It starts with the system prompt (which has the full XML context) and the current user prompt.
        $messagesForThisTurn = [
            $this->sessionContext->conversation_history[0], // System prompt
            ['role' => 'user', 'content' => $prompt]
        ];

        // Add the user prompt to the permanent history for logging/saving purposes.
        $this->sessionContext->conversation_history[] = ['role' => 'user', 'content' => $prompt];

        $actionSummaries = [];
        $finalAssistantContent = null;
        $loopCount = 0;
        $keepGoing = true;

        while ($keepGoing && $loopCount < $this->maxIterations) {
            $loopCount++;

            $response = $this->apiClient->chat(
                $messagesForThisTurn, // Use the lean, turn-specific history for the API call
                $this->toolRegistry->getToolsAsJsonSchema(),
                $onProgress
            );

            // Aggregate usage from this API call
            if ($usage = $response->getUsage()) {
                $turnUsage->add($usage);
            }

            if (!$response->hasToolCalls()) {
                // No tool calls, so it's the final answer.
                $keepGoing = false;
                $finalAssistantContent = $response->getMessageContent();
                // The onProgress callback already handled the streaming output.
                // Log the final message to the permanent history.
                $this->sessionContext->conversation_history[] = $response->getMessage();
                continue;
            }

            // Add assistant's response (with tool calls) to both permanent and turn-specific histories.
            $assistantMessage = $response->getMessage();
            $this->sessionContext->conversation_history[] = $assistantMessage;
            $messagesForThisTurn[] = $assistantMessage;

            $toolCalls = $response->getToolCalls();
            $onProgress(PHP_EOL);

            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true);
                $toolSummaryForLlm = "Error: Tool '{$toolName}' did not produce a summary.";

                // --- Intercept internal to-do commands ---
                if (in_array($toolName, ['add_todo', 'mark_todo_done', 'list_todos'])) {
                    $toolSummaryForLlm = $this->handleTodoCommand($toolName, $arguments);
                    echo Style::GRAY . "│ " . Style::CYAN . "Internal: " . trim($toolSummaryForLlm) . Style::RESET . PHP_EOL;
                    $this->updateCurrentContext($toolName, $arguments, $toolSummaryForLlm);
                } else {
                // --- Standard tool execution ---
                    Style::tool("Using tool: " . Style::BOLD . $toolName . Style::RESET);
                    $tool = $this->toolRegistry->getTool($toolName);

                    if ($tool) {
                        $toolOutput = "";
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
                            $toolSummaryForLlm = $toolOutput;
                        } elseif ($toolName === 'execute_shell_command') {
                            $jsonOutput = $tool->execute($arguments);
                            $commandResult = json_decode($jsonOutput, true);
                            $this->updateTerminalContext($commandResult['command'] ?? $arguments['command'], $commandResult['stdout'], $commandResult['stderr'], $commandResult['exit_code']);
                            if (!empty($commandResult['stdout'])) echo Style::GRAY . "│ STDOUT: " . trim($commandResult['stdout']) . Style::RESET . PHP_EOL;
                            if (!empty($commandResult['stderr'])) echo Style::RED . "│ STDERR: " . trim($commandResult['stderr']) . Style::RESET . PHP_EOL;
                            $toolSummaryForLlm = $commandResult['summary'];
                            $toolOutput = $jsonOutput;
                        } else {
                            $toolOutput = $tool->execute($arguments);
                            echo Style::GRAY . "│ Tool Output: " . trim($toolOutput) . Style::RESET . PHP_EOL;
                            $toolSummaryForLlm = $toolOutput;
                        }
                        $this->updateFileContext($toolName, $arguments, $toolOutput);
                        $this->updateCurrentContext($toolName, $arguments, $toolOutput);
                    } else {
                        $toolSummaryForLlm = "Error: Tool '{$toolName}' not found.";
                        Style::error("Tool '{$toolName}' not found.");
                    }
                }

                $actionSummaries[] = $toolSummaryForLlm;

                // Add tool result to both histories
                $toolMessage = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $toolName,
                    'content' => $toolSummaryForLlm,
                ];
                $this->sessionContext->conversation_history[] = $toolMessage;
                $messagesForThisTurn[] = $toolMessage;
            }
        }

        if ($loopCount >= $this->maxIterations) {
            Style::error("Agent reached maximum number of iterations ({$this->maxIterations}).");
        }

        // --- Episode Summary Generation ---
        if ($finalAssistantContent) {
            $actionSummaries[] = $finalAssistantContent;
        }
        $outcome = $this->generateEpisodeOutcome($actionSummaries);
        $this->sessionContext->summarized_history[] = [
            'request' => $prompt,
            'outcome' => $outcome,
            'timestamp' => date('c'),
        ];

        return $turnUsage;
    }

    private function generateEpisodeOutcome(array $actionSummaries): string
    {
        if (empty($actionSummaries)) {
            return 'No action taken.';
        }

        $filesWritten = [];
        $commandsRun = [];
        $otherActions = [];

        foreach ($actionSummaries as $summary) {
            if (str_starts_with($summary, 'Successfully wrote')) { // From WriteFileTool
                if (preg_match('/to (.*)$/', $summary, $matches)) {
                    $filesWritten[] = basename($matches[1]);
                }
            } elseif (str_starts_with($summary, 'Command `')) { // From ExecuteShellCommandTool
                if (preg_match('/`([^`]+)`/', $summary, $matches)) {
                    $commandsRun[] = $matches[1];
                }
            } else {
                $otherActions[] = $summary;
            }
        }

        $outcomeParts = [];
        if (!empty($filesWritten)) {
            $count = count($filesWritten);
            $outcomeParts[] = "created/modified {$count} file(s): " . implode(', ', $filesWritten);
        }
        if (!empty($commandsRun)) {
            $outcomeParts[] = "executed command(s): " . implode(', ', $commandsRun);
        }

        // If there were concrete tool actions, return their summary.
        if (!empty($outcomeParts)) {
            return implode('; ', $outcomeParts);
        }

        // Otherwise, if there were no tool actions, it was probably a text response. Use the last summary.
        if (!empty($otherActions)) {
            return end($otherActions);
        }

        return "Completed a series of actions.";
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
