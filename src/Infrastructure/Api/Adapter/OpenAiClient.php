<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Adapter;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Api\ApiResponseInterface;
use AnyllmCli\Infrastructure\Api\Response\OpenAiResponse;
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

        $jsonPayload = json_encode($payload);
        file_put_contents(
            getcwd() . '/llm_log.txt',
            "--- OpenAI Request ---\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );

        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

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
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = substr($line, 6);
                    if ($jsonStr === '[DONE]') continue;

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
            } else {
                curl_multi_select($mh, 0.1);
            }
        } while ($active && $status == CURLM_OK);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
            Style::errorBox("Network Error:\n$err");
            return new OpenAiResponse('{}');
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);

        $log = "--- OpenAI Response (HTTP {$httpCode}) ---\n";
        if ($httpCode >= 400) {
            $log .= "Error Buffer: " . $errorBuffer . "\n";
        } else {
            $log .= "Raw Content: " . $responseContent . "\n";
        }
        file_put_contents(getcwd() . '/llm_log.txt', $log . "\n\n", FILE_APPEND);

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
            'stream' => false, // No streaming
            'response_format' => ['type' => 'json_object'], // Ask for JSON output
        ];

        $jsonPayload = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $headers = [];
        $confHeaders = $this->config['headers'] ?? $this->config['header'] ?? [];
        foreach ($confHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        if (empty($confHeaders['Content-Type'])) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            Style::errorBox("Task analysis API call failed. HTTP Code: {$httpCode}\nError: {$error}\nResponse: " . substr((string)$response, 0, 500));
            return null;
        }

        $decoded = json_decode($response, true);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!$content) {
            return null;
        }

        // The response itself is expected to be a JSON string
        return json_decode($content, true);
    }
}
