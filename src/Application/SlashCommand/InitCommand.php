<?php

declare(strict_types=1);

namespace AnyllmCli\Application\SlashCommand;

use AnyllmCli\Application\RunCommand;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Domain\SlashCommand\SlashCommandInterface;
use AnyllmCli\Infrastructure\Terminal\Style;
use AnyllmCli\Application\Factory\AgentFactory;

class InitCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return '/init';
    }

    public function getDescription(): string
    {
        return 'Analyzes the project and generates an ANYLLM.md summary file.';
    }

    public function execute(array $args, RunCommand $mainApp): void
    {
        echo Style::YELLOW . "This command will analyze the entire project to generate an ANYLLM.md file." . Style::RESET . PHP_EOL;
        echo Style::YELLOW . "This may take some time and consume a significant amount of tokens." . Style::RESET . PHP_EOL;
        echo "Do you want to continue? (y/n): ";

        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);

        if (strtolower($line) !== 'y') {
            Style::info("Operation cancelled.");
            return;
        }

        $providerConfig = $mainApp->getActiveProviderConfig();
        $modelName = $mainApp->getActiveModelName();

        if (!$providerConfig || !$modelName) {
            Style::error("Cannot run /init because no active model is selected.");
            return;
        }

        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = "Analyze the project and generate the ANYLLM.md file as instructed in the system prompt. Start by exploring the file system.";
        $cleanSessionContext = new SessionContext();

        Style::info("Starting project analysis... The agent will now take over.");
        $agent = AgentFactory::create(
            $providerConfig,
            $modelName,
            $systemPrompt,
            $cleanSessionContext,
            50
        );

        $agent->execute($userPrompt, function ($chunk) {
            echo $chunk;
            if (ob_get_length()) ob_flush();
            flush();
        });

        Style::success("Project analysis complete. ANYLLM.md should be generated.");
    }

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an expert code analyzer and technical documentation specialist. Your task is to comprehensively analyze the provided project to extract all possible information and create a detailed, structured report.

**User Request:** Analyze the entire project I provide. Your goal is to understand the project completely and document everything of importance. Proceed step by step.

**Step-by-Step Instructions:**

1.  **Initial Overview & Identification:**
    *   Determine the **project's type** (e.g., web app, mobile app, CLI tool, library, backend service).
    *   Identify the **primary programming language(s)** and **major frameworks** (e.g., React, Spring Boot, Django, Express.js).
    *   Scan for common configuration files (`package.json`, `pyproject.toml`, `build.gradle`, `pom.xml`, `dockerfile`, `docker-compose.yml`, `composer.json`, `Cargo.toml`, `go.mod`, etc.) to list **core dependencies and technologies**.

2.  **Project Structure & Architecture:**
    *   Map the **key directories and their purposes** (e.g., `src/`, `app/`, `client/`, `server/`, `api/`, `config/`, `public/`, `tests/`).
    *   Describe the **high-level architecture** if discernible (e.g., MVC, microservices, monolithic, client-server).
    *   Identify the **entry point(s)** of the application. This is crucial. Look for files like `main.js`, `index.js`, `App.jsx`, `main.py`, `application.py`, `src/main/java/.../Application.java`, `Program.cs`, etc.
    *   Note the location of **static assets, configuration files, environment variable templates (`.env.example`), and documentation**.

3.  **Dependencies & Technologies:**
    *   List **backend technologies** (language, framework, web server, ORM, database drivers).
    *   List **frontend technologies** (framework, UI library, bundler like Webpack/Vite, CSS framework).
    *   List **development tools** (linter, formatter, testing framework, package manager).
    *   List **databases, caches, and external services** (PostgreSQL, Redis, Elasticsearch, etc.) as indicated in config files.
    *   Identify any **containerization or orchestration** tools (Docker, Kubernetes).

4.  **Setup & Launch Instructions:**
    *   Extract **how to install dependencies** (e.g., `npm install`, `pip install -r requirements.txt`, `bundle install`).
    *   Find instructions for **setting up environment variables** (look for `.env.example` or similar).
    *   Find **database setup steps** (migrations, seeds, init scripts).
    *   Determine the **command(s) to run the project** for development (e.g., `npm run dev`, `python manage.py runserver`, `./gradlew bootRun`).
    *   Determine if there are **build commands for production** (e.g., `npm run build`).
    *   Check for **available scripts and their purposes** (in `package.json`, `Makefile`, etc.).

5.  **Additional Analysis:**
    *   Briefly review the main source files to understand the **core logic and data flow**.
    *   Check for the presence and location of **tests** and how to run them.
    *   Look for any **API documentation** (OpenAPI/Swagger specs, Postman collections) or inline docs.
    *   Note any **important security or configuration considerations** (e.g., required secrets, ports in use).

**Output Format & Final Task:**
Synthesize all gathered information into a final, comprehensive, and well-organized report.

**Your final and mandatory output task is:** Create a file named `ANYLLM.md` and write the complete obtained information into it. Structure the `ANYLLM.md` file as follows:

```markdown
# Project Analysis Report

## 1. Project Overview
*   **Type:**
*   **Primary Language(s):**
*   **Core Frameworks:**

## 2. Project Structure & Architecture
*   **Key Directories:**
*   **Architecture Pattern (if identifiable):**
*   **Main Entry Point(s):** `[path/to/file]`

## 3. Technology Stack
*   **Backend:**
*   **Frontend:**
*   **Database & External Services:**
*   **Development Tools:**
*   **Infrastructure/DevOps:**

## 4. How to Run the Project
*   **Prerequisites:**
*   **Installation Steps:**
*   **Configuration (Environment Variables):**
*   **Database Setup:**
*   **Development Server Command:**
*   **Production Build Command:**
*   **Other Useful Scripts:**

## 5. Detailed Project Layout
*(A more detailed breakdown of what key files and directories contain.)*

## 6. Additional Notes & Observations
*(Architecture details, code patterns, security notes, TODOs found, etc.)*
```

Now analyze the provided project files and create an `ANYLLM.md` report in the project root. Afterwards, verify that you've created the ANYLLM.md file in the project root and make sure it's in place.
PROMPT;
    }
}
