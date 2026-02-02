<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Session;

/**
 * A Data Transfer Object representing the complete state of an agent session.
 * This structure is inspired by the SESSION_CONTEXT from the PocketCoder article.
 */
class SessionContext
{
    public string $sessionId;
    public bool $isNewSession = true;

    public ?array $task = null;
    public ?array $project = null;
    public ?string $repo_map = null;
    public ?string $code_highlights = null;
    public array $conversation_history = [];
    public array $files = ['modified' => [], 'read' => [], 'mentioned' => []];
    public array $terminal = [];
    public ?array $decisions = null;
    public ?array $knowledge_base = null;
    public array $todo = [];
    public ?array $current = null;
    public ?array $relevant_history = null;

    public function __construct()
    {
        $this->sessionId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Generates an XML-like prompt string from the current context.
     * @return string
     */
    public function toXmlPrompt(): string
    {
        $xml = "<SESSION_CONTEXT>\n";

        if ($this->project) {
            $xml .= "  <project>\n";
            $xml .= "    <name>" . htmlspecialchars($this->project['name']) . "</name>\n";
            $xml .= "    <path>" . htmlspecialchars($this->project['path']) . "</path>\n";
            $xml .= "  </project>\n";
        }

        if ($this->repo_map) {
            $xml .= "  <repo_map>\n";
            $xml .= "<![CDATA[\n" . $this->repo_map . "\n]]>\n";
            $xml .= "  </repo_map>\n";
        }

        if ($this->code_highlights) {
            $xml .= "  <code_highlights>\n";
            $xml .= "<![CDATA[\n" . $this->code_highlights . "\n]]>\n";
            $xml .= "  </code_highlights>\n";
        }
        
        // In the future, other blocks like files, terminal etc., will be added here.

        $xml .= "</SESSION_CONTEXT>\n";
        return $xml;
    }
}
