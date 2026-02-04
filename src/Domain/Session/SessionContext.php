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
    public array $summarized_history = [];
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

        if ($this->task) {
            $xml .= "  <task>\n";
            $xml .= "    <summary>" . htmlspecialchars($this->task['summary'] ?? '') . "</summary>\n";
            $xml .= "    <type>" . htmlspecialchars($this->task['type'] ?? '') . "</type>\n";
            if ($this->task['artifact']) {
                $xml .= "    <artifact>" . htmlspecialchars($this->task['artifact']) . "</artifact>\n";
            }
            if ($this->task['stack']) {
                $xml .= "    <stack>" . htmlspecialchars($this->task['stack']) . "</stack>\n";
            }
            if ($this->task['constraints']) {
                $xml .= "    <constraints>" . htmlspecialchars($this->task['constraints']) . "</constraints>\n";
            }
            $xml .= "  </task>\n";
        }

        if ($this->relevant_history) {
            $xml .= "  <relevant_history>\n";
            $xml .= "    <summary>Snippets from past sessions that might be relevant to the current query.</summary>\n";
            foreach ($this->relevant_history as $line) {
                $xml .= "    <retrieved_episode><![CDATA[" . htmlspecialchars($line) . "]]></retrieved_episode>\n";
            }
            $xml .= "  </relevant_history>\n";
        }

        if ($this->project) {
            $xml .= "  <project>\n";
            $xml .= "    <name>" . htmlspecialchars($this->project['name']) . "</name>\n";
            $xml .= "    <path>" . htmlspecialchars($this->project['path']) . "</path>\n";
            if (!empty($this->project['entry_point'])) {
                $xml .= "    <entry_point>" . htmlspecialchars($this->project['entry_point']) . "</entry_point>\n";
            }
            $xml .= "  </project>\n";
        }

        if (!empty($this->summarized_history)) {
            $xml .= "  <conversation_history>\n";
            foreach ($this->summarized_history as $episode) {
                $xml .= '    <episode timestamp="' . htmlspecialchars($episode['timestamp']) . '">' . "\n";
                $xml .= '      <request><![CDATA[' . htmlspecialchars($episode['request']) . "]]></request>\n";
                $xml .= '      <outcome><![CDATA[' . htmlspecialchars($episode['outcome']) . "]]></outcome>\n";
                $xml .= "    </episode>\n";
            }
            $xml .= "  </conversation_history>\n";
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

        if ($this->knowledge_base) {
            $xml .= '  <knowledge_base file="' . htmlspecialchars($this->knowledge_base['path']) . '">' . "\n";
            $xml .= "<![CDATA[\n" . $this->knowledge_base['content'] . "\n]]>\n";
            $xml .= "  </knowledge_base>\n";
        }

        if (!empty($this->files['modified']) || !empty($this->files['read'])) {
            $xml .= "  <files>\n";
            if (!empty($this->files['modified'])) {
                $xml .= "    <modified>\n";
                foreach ($this->files['modified'] as $file) {
                    $xml .= '      <file path="' . htmlspecialchars($file['path']) . '" status="' . $file['status'] . '" lines="' . $file['lines'] . '">';
                    $xml .= "<![CDATA[" . htmlspecialchars($file['preview']) . "]]>";
                    $xml .= "</file>\n";
                }
                $xml .= "    </modified>\n";
            }
            if (!empty($this->files['read'])) {
                $xml .= "    <read>\n";
                foreach ($this->files['read'] as $file) {
                    $xml .= '      <file path="' . htmlspecialchars($file['path']) . '">';
                    $xml .= "<![CDATA[" . htmlspecialchars($file['preview']) . "]]>";
                    $xml .= "</file>\n";
                }
                $xml .= "    </read>\n";
            }
            $xml .= "  </files>\n";
        }

        if (!empty($this->terminal)) {
            $xml .= "  <terminal>\n";
            foreach ($this->terminal as $exec) {
                $xml .= '    <execution exit_code="' . ((int) ($exec['exit_code'] ?? -1)) . '">' . "\n";
                $xml .= '      <command><![CDATA[' . htmlspecialchars((string) ($exec['command'] ?? '')) . "]]></command>\n";
                if (!empty($exec['stdout'])) {
                    $xml .= '      <stdout><![CDATA[' . htmlspecialchars((string) $exec['stdout']) . "]]></stdout>\n";
                }
                if (!empty($exec['stderr'])) {
                    $xml .= '      <stderr><![CDATA[' . htmlspecialchars((string) $exec['stderr']) . "]]></stderr>\n";
                }
                $xml .= "    </execution>\n";
            }
            $xml .= "  </terminal>\n";
        }

        if (!empty($this->todo)) {
            $xml .= "  <todo>\n";
            foreach ($this->todo as $item) {
                $xml .= '    <item status="' . htmlspecialchars($item['status']) . '">' . htmlspecialchars($item['text']) . "</item>\n";
            }
            $xml .= "  </todo>\n";
        }

        if ($this->current) {
            $xml .= "  <current>\n";
            $xml .= "    <last_action>" . htmlspecialchars($this->current['last_action']) . "</last_action>\n";
            $xml .= "    <last_result>" . htmlspecialchars($this->current['last_result']) . "</last_result>\n";
            if ($this->current['last_file']) {
                $xml .= "    <last_file>" . htmlspecialchars($this->current['last_file']) . "</last_file>\n";
            }
            $xml .= "  </current>\n";
        }
        
        // In the future, other blocks like files, terminal etc., will be added here.

        $xml .= "</SESSION_CONTEXT>\n";
        return $xml;
    }
}
