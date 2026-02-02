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

    public function __construct(string $projectRoot)
    {
        $this->sessionDir = $projectRoot . '/.anyllm';
        $this->contextFile = $this->sessionDir . '/project_context.json';
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

    public function saveSession(SessionContext $context): void
    {
        // Don't persist the "isNewSession" flag as true
        $context->isNewSession = false;

        $dataToSave = get_object_vars($context);
        file_put_contents($this->contextFile, json_encode($dataToSave, JSON_PRETTY_PRINT));

        Style::info('Session saved');
    }
}
