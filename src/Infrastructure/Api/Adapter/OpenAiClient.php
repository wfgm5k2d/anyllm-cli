<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Adapter;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Api\ApiResponseInterface;
use AnyllmCli\Infrastructure\Api\Response\OpenAiResponse;
use AnyllmCli\Infrastructure\Service\SignalManager;
use AnyllmCli\Infrastructure\Terminal\Style;

class OpenAiClient implements ApiClientInterface
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
        $baseUrl = rtrim($this->config['baseURL'], '/');
        $url = (strpos($baseUrl, 'chat/completions') !== false) ? $baseUrl : $baseUrl . '/chat/completions';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $headers = [];
        $confHeaders = $this->config['headers'] ?? $this->config['header'] ?? [];
        foreach ($confHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        if (empty($confHeaders['Content-Type'])) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => true,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        // Progress function to handle cancellation
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () {
            if (SignalManager::$cancellationRequested) {
                return -1; // A non-zero return value aborts the transfer.
            }
            return 0;
        });

        $responseContent = "";
        $errorBuffer = "";
        $responseStarted = false;

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseContent, &$errorBuffer, $onProgress, &$responseStarted) {
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

            foreach (explode("\n", $data) as $line) {
                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = substr($line, 6);
                    if (trim($jsonStr) === '[DONE]') continue;

                    $json = json_decode($jsonStr, true);
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $chunk = $json['choices'][0]['delta']['content'];
                        if ($onProgress) {
                            $onProgress($chunk);
                        }
                    }
                }
            }
            return strlen($data);
        });

        // Use a multi-handle to add a thinking animation
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
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);


        if ($curl_errno === CURLE_ABORTED_BY_CALLBACK) {
            if ($onProgress) {
                $onProgress('<<INTERRUPTED>>');
            }
            echo PHP_EOL . Style::YELLOW . "Request cancelled." . Style::RESET . PHP_EOL;
            return new OpenAiResponse('{}');
        }

        if ($curl_errno) {
            $err = curl_error($ch);
            Style::errorBox("Network Error:\n$err");
            return new OpenAiResponse('{}');
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
            return new OpenAiResponse('{}');
        }

        return new OpenAiResponse($responseContent);
    }

    public function simpleChat(array $messages): ?array
    {
        $baseUrl = rtrim($this->config['baseURL'], '/');
        $url = (strpos($baseUrl, 'chat/completions') !== false) ? $baseUrl : $baseUrl . '/chat/completions';

        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => false,
            'response_format' => ['type' => 'json_object'],
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

        // Progress function to handle cancellation
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
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!$content) {
            return null;
        }

        return json_decode($content, true);
    }
}
