<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Service;

use AnyllmCli\Domain\Session\SessionContext;

class RepoMapGenerator
{
    private string $projectRoot;
    private array $fileList = [];
    private array $ignoreRules = [];
    private const array HARDCODED_IGNORE = [
        '.git', 'node_modules', 'vendor', 'build', 'dist', 'target', '__pycache__', '.idea', '.vscode', '.DS_Store'
    ];

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    public function performInitialScan(): void
    {
        $this->loadIgnoreRules();

        $directory = new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);

        $files = [];
        foreach ($iterator as $info) {
            $relativePath = str_replace($this->projectRoot . DIRECTORY_SEPARATOR, '', $info->getPathname());

            if ($this->isIgnored($relativePath)) {
                continue;
            }

            if ($info->isFile()) {
                $files[] = $relativePath;
            }
        }
        $this->fileList = $files;
    }

    private function loadIgnoreRules(): void
    {
        $rules = self::HARDCODED_IGNORE;

        $anyllmIgnorePath = $this->projectRoot . '/.anyllmignore';
        $gitignorePath = $this->projectRoot . '/.gitignore';

        $path_to_load = file_exists($anyllmIgnorePath) ? $anyllmIgnorePath : (file_exists($gitignorePath) ? $gitignorePath : null);

        if ($path_to_load) {
            $lines = file($path_to_load, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $rules[] = $line;
                }
            }
        }

        $this->ignoreRules = array_unique($rules);
    }

    private function isIgnored(string $path): bool
    {
        $pathSegments = explode(DIRECTORY_SEPARATOR, $path);

        foreach ($this->ignoreRules as $rule) {

            // Case 1: Rule is a directory name (e.g., 'vendor', 'source').
            // This is the most common and important case.
            // If any part of the path matches the rule, ignore it.
            if (in_array($rule, $pathSegments)) {
                 return true;
            }

            // Case 2: Rule is a wildcard pattern for files (e.g., '*.log')
            // This should only match the file name itself.
            if (fnmatch($rule, basename($path))) {
                return true;
            }

            // Case 3: Rule is a full path or contains wildcards for paths
            if (fnmatch($rule, $path)) {
                return true;
            }
        }
        return false;
    }

    public function generate(string $prompt, SessionContext $context): array
    {
        $keywords = $this->extractKeywords($prompt);
        $focusFiles = $this->findFocusFiles($keywords, $context);

        $tree = $this->buildTree($this->fileList);
        $repoMapString = $this->renderSmartTree($tree, $focusFiles);

        $codeHighlights = $this->generateCodeHighlights($focusFiles);

        return [
            'repo_map' => $repoMapString,
            'code_highlights' => $codeHighlights,
        ];
    }

    private function extractKeywords(string $prompt): array
    {
        $prompt = strtolower($prompt);
        $words = preg_split('/[\s,.;:!?"]+/', $prompt);
        $stopWords = ['the', 'a', 'in', 'is', 'to', 'for', 'and', 'or', 'how', 'what', 'when', 'where', 'why', 'file', 'code', 'add', 'fix', 'change', 'update'];
        $keywords = array_filter($words, fn($word) => strlen($word) > 3 && !in_array($word, $stopWords));
        return array_unique($keywords);
    }

    private function findFocusFiles(array $keywords, SessionContext $context): array
    {
        $focusFiles = [];
        if (isset($context->files['read'])) {
            foreach ($context->files['read'] as $file) $focusFiles[] = $file['path'];
        }
        if (isset($context->files['modified'])) {
            foreach ($context->files['modified'] as $file) $focusFiles[] = $file['path'];
        }

        if (!empty($keywords)) {
            foreach ($this->fileList as $file) {
                foreach ($keywords as $keyword) {
                    if (strpos(strtolower($file), $keyword) !== false) {
                        $focusFiles[] = $file;
                        break;
                    }
                }
            }
        }
        return array_unique($focusFiles);
    }

    private function generateCodeHighlights(array $focusFiles): ?string
    {
        if (empty($focusFiles)) return null;
        $highlights = '';
        $filesToHighlight = array_slice($focusFiles, 0, 2);

        foreach ($filesToHighlight as $filePath) {
            $fullPath = $this->projectRoot . DIRECTORY_SEPARATOR . $filePath;
            if (!file_exists($fullPath) || !is_file($fullPath)) continue;

            $content = file_get_contents($fullPath);
            preg_match_all('/^\s*(?:class|trait|function|public function|private function|protected function)\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/m', $content, $matches);

            if (!empty($matches[1])) {
                $highlights .= "Symbols in {$filePath}:\n";
                foreach (array_unique($matches[1]) as $symbol) {
                    $highlights .= "- {$symbol}\n";
                }
                $highlights .= "\n";
            }
        }
        return $highlights ?: null;
    }

    public function getFileList(): array
    {
        return $this->fileList;
    }

    private function buildTree(array $paths): array
    {
        $tree = [];
        foreach ($paths as $path) {
            $parts = explode(DIRECTORY_SEPARATOR, $path);
            $current = &$tree;
            foreach ($parts as $part) {
                if (!isset($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }
        return $tree;
    }

    private function renderSmartTree(array $tree, array $focusFiles, int $depth = 0, string $prefix = '', string $currentPath = ''): string
    {
        $output = '';
        $keys = array_keys($tree);
        $maxDepth = 2; // Reduced for more aggressive collapsing
        $maxFilesInDir = 5; // Reduced for more aggressive collapsing

        foreach ($tree as $key => $value) {
            $isLast = $key === end($keys);
            $connector = $isLast ? '└── ' : '├── ';
            $newPath = $currentPath === '' ? $key : $currentPath . DIRECTORY_SEPARATOR . $key;

            if (!empty($value)) { // It's a directory
                $childFiles = $this->collectChildFiles($value);
                $isFocused = $this->isBranchFocused($newPath, $focusFiles);

                if (!$isFocused && ($depth >= $maxDepth || count($childFiles) > $maxFilesInDir)) {
                     $output .= $prefix . $connector . $key . '/ (' . count($childFiles) . " files)\n";
                     continue;
                }

                $output .= $prefix . $connector . $key . "/\n";
                $output .= $this->renderSmartTree($value, $focusFiles, $depth + 1, $prefix . ($isLast ? '    ' : '│   '), $newPath);
            } else { // It's a file
                 $output .= $prefix . $connector . $key . "\n";
            }
        }
        return $output;
    }

    private function isBranchFocused(string $branchPath, array $focusFiles): bool
    {
        foreach ($focusFiles as $focusFile) {
            if (str_starts_with($focusFile, $branchPath)) {
                return true;
            }
        }
        return false;
    }

    private function collectChildFiles(array $tree): array
    {
        $files = [];
        foreach ($tree as $key => $value) {
            if (!empty($value)) {
                $files = array_merge($files, $this->collectChildFiles($value));
            }
        }
        return $files;
    }
}



