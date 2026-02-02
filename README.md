# AnyLLM CLI

AnyLLM CLI is a powerful command-line tool that allows you to interact with various Large Language Models (LLMs) from your terminal.

## Installation

You can install `anyllm` with a single command. This will download the appropriate binary for your system and place it in `/usr/local/bin`.

```bash
curl -sSfL https://anyllm.tech/install.sh | sh
```
*Note: The script may ask for your password (`sudo`) to move the binary to the target directory.*

## Manual Installation

You can also download the latest pre-built binaries for Linux and macOS directly from the [GitHub Releases page](https://github.com/wfgm5k2d/anyllm-cli/releases). The release link will change with each new version.

1.  **Download the Binary**:
    Go to the latest release and download the appropriate binary for your operating system and architecture:
    -   `anyllm-linux-x86_64`
    -   `anyllm-macos-x86_64`
    -   `anyllm-macos-aarch64` (for Apple Silicon Macs)

2.  **Make it Executable**:
    After downloading, open your terminal, navigate to the directory containing the file, and make it executable. You can also rename it for convenience.

    ```bash
    # For example, on Linux
    mv anyllm-linux-x86_64 anyllm
    chmod +x ./anyllm
    ```

3.  **Special Instructions for macOS Users**:
    When you first try to run the application on macOS, you may see a warning that it is from an unidentified developer. To resolve this, you need to grant an exception for the app.

    -   Navigate to **System Settings** > **Privacy & Security**.
    -   Scroll down to the "Security" section.
    -   You will see a message about "anyllm" being blocked. Click the **"Open Anyway"** button.

    For more detailed instructions, please refer to the official Apple support page: [Open a Mac app from an unidentified developer](https://support.apple.com/guide/mac-help/open-a-mac-app-from-an-unidentified-developer-mh40616/mac).

4.  **Run the Application**:
    Once the file is executable, you can run it from your terminal.

    ```bash
    ./anyllm
    ```
    
    Remember to create an `anyllm.json` configuration file in the same directory where you run the binary.

---
# AnyLLM CLI Binary Build Guide

This guide provides step-by-step instructions on how to build a standalone executable binary for the AnyLLM CLI tool. This binary will allow you to run AnyLLM without a system-wide PHP installation, making it highly portable.

---

## Why Build a Binary?

Building a binary for AnyLLM offers several advantages:
- **Portability**: Run AnyLLM on any compatible system without needing to install PHP or its dependencies globally.
- **Self-contained**: All necessary PHP code and extensions are bundled into a single executable file.
- **Simplified Deployment**: Distribute AnyLLM as a single file, making deployment and usage straightforward.

## Prerequisites

Before you begin, ensure you have the following:

- **PHP 8.4+**: While the final binary won't require a system PHP, you need it for Composer and to create the PHAR archive during the build process.
- **Composer**: Used to manage PHP dependencies.
- **`static-php-cli` binary (`spc`)**: This tool is essential for building static PHP executables. It's provided within this project.
- **Basic Build Tools**: `make`, `gcc`/`clang`, `pkg-config` (usually available on Linux/macOS or installed via `spc doctor`).

## Step-by-Step Build Instructions

Follow these steps from the root of the `anyllm-cli` directory.

### Step 1: Make `static-php-cli` Binary Executable

The `spc` binary is located in the `anyllm-cli` directory. You need to make it executable.

```bash
chmod +x ./spc
```

### Step 2: Install Composer Dependencies

Ensure all PHP dependencies are installed.

```bash
composer install
```

### Step 3: Prepare `anyllm.json`

The `anyllm.json` configuration file should reside *outside* the binary, in the same directory where you run the `anyllm` executable. This allows you to easily modify your AI provider configurations without rebuilding the binary.

Make sure your `anyllm.json` file is correctly set up in the root of your project (e.g., `/Users/nikitaverbuh/git/anyllm/anyllm.json`).

### Step 4: Modify `Config.php` to Locate `anyllm.json`

The `Config.php` file needs to be adjusted so that the binary looks for `anyllm.json` in the current working directory at runtime, rather than inside the bundled PHAR.

Open `src/Service/Config.php` and change the line:

```php
$this->configFile = __DIR__ . '/../../anyllm.json';
```

to:

```php
$this->configFile = getcwd() . DIRECTORY_SEPARATOR . 'anyllm.json';
```

This change ensures that the `anyllm` binary will always look for its configuration file in the directory from which it is executed.

### Step 5: Run `spc doctor` (Optional, but Recommended)

This command checks for missing build dependencies and can often fix them automatically.

```bash
./spc doctor --auto-fix
```

### Step 6: Download PHP Sources and Extensions

Download all necessary PHP source code and extensions that AnyLLM requires. This step can take some time depending on your internet connection.

```bash
./spc download --for-extensions=curl,json,mbstring,pcntl,posix,phar,tokenizer,sockets,zlib,openssl
```

### Step 7: Build the `micro.sfx` Binary

Now, build the static PHP runtime (`micro.sfx`) with the required extensions and a specified memory limit. We'll set the memory limit to 512MB as an example.

```bash
./spc build curl,json,mbstring,pcntl,posix,phar,tokenizer,sockets,zlib,openssl --build-micro -I "memory_limit=512M"
```

This command will compile PHP and all specified extensions into a single `micro.sfx` file located in `buildroot/bin/`.

### Step 8: Create the PHAR Archive of AnyLLM Application Code

Next, we'll bundle the AnyLLM application's PHP files into a PHAR archive. This PHAR will *not* include `anyllm.json`.

First, create a temporary build directory and copy the application files into it:

```bash
rm -rf build && mkdir -p build
cp -R src vendor index.php composer.json composer.lock build/
```

Then, create the PHAR archive. You might need to temporarily disable `phar.readonly` in your PHP configuration for this step.

```bash
php -d phar.readonly=0 -r "
    \$pharFile = 'anyllm.phar';
    \$phar = new Phar(\$pharFile);
    \$phar->buildFromDirectory('build', '/\.php$/');
    \$phar->setStub(\$phar->createDefaultStub('index.php'));
    \$phar->compressFiles(Phar::GZ);
"
```

### Step 9: Combine PHAR and `micro.sfx` into the Final Executable

Finally, combine the `micro.sfx` runtime with your `anyllm.phar` application code to create the standalone `anyllm` executable.

```bash
./spc micro:combine anyllm.phar --with-micro=buildroot/bin/micro.sfx --output=anyllm
```

### Step 10: Clean Up Temporary Files

Remove the temporary `build` directory and the `anyllm.phar` file.

```bash
rm -rf build anyllm.phar
```

## Running the Binary

You should now have a single executable file named `anyllm` in your `anyllm-cli` directory.

To run it, navigate to the `anyllm-cli` directory and execute:

```bash
./anyllm
```

Remember to place your `anyllm.json` configuration file in the same directory where you execute the `anyllm` binary.

## Troubleshooting

-   **`pkg-config` not found**: Run `./spc doctor --auto-fix` to attempt an automatic fix.
-   **`phar.readonly` error**: Ensure you run the `php -d phar.readonly=0 ...` command as shown in Step 8.
-   **Build failures**: If the build fails, try running `rm -rf buildroot source` to clear previous build artifacts and then retry from Step 6. If issues persist, consult the `static-php-cli` documentation or open an issue.

## Configuration (`anyllm.json`)

The `anyllm` binary is configured via an `anyllm.json` file. This file must be placed in the same directory where you run the executable. It allows you to define the AI providers and models that the CLI can use.

### File Structure

The configuration is structured as follows:

```json
{
  "provider": {
    "your_provider_key": {
      "name": "Human-Readable Name",
      "type": "openai_compatible",
      "options": {
        "baseURL": "https://api.example.com/v1",
        "header": {
          "Authorization": "Bearer YOUR_API_KEY"
        }
      },
      "models": {
        "model-alias": {
          "name": "actual-model-name-for-api"
        }
      }
    }
  }
}
```

### Key Descriptions

*   `provider`: The root object containing all provider configurations.
*   `your_provider_key`: A unique key for your provider (e.g., `my_openai`, `local_llama`).
    *   `name`: (string) A display name for the provider that will appear in the TUI.
    *   `type`: (string) The API compatibility type. Currently, `openai_compatible` is supported, allowing you to use any service that follows the OpenAI API standard.
    *   `options`: An object for provider-specific settings.
        *   `baseURL`: (string) The base URL for the API endpoint.
        *   `header`: (object, optional) Custom HTTP headers to be sent with each request. This is where you should place your API key in an `Authorization` header.
    *   `models`: An object listing the available models for this provider.
        *   `model-alias`: A short name or alias for the model that you will see in the selection menu.
            *   `name`: (string) The actual model identifier that the API expects.

## Running Tests

To verify the functionality and ensure everything is working as expected, you can run the PHPUnit test suite.

Navigate to the root of the project directory and execute the following command:

```bash
./vendor/bin/phpunit
```

This will run all the defined tests and report their status.

