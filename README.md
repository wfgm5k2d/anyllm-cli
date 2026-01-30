# AnyLLM CLI Binary Build Guide

This guide provides step-by-step instructions on how to build a standalone executable binary for the AnyLLM CLI tool. This binary will allow you to run AnyLLM without a system-wide PHP installation, making it highly portable.

## Why Build a Binary?

Building a binary for AnyLLM offers several advantages:
- **Portability**: Run AnyLLM on any compatible system without needing to install PHP or its dependencies globally.
- **Self-contained**: All necessary PHP code and extensions are bundled into a single executable file.
- **Simplified Deployment**: Distribute AnyLLM as a single file, making deployment and usage straightforward.

## Prerequisites

Before you begin, ensure you have the following:

- **PHP 8.1+**: While the final binary won't require a system PHP, you need it for Composer and to create the PHAR archive during the build process.
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
