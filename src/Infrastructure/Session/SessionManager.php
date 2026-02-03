<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Session;

use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Domain\Session\SessionManagerInterface;
use AnyllmCli\Infrastructure\Terminal\Style;

class SessionManager implements SessionManagerInterface
{
    private string $sessionDir;
    private string $contextFile;
    private string $episodesFile;

    public function __construct(string $projectRoot)
    {
        $this->sessionDir = $projectRoot . '/.anyllm';
        $this->contextFile = $this->sessionDir . '/project_context.json';
        $this->episodesFile = $this->sessionDir . '/episodes.jsonl';
    }

    public function initialize(): void
    {
        if (!is_dir($this->sessionDir)) {
            mkdir($this->sessionDir, 0777, true);
        }
    }

    public function loadSession(): SessionContext
    {
        $context = new SessionContext();

        if (file_exists($this->contextFile)) {
            $data = json_decode(file_get_contents($this->contextFile), true);
            if (is_array($data)) {
                $context->isNewSession = false;
                // Overwrite properties from loaded data
                foreach ($data as $key => $value) {
                    if (property_exists($context, $key)) {
                        $context->{$key} = $value;
                    }
                }
            }
        }

        // Ensure conversation history is always an array
        if (!is_array($context->conversation_history)) {
            $context->conversation_history = [];
        }

        return $context;
    }

    public function saveSession(SessionContext $context, bool $shouldLogHistory = false): void
    {
        // Don't persist the "isNewSession" flag as true
        $context->isNewSession = false;

        if ($shouldLogHistory) {
            $this->saveConversationToEpisodes($context->conversation_history, $context->sessionId);
        }

        // Clean history before saving to project_context to avoid duplication
        $contextToSave = clone $context;
        $contextToSave->conversation_history = [];

        $dataToSave = get_object_vars($contextToSave);
        file_put_contents($this->contextFile, json_encode($dataToSave, JSON_PRETTY_PRINT));

        Style::info('Session saved');
    }

    private function saveConversationToEpisodes(array $history, string $sessionId): void
    {
        $episode = [
            'session_id' => $sessionId,
            'timestamp' => date('c'),
            'turns' => [],
        ];

        $currentTurn = [];
        foreach ($history as $message) {
            if ($message['role'] === 'system') continue;

            if ($message['role'] === 'user') {
                if (!empty($currentTurn['user'])) { // If previous turn was not completed, save it
                    $episode['turns'][] = $currentTurn;
                }
                $currentTurn = ['user' => $message['content'], 'assistant' => ''];
            } elseif ($message['role'] === 'assistant' && isset($currentTurn['assistant'])) {
                $assistantResponse = '';
                if (!empty($message['content'])) {
                    $assistantResponse .= $message['content'];
                }
                if (!empty($message['tool_calls'])) {
                    $toolStrings = [];
                    foreach ($message['tool_calls'] as $toolCall) {
                        $func = $toolCall['function'];
                        $toolStrings[] = "TOOL_CALL: {$func['name']}({$func['arguments']})";
                    }
                    $assistantResponse .= implode("\n", $toolStrings);
                }
                $currentTurn['assistant'] = trim($assistantResponse);
            }
        }
        // Add the last turn
        if (!empty($currentTurn['user'])) {
            $episode['turns'][] = $currentTurn;
        }

        if (!empty($episode['turns'])) {
            file_put_contents($this->episodesFile, json_encode($episode) . PHP_EOL, FILE_APPEND);
        }
    }
}
