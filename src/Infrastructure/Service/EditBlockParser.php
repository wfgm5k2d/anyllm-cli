<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Service;

use AnyllmCli\Infrastructure\Terminal\DiffRenderer;
use AnyllmCli\Infrastructure\Terminal\Style;

class EditBlockParser
{
    public function __construct(private DiffRenderer $diffRenderer)
    {
    }

    public function applyEdits(string $responseText): string
    {
        $outputLog = [];

        // Pattern for SEARCH/REPLACE blocks
        $blockPattern = '/^FILE: (.*?)\r?\n<<<<<<< SEARCH\r?\n(.*?)\r?\n=======\r?\n(.*?)\r?\n>>>>>>> REPLACE/sm';
        preg_match_all($blockPattern, $responseText, $matches, PREG_SET_ORDER);

        if (!empty($matches)) {
            foreach ($matches as $match) {
                $path = trim($match[1]);
                // Note: The captured content might have trailing newlines which are important
                $searchBlock = $match[2];
                $replaceBlock = $match[3];

                $this->applySingleEdit($path, $searchBlock, $replaceBlock, $outputLog);
            }
        }

        // Pattern for [[FILE]] blocks
        $filePattern = '/\[\[FILE:(.*?)\]\](.*?)\[\[ENDFILE\]\]/s';
        preg_match_all($filePattern, $responseText, $fileMatches, PREG_SET_ORDER);

        if (!empty($fileMatches)) {
            foreach ($fileMatches as $match) {
                $path = trim($match[1]);
                $newContent = $match[2];
                // Use null for searchBlock to indicate a full overwrite
                $this->applySingleEdit($path, null, $newContent, $outputLog);
            }
        }

        if (empty($matches) && empty($fileMatches)) {
            // If no blocks were found, the model might just be talking.
            // The raw output was already streamed, so we don't need to do anything here.
        }

        return implode("\n", $outputLog);
    }

    private function applySingleEdit(?string $path, ?string $searchBlock, string $replaceBlock, array &$outputLog): void
    {
        if (!$path) {
            Style::error("Model did not specify a file path for the edit block.");
            $outputLog[] = "Error: Missing file path for edit.";
            return;
        }

        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $path;

        if (strpos($path, '..') !== false) {
            Style::errorBox("Security Alert: Path traversal blocked: $path");
            $outputLog[] = "[Error: Blocked path traversal]";
            return;
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                Style::errorBox("Failed to create directory: $dir");
                $outputLog[] = "[Error: Failed to create directory]";
                return;
            }
        }

        $oldContent = file_exists($fullPath) ? file_get_contents($fullPath) : "";
        $newContent = "";

        Style::tool("Applying changes to: " . Style::BOLD . $path . Style::RESET);

        if ($searchBlock !== null) {
            // SEARCH/REPLACE logic
            if ($oldContent === '' || strpos($oldContent, $searchBlock) === false) {
                Style::errorBox("SEARCH block not found in file: $path\nCannot apply changes.");
                $outputLog[] = "[Error: SEARCH block not found in $path]";
                return;
            }
            // Use substr_count to ensure we are only replacing one occurrence
            if (substr_count($oldContent, $searchBlock) > 1) {
                Style::errorBox("SEARCH block is not unique in file: $path\nCannot apply changes safely. Please make the SEARCH block more specific.");
                $outputLog[] = "[Error: SEARCH block not unique in $path]";
                return;
            }
            $newContent = str_replace($searchBlock, $replaceBlock, $oldContent);
        } else {
            // Full file overwrite logic
            $newContent = $replaceBlock;
        }

        $this->diffRenderer->render($oldContent, $newContent);

        if (file_put_contents($fullPath, $newContent) !== false) {
            $msg = "File created/updated: $path";
            $outputLog[] = "[$msg]";
            Style::success($msg);
        } else {
            Style::errorBox("Failed to write to file: $fullPath");
            $outputLog[] = "[Error: Failed to write to $path]";
        }
    }
}