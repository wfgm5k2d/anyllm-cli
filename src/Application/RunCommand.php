<?php

namespace AnyllmCli\Application;

use AnyllmCli\Application\Factory\AgentFactory;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Infrastructure\Config\AnylmJsonConfig;
use AnyllmCli\Infrastructure\Service\HistorySearchService;
use AnyllmCli\Infrastructure\Service\KnowledgeBaseService;
use AnyllmCli\Infrastructure\Service\ProjectIdentifierService;
use AnyllmCli\Infrastructure\Service\RepoMapGenerator;
use AnyllmCli\Infrastructure\Service\SignalManager;
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
    private ?array $activeModelConfig = null;
    private string $ragMode = 'none'; // 'none', 'command', or 'llm'
    private bool $requestInterrupted = false;

    // Public getters for commands to access dependencies
    public function getSessionContext(): SessionContext { return $this->sessionContext; }
    public function getActiveProviderConfig(): ?array { return $this->activeProviderConfig; }
    public function getActiveModelName(): ?string { return $this->activeModelName; }
    public function getActiveModelConfig(): ?array { return $this->activeModelConfig; }

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
    
    private function setupSignalHandler(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'handleSigint']);
        }
    }

    public function handleSigint(): void
    {
        if (SignalManager::$isAgentRunning) {
            SignalManager::$cancellationRequested = true;
        } else {
            SignalManager::$sigintCount++;
        }
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

    public function performCleanup(): void
    {
        if ($this->isCleanedUp) {
            return;
        }
        $this->terminalManager->restoreMode();
        if ($this->isSessionMode) {
            $shouldLogHistory = $this->ragMode !== 'none' && !$this->requestInterrupted;
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
        $this->activeModelConfig = $selection['model_config'];

        Style::info("Using Provider: " . Style::PURPLE . $selection['provider_name'] . Style::RESET);
        Style::info("Using Model:    " . Style::BOLD . $selection['model_key'] . Style::RESET);

        // The system prompt is now generated inside the loop to have the latest context
        $this->startLoop($this->activeProviderConfig, $this->activeModelName, $this->activeModelConfig);
    }

    private function startLoop(array $providerConfig, string $modelName, array $modelConfig): void
    {
        while (true) {
            $this->requestInterrupted = false;
            SignalManager::$cancellationRequested = false;
            SignalManager::$isAgentRunning = false;
            SignalManager::$sigintCount = 0;

            $input = $this->tui->readInputTUI();
            if ($input === null) continue; // TUI was interrupted, loop again

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

                $tempApiClient = ($providerConfig['type'] === 'google')
                    ? new \AnyllmCli\Infrastructure\Api\Adapter\GeminiClient($providerConfig, $modelName)
                    : new \AnyllmCli\Infrastructure\Api\Adapter\OpenAiClient($providerConfig, $modelName);

                SignalManager::$isAgentRunning = true;
                $taskData = $tempApiClient->simpleChat($taskAnalysisPrompt);
                SignalManager::$isAgentRunning = false;

                if (SignalManager::$cancellationRequested) {
                    $this->requestInterrupted = true;
                    continue; // Skip to next main loop iteration
                }

                if ($taskData && is_array($taskData)) {
                    $this->sessionContext->task = [
                        'summary' => $taskData['summary'] ?? null,
                        'type' => $taskData['type'] ?? 'OTHER',
                        'artifact' => $taskData['artifact'] ?? null,
                        'stack' => $taskData['stack'] ?? null,
                        'constraints' => $taskData['constraints'] ?? null,
                    ];
                    Style::info("Task identified: " . ($this->sessionContext->task['summary'] ?? 'N/A'));
                } else if (!$this->requestInterrupted) {
                    Style::error("Could not identify task from initial prompt.");
                }
                $this->sessionContext->isNewSession = false; // Ensure this only runs once
            }
            // ------------------------------------
            
            // Generate the prompt with the latest context right before execution
            $maxIterations = (int) $this->config->get('agent.max_iterations', 10);

            $modelType = $modelConfig['type'] ?? 'large';
            $systemPrompt = ($modelType === 'small')
                ? $this->getSmallModelSystemPrompt($this->sessionContext, $processedInput)
                : $this->getSystemPrompt($this->sessionContext, $processedInput);

            $agent = AgentFactory::create($providerConfig, $modelName, $systemPrompt, $this->sessionContext, $maxIterations, $modelConfig);

            echo PHP_EOL . Style::PURPLE . "✦ " . Style::RESET;

            SignalManager::$isAgentRunning = true;
            $agent->execute($processedInput, function ($chunk) {
                if ($chunk === '<<INTERRUPTED>>') {
                    $this->requestInterrupted = true;
                    return;
                }
                echo $chunk;
                if (ob_get_length()) ob_flush();
                flush();
            });
            SignalManager::$isAgentRunning = false;

            echo PHP_EOL . PHP_EOL;
        }
    }

    private function getSmallModelSystemPrompt(SessionContext $context, string $currentInput): string
    {
        $osInfo = php_uname();
        $cwd = getcwd();

        // RAG and RepoMap generation logic is the same for all models
        if ($this->ragMode === 'llm' && !empty($currentInput)) {
            $historySearch = new HistorySearchService(getcwd());
            $relevantHistory = $historySearch->search($currentInput);
            if (!empty($relevantHistory)) {
                $context->relevant_history = $relevantHistory;
            }
        }
        $mapData = $this->repoMapGenerator->generate($currentInput, $context);
        $context->repo_map = $mapData['repo_map'];
        $context->code_highlights = $mapData['code_highlights'];
        
        $sessionXml = $context->toXmlPrompt();

        return <<<PROMPT
You are a powerful AI assistant running in a CLI on a user's local machine. Your primary function is to execute user requests by generating text-based commands to interact with the local filesystem.

**System Information:**
- Operating System: $osInfo

**SESSION CONTEXT:**
This block contains the summary of the project and conversation history. Use it to understand the user's goals.
$sessionXml

**CRITICAL INSTRUCTIONS:**
1.  **Text-Based Commands:** You MUST use the specific text formats described below to read, write, or edit files. Do not output any other text or explanations.
2.  **Step-by-step Thinking:** When the user gives a command, figure out what file modifications are needed and generate the correct text blocks.
3.  **File Paths:** You are operating inside the project root directory. All file paths you provide MUST be relative to the project root (e.g., 'src/main.js' or 'README.md'). Do NOT use absolute paths or include the project directory path itself. Double-check your paths before generating a command.

**AVAILABLE COMMANDS:**

*   **Read a File:**
    To read a file, you must ask the user to show it to you. For example: "Please show me the content of `src/Application/RunCommand.php`"

*   **Edit a File (SEARCH/REPLACE):**
    To edit a file, you must specify the file path, then provide a `SEARCH` block and a `REPLACE` block.
    The `SEARCH` block must be the exact, literal text to find in the file.
    The `REPLACE` block is the new text that will replace the `SEARCH` block.

    Format:
    FILE: path/to/your/file.php
    <<<<<<< SEARCH
    // The exact code to search for
    function old_function() {
        return 1;
    }
    =======
    // The new code to replace it with
    function new_function() {
        return 2;
    }
    >>>>>>> REPLACE

*   **Write/Create a File (Full Overwrite):**
    To create a new file or completely overwrite an existing one, use the `[[FILE]]` block.

    Format:
    [[FILE:path/to/file.ext]]
    ... file content ...
    [[ENDFILE]]

Now, wait for the user's command and execute it by generating the appropriate text blocks.
After all the actions, if you created a file or modified it, read the contents of the file to make sure that all the necessary changes were made and the necessary files exist.
PROMPT;
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

