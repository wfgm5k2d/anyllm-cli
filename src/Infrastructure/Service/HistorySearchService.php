<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Service;

class HistorySearchService
{
    private string $episodesFile;

    public function __construct(string $projectRoot)
    {
        $this->episodesFile = $projectRoot . '/.anyllm/episodes.jsonl';
    }

    public function search(string $query, int $limit = 3): array
    {
        if (!file_exists($this->episodesFile)) {
            return [];
        }

        $keywords = $this->extractKeywords($query);
        if (empty($keywords)) {
            return [];
        }

        $scoredResults = [];
        $fileHandle = fopen($this->episodesFile, 'r');
        if ($fileHandle) {
            while (($line = fgets($fileHandle)) !== false) {
                $score = 0;
                $lineLower = strtolower($line);
                foreach ($keywords as $keyword) {
                    if (str_contains($lineLower, $keyword)) {
                        $score++;
                    }
                }

                if ($score > 0) {
                    $scoredResults[] = ['line' => $line, 'score' => $score];
                }
            }
            fclose($fileHandle);
        }

        // Sort by score descending
        usort($scoredResults, fn($a, $b) => $b['score'] <=> $a['score']);

        // Return the line content of the top results
        return array_map(fn($r) => $r['line'], array_slice($scoredResults, 0, $limit));
    }

    private function extractKeywords(string $prompt): array
    {
        $prompt = strtolower($prompt);
        // A more comprehensive list of stop words
        $stopWords = [
            // English
            'a', 'an', 'and', 'the', 'in', 'is', 'it', 'to', 'for', 'of', 'on', 'with', 'was', 'were', 'be', 'been', 'being',
            'how', 'what', 'when', 'where', 'why', 'who', 'which', 'that', 'this', 'these', 'those',
            'i', 'you', 'he', 'she', 'they', 'we', 'me', 'him', 'her', 'them', 'us',
            'my', 'your', 'his', 'her', 'their', 'our', 'mine', 'yours', 'theirs', 'ours',
            'do', 'does', 'did', 'will', 'can', 'should', 'could', 'would', 'have', 'has', 'had',
            'create', 'add', 'make', 'fix', 'change', 'update', 'delete', 'remove', 'get', 'set', 'list', 'show', 'find',
            'file', 'code', 'command', 'error', 'issue', 'problem', 'request', 'response', 'data', 'class', 'function', 'method', 'variable',

            // Russian
            'а', 'в', 'и', 'не', 'на', 'с', 'о', 'но', 'по', 'из', 'у', 'за', 'к', 'от', 'да', 'нет', 'то', 'так', 'вот', 'же', 'бы',
            'как', 'что', 'где', 'когда', 'почему', 'зачем', 'кто', 'какой', 'который',
            'я', 'ты', 'он', 'она', 'оно', 'они', 'мы', 'вы', 'меня', 'тебя', 'его', 'ее', 'нас', 'вас', 'их',
            'мой', 'твой', 'свой', 'наш', 'ваш',
            'быть', 'есть',
            'создай', 'добавь', 'сделай', 'исправь', 'измени', 'удали', 'получи', 'покажи', 'найди',
            'файл', 'код', 'команда', 'ошибка', 'проблема', 'запрос', 'ответ', 'данные', 'класс', 'функция', 'метод', 'переменная',
        ];
        $words = preg_split('/[\s,.;:!?"\'`()[\]{}]+/', $prompt);
        $keywords = array_filter($words, fn($word) => strlen($word) > 2 && !in_array($word, $stopWords));
        return array_unique($keywords);
    }
}
