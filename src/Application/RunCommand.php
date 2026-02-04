<?php

namespace AnyllmCli\Application;

use AnyllmCli\Application\Factory\AgentFactory;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Infrastructure\Config\AnylmJsonConfig;
use AnyllmCli\Infrastructure\Service\HistorySearchService;
use AnyllmCli\Infrastructure\Service\RepoMapGenerator;
use AnyllmCli\Infrastructure\Service\KnowledgeBaseService;
use AnyllmCli\Infrastructure\Service\ProjectIdentifierService;
use AnyllmCli\Infrastructure\Session\SessionManager;
use AnyllmCli\Infrastructure\Terminal\Style;
use AnyllmCli\Infrastructure\Terminal\TerminalManager;
use AnyllmCli\Infrastructure\Terminal\TUI;
use AnyllmCli\Application\SlashCommand\SlashCommandRegistry;

class RunCommand
{
    private AnylmJsonConfig $config;
    private TerminalManager $terminalManager;
    private TUI $tui;
    private SessionManager $sessionManager;
    private RepoMapGenerator $repoMapGenerator;
    private ProjectIdentifierService $projectIdentifierService;
    private KnowledgeBaseService $knowledgeBaseService;
    private bool $isSessionMode = false;
    private SessionContext $sessionContext;
    private bool $isCleanedUp = false;
    private SlashCommandRegistry $commandRegistry;
    private ?array $activeProviderConfig = null;
    private ?string $activeModelName = null;
    private string $ragMode = 'none'; // 'none', 'command', or 'llm'

    // Public getters for commands to access dependencies
    public function getSessionContext(): SessionContext { return $this->sessionContext; }
    public function getActiveProviderConfig(): ?array { return $this->activeProviderConfig; }
    public function getActiveModelName(): ?string { return $this->activeModelName; }

    public function isSessionMode(): bool
    {
        return $this->isSessionMode;
    }

    public function resetSessionContext(): void
    {
        // Re-create the session context object
        $this->sessionContext = new SessionContext();

        // Re-run the initial identification steps that happen for a new session
        $this->sessionContext->project = $this->projectIdentifierService->identify();
        $this->sessionContext->knowledge_base = $this->knowledgeBaseService->findKnowledge();
    }


    public function __construct()
    {
        // --- Global Handlers ---
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            Style::errorBox("PHP Error [$errno]: $errstr\nLocation: $errfile:$errline");
            return true;
        });

        set_exception_handler(function ($e) {
            Style::errorBox("Uncaught Exception:\n" . $e->getMessage() . "\nLocation: " . $e->getFile() . ":" . $e->getLine());
            exit(1);
        });
        // ------------------------------------------

        $this->config = new AnylmJsonConfig();
        $this->terminalManager = new TerminalManager();
        $this->commandRegistry = new SlashCommandRegistry();
        $this->tui = new TUI($this->terminalManager, $this->config, $this->commandRegistry);
        $this->sessionManager = new SessionManager(getcwd());
        $this->repoMapGenerator = new RepoMapGenerator(getcwd());
        $this->projectIdentifierService = new ProjectIdentifierService(getcwd());
        $this->knowledgeBaseService = new KnowledgeBaseService(getcwd());
        $this->sessionContext = new SessionContext();

        $this->setupRagMode();
        $this->registerSlashCommands();
        $this->detectSessionMode();
        $this->setupSignalHandler();
        register_shutdown_function([$this, 'performCleanup']);
    }

    private function setupRagMode(): void
    {
        $determinedMode = 'none';

        // Flags have priority
        if (in_array('--rag-llm', $_SERVER['argv'], true)) {
            $determinedMode = 'llm';
        } elseif (in_array('--rag-command', $_SERVER['argv'], true)) {
            $determinedMode = 'command';
        } elseif (in_array('--rag', $_SERVER['argv'], true)) {
            $determinedMode = 'command'; // Default to 'command' mode if only --rag is specified
        } else {
            // If no flags, check config file
            $ragConfig = $this->config->get('rag');
            if ($ragConfig !== null && is_array($ragConfig)) {
                // Validate config
                if (!isset($ragConfig['enable']) || !isset($ragConfig['mode'])) {
                    Style::errorBox("The 'rag' config is missing required 'enable' or 'mode' keys.\nPlease refer to the documentation: https://anyllm.tech/?p=Memory/rag&lang=ru");
                    exit(1);
                }
                if ((bool)$ragConfig['enable'] === true) {
                    if (!in_array($ragConfig['mode'], ['llm', 'command'])) {
                        Style::errorBox("Invalid value for 'rag.mode'. Must be 'llm' or 'command'.\nPlease refer to the documentation: https://anyllm.tech/?p=Memory/rag&lang=ru");
                        exit(1);
                    }
                    $determinedMode = $ragConfig['mode'];
                }
            }
        }

        $this->ragMode = $determinedMode;
    }

    private function registerSlashCommands(): void
    {
        $this->commandRegistry->register(new \AnyllmCli\Application\SlashCommand\ExitCommand());
        $this->commandRegistry->register(new \AnyllmCli\Application\SlashCommand\ClearCommand());
        $this->commandRegistry->register(new \AnyllmCli\Application\SlashCommand\SummarizeCommand());
        $this->commandRegistry->register(new \AnyllmCli\Application\SlashCommand\InitCommand());

        if ($this->ragMode === 'command' || $this->ragMode === 'llm') {
            $this->commandRegistry->register(new \AnyllmCli\Application\SlashCommand\SearchHistoryCommand());
        }
    }

    private function setupSignalHandler(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'handleSigint']);
        }
    }

    public function handleSigint(): never
    {
        echo PHP_EOL . Style::GRAY . "Ctrl+C detected. Shutting down gracefully..." . Style::RESET . PHP_EOL;
        $this->performCleanup();
        exit();
    }

    public function performCleanup(): void
    {
        if ($this->isCleanedUp) {
            return;
        }
        $this->terminalManager->restoreMode();
        if ($this->isSessionMode) {
            $shouldLogHistory = $this->ragMode !== 'none';
            $this->sessionManager->saveSession($this->sessionContext, $shouldLogHistory);
        }
        $this->isCleanedUp = true;
    }

    private function detectSessionMode(): void
    {
        $sessionFlagFound = in_array('--session', $_SERVER['argv'], true);
        $sessionInConfig = $this->config->get('session', false) === true;
        $this->isSessionMode = $sessionFlagFound || $sessionInConfig;
    }

    public function run(): void
    {
        Style::banner();

        if (empty($this->config->get('provider'))) {
            Style::error("No providers configured in anyllm.json");
            exit(1);
        }

        if ($this->ragMode !== 'none') {
            Style::info("RAG mode enabled: " . Style::BOLD . $this->ragMode . Style::RESET);
        }

        // --- Session Handling ---
        if ($this->isSessionMode) {
            Style::info("Session mode enabled. Context will be loaded and saved.");
            $this->sessionManager->initialize();
            $this->sessionContext = $this->sessionManager->loadSession();
        }

        // --- Project Identification ---
        if ($this->sessionContext->isNewSession) {
            $this->sessionContext->project = $this->projectIdentifierService->identify();
        }

        // --- Knowledge Base ---
        $this->sessionContext->knowledge_base = $this->knowledgeBaseService->findKnowledge();

        // --- Repo Map Generation ---
        $this->repoMapGenerator->performInitialScan();
        // --------------------------

        $selection = $this->tui->selectModelTUI();

        if (!$selection) {
            echo Style::GRAY . "Exit." . Style::RESET . PHP_EOL;
            exit(0);
        }

        $this->activeProviderConfig = $selection['provider_config'];
        $this->activeModelName = $selection['model_name'];

        Style::info("Using Provider: " . Style::PURPLE . $selection['provider_name'] . Style::RESET);
        Style::info("Using Model:    " . Style::BOLD . $selection['model_key'] . Style::RESET);

        // The system prompt is now generated inside the loop to have the latest context
        $this->startLoop($this->activeProviderConfig, $this->activeModelName);
    }

    private function startLoop(array $providerConfig, string $modelName): void
    {
        while (true) {
            $input = $this->tui->readInputTUI();
            if (empty($input)) continue;

            // Handle Slash Commands
            if (str_starts_with($input, '/')) {
                $parts = explode(' ', $input);
                $commandName = $parts[0];
                $args = array_slice($parts, 1);

                if ($commandName === '/exit' || $commandName === '/quit') { // Special case for exit
                    (new \AnyllmCli\Application\SlashCommand\ExitCommand())->execute([], $this);
                }

                $command = $this->commandRegistry->find($commandName);
                if ($command) {
                    $command->execute($args, $this);
                    echo PHP_EOL; // Add a newline after command execution
                    continue; // Go to next loop iteration
                } else {
                    Style::error("Unknown command: " . $commandName);
                    continue;
                }
            }

            // If not a slash command, proceed with agent execution
            $processedInput = $this->tui->processInputFiles($input);

            // --- Task Analysis on first run ---
            if ($this->sessionContext->isNewSession && !empty($processedInput)) {
                Style::info("First run in session. Analyzing task...");
                $taskAnalysisPrompt = $this->getTaskAnalysisPrompt($processedInput);

                // Create a temporary client for this one-off call
                $tempApiClient = ($providerConfig['type'] === 'google')
                    ? new \AnyllmCli\Infrastructure\Api\Adapter\GeminiClient($providerConfig, $modelName)
                    : new \AnyllmCli\Infrastructure\Api\Adapter\OpenAiClient($providerConfig, $modelName);

                $taskData = $tempApiClient->simpleChat($taskAnalysisPrompt);

                if ($taskData && is_array($taskData)) {
                    $this->sessionContext->task = [
                        'summary' => $taskData['summary'] ?? null,
                        'type' => $taskData['type'] ?? 'OTHER',
                        'artifact' => $taskData['artifact'] ?? null,
                        'stack' => $taskData['stack'] ?? null,
                        'constraints' => $taskData['constraints'] ?? null,
                    ];
                    Style::info("Task identified: " . ($this->sessionContext->task['summary'] ?? 'N/A'));
                } else {
                    Style::error("Could not identify task from initial prompt.");
                    // We can still continue, just without the <task> context
                }
                $this->sessionContext->isNewSession = false; // Ensure this only runs once
            }
            // ------------------------------------
            
            // Generate the prompt with the latest context right before execution
            $maxIterations = (int) $this->config->get('agent.max_iterations', 10);
            $systemPrompt = $this->getSystemPrompt($this->sessionContext, $processedInput);
            $agent = AgentFactory::create($providerConfig, $modelName, $systemPrompt, $this->sessionContext, $maxIterations);

            echo PHP_EOL . Style::PURPLE . "✦ " . Style::RESET;

            $agent->execute($processedInput, function ($chunk) {
                echo $chunk;
                if (ob_get_length()) ob_flush();
                flush();
            });

            echo PHP_EOL . PHP_EOL;
        }
    }

    private function getSystemPrompt(SessionContext $context, string $currentInput): string
    {
        $osInfo = php_uname();
        $cwd = getcwd();

        // --- RAG: llm mode ---
        if ($this->ragMode === 'llm' && !empty($currentInput)) {
            $historySearch = new HistorySearchService(getcwd());
            $relevantHistory = $historySearch->search($currentInput);
            if (!empty($relevantHistory)) {
                $context->relevant_history = $relevantHistory;
            }
        }
        // ---------------------

        // Generate dynamic repo map based on current input
        $mapData = $this->repoMapGenerator->generate($currentInput, $context);
        $context->repo_map = $mapData['repo_map'];
        $context->code_highlights = $mapData['code_highlights'];
        
        $sessionXml = $context->toXmlPrompt();

        return <<<PROMPT
You are a powerful AI assistant running in a CLI on a user's local machine. Your primary function is to execute user requests by calling functions to interact with the local filesystem.

**System Information:**
- Operating System: $osInfo
- Current Working Directory: $cwd

**SESSION CONTEXT:**
This block contains the summary of the project and conversation history. Use it to understand the user's goals.
$sessionXml

**CRITICAL INSTRUCTIONS:**
1.  **Tool Usage:** You MUST use the provided tools (functions) to interact with the filesystem. Do not ask for permission.
2.  **Planning:** For complex, multi-step tasks (like creating an application), your first action should be to break the task into a series of smaller steps by calling the `add_todo` tool for each step. After creating the plan, call `list_todos` to show it to the user. Then, execute the plan step-by-step, using `mark_todo_done` after completing each one. For simple, single-step tasks, act directly without planning.
3.  **Step-by-step Thinking:** When the user gives a command, figure out which tool can fulfill the request and call it with the correct arguments.
4.  **File Paths:** If a file path is required, use the relative path from the current working directory.
5.  **Conciseness:** When you have completed the user's request, simply stop. Do not output a summary message like "I have successfully...". Your responses should be concise and directly related to the task.
PROMPT;
    }

    private function getTaskAnalysisPrompt(string $userInput): array
    {
        $prompt = <<<PROMPT
You are a task analysis assistant. Analyze the user's request and extract the session goal. Respond ONLY with a valid JSON object with the following keys: "summary", "type", "artifact", "stack", "constraints".
- "summary": A concise one-sentence summary of the user's goal.
- "type": The type of task. Choose one from: CREATE, EDIT, DELETE, RUN, EXPLORE, MULTI, OTHER.
- "artifact": The main noun or artifact being worked on (e.g., "calculator", "user profile page", "database connection").
- "stack": The primary technology stack if mentioned (e.g., "Python", "React", "PHP"). If not mentioned, use null.
- "constraints": Any limitations or requirements mentioned (e.g., "without external libraries", "using FastAPI"). If none, use null.

The user's request is:
"{$userInput}"
PROMPT;

        return [
            ['role' => 'system', 'content' => 'You are a helpful assistant designed to output JSON.'],
            ['role' => 'user', 'content' => $prompt]
        ];
    }
}

