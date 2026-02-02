<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Service;

class KnowledgeBaseService
{
    private string $projectRoot;

    private const KNOWLEDGE_FILES = [
        'ANYLLM.md',
        'README.md',
    ];

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * Finds and reads the content of a knowledge base file (ANYLLM.md or README.md).
     *
     * @return array{path: string, content: string}|null
     */
    public function findKnowledge(): ?array
    {
        foreach (self::KNOWLEDGE_FILES as $filename) {
            $fullPath = $this->projectRoot . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                // Limit the content to a reasonable size to avoid bloating the context
                $truncatedContent = mb_substr($content, 0, 4000);
                return [
                    'path' => $filename,
                    'content' => $truncatedContent,
                ];
            }
        }
        return null;
    }
}
