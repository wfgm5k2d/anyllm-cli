<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Adapter;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Api\ApiResponseInterface;
use AnyllmCli\Infrastructure\Api\Response\GeminiResponse;
use AnyllmCli\Infrastructure\Service\SignalManager;
use AnyllmCli\Infrastructure\Terminal\Style;
use stdClass;

class GeminiClient implements ApiClientInterface
{
    private array $config;
    private string $modelName;

    public function __construct(array $providerConfig, string $modelName)
    {
        $this->config = $providerConfig;
        $this->modelName = $modelName;
    }

    public function chat(array $messages, array $tools, ?callable $onProgress): ApiResponseInterface
    {
        $url = rtrim($this->config['baseURL'], '/') . "/v1beta/models/{$this->modelName}:streamGenerateContent";

        $payload = [];
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => $message['content']]]];
                continue;
            }

            if ($role === 'assistant') {
                $role = 'model';
            }

            if ($role === 'tool') {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [['functionResponse' => [
                        'name' => $message['name'],
                        'response' => ['content' => (string) $message['content']]
                    ]]],
                ];
                continue;
            }

            if (isset($message['tool_calls'])) {
                $parts = [];
                foreach ($message['tool_calls'] as $toolCall) {
                    $decodedArgs = json_decode((string) $toolCall['function']['arguments'], true) ?? [];
                    if (empty($decodedArgs)) {
                        $decodedArgs = new stdClass();
                    }

                    $parts[] = ['functionCall' => [
                        'name' => $toolCall['function']['name'],
                        'args' => $decodedArgs
                    ]];
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }

            if (isset($message['content'])) {
                $contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
            }
        }

        $payload['contents'] = $contents;

        if ($systemInstruction) {
            $payload['system_instruction'] = $systemInstruction;
        }

        if ($tools) {
            $geminiTools = array_map(fn($tool) => $tool['function'] ?? $tool, $tools);
            $payload['tools'] = [['functionDeclarations' => $geminiTools]];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $headers = [];
        $confHeaders = $this->config['headers'] ?? $this->config['header'] ?? [];
        foreach ($confHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        if (empty($confHeaders['Content-Type'])) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () {
            if (SignalManager::$cancellationRequested) {
                return -1; // Abort
            }
            return 0;
        });

        $responseContent = "";
        $errorBuffer = "";
        $responseStarted = false;

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseContent, &$errorBuffer, &$responseStarted) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 400) {
                $errorBuffer .= $data;
                return strlen($data);
            }

            if (!$responseStarted) {
                $responseStarted = true;
                Style::clearLine();
            }

            $responseContent .= $data;
            return strlen($data);
        });

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $active = null;
        $animFrame = 0;
        $anim = ['.', '..', '...'];

        do {
            $status = curl_multi_exec($mh, $active);
            if (!$responseStarted && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400) {
                Style::clearLine();
                echo Style::GRAY . "Think" . $anim[$animFrame++ % 3] . Style::RESET;
                usleep(200000);
            }
            if ($active && $status === CURLM_OK) {
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);

        $curl_errno = curl_errno($ch);
        $responseContent = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);


        if ($curl_errno === CURLE_ABORTED_BY_CALLBACK) {
            if ($onProgress) {
                $onProgress('<<INTERRUPTED>>');
            }
            echo PHP_EOL . Style::YELLOW . "Request cancelled." . Style::RESET . PHP_EOL;
            return new GeminiResponse('{}');
        }

        if ($curl_errno) {
            $err = curl_error($ch);
            Style::errorBox("Network Error:\n$err");
            return new GeminiResponse('{}');
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            $errorMsg = "HTTP Status: $httpCode";
            $jsonErr = json_decode($errorBuffer, true);
            if (isset($jsonErr['error']['message'])) {
                $errorMsg .= "\nAPI Error: " . $jsonErr['error']['message'];
            } elseif (!empty($errorBuffer)) {
                $errorMsg .= "\nResponse: " . substr($errorBuffer, 0, 800);
            }
            Style::errorBox($errorMsg);
            return new GeminiResponse('{}');
        }

        if ($onProgress) {
            $decodedArray = json_decode($responseContent, true);
            if (is_array($decodedArray)) {
                foreach ($decodedArray as $chunk) {
                    if (isset($chunk['candidates'][0]['content']['parts'][0]['text'])) {
                        $onProgress($chunk['candidates'][0]['content']['parts'][0]['text']);
                    }
                }
            }
        }

        return new GeminiResponse($responseContent);
    }

    public function simpleChat(array $messages): ?array
    {
        $url = rtrim($this->config['baseURL'], '/') . "/v1beta/models/{$this->modelName}:generateContent";

        $payload = [];
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $message) {
             if ($message['role'] === 'system') {
                $systemInstruction = ['parts' => [['text' => $message['content']]]];
            } else {
                $role = $message['role'] === 'assistant' ? 'model' : $message['role'];
                $contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
            }
        }
        $payload['contents'] = $contents;
        if ($systemInstruction) {
            $payload['system_instruction'] = $systemInstruction;
        }
        $payload['generationConfig'] = [
            'responseMimeType' => 'application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $headers = [];
        $confHeaders = $this->config['headers'] ?? $this->config['header'] ?? [];
        foreach ($confHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        if (empty($confHeaders['Content-Type'])) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () {
            if (SignalManager::$cancellationRequested) {
                return -1; // Abort
            }
            return 0;
        });

        $response = curl_exec($ch);

        if (curl_errno($ch) === CURLE_ABORTED_BY_CALLBACK) {
            echo PHP_EOL . Style::YELLOW . "Request cancelled." . Style::RESET . PHP_EOL;
            return null;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($httpCode >= 400 || $response === false) {
            Style::errorBox("Task analysis API call failed. HTTP Code: {$httpCode}\nError: {$error}\nResponse: " . substr((string)$response, 0, 500));
            return null;
        }

        $decoded = json_decode($response, true);
        $content = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$content) {
            return null;
        }

        return json_decode($content, true);
    }
}
