<?php

namespace Test;

use Core\Mc\Alpaca\LLMClient;

class IntegrationOllamaClient implements LLMClient {
    private string $apiUrl;
    private string $modelName;
    private string $apiKey;
    private array $options = [];
    private array $callLog = [];
    private array $responses = [];

    public function __construct(
        string $apiUrl = 'http://localhost:11434',
        string $modelName = 'qwen3.5:9b',
        string $apiKey = ''
    ) {
        $this->apiUrl = $apiUrl;
        $this->modelName = $modelName;
        $this->apiKey = $apiKey;
        $this->options = ['stream' => false];
    }

    public function GetApiUrl(): string {
        return $this->apiUrl;
    }

    public function GetApiKey(): string {
        return $this->apiKey;
    }

    public function GetModelName(): string {
        return $this->modelName;
    }

    public function SetModelName(string $modelName): void {
        $this->modelName = $modelName;
    }

    public function SetRequestOption(string $option, string $value): void {
        $this->options[$option] = $value;
    }

    public function SetModelOptions(array $options): void {
        $this->options['options'] = $options;
    }

    public function Generate(string $prompt, array $options = []): string {
        return $this->Prompt('api/generate', array_merge(['prompt' => $prompt], $options));
    }

    public function Prompt(string $endpoint, array $data) {
        $this->callLog[] = ['endpoint' => $endpoint, 'data' => $data];

        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        if (!empty($this->apiKey)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            curl_close($ch);
            throw new \RuntimeException('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Ollama returned HTTP $httpCode: $response");
        }

        return $response;
    }

    public function Get(string $endpoint): string {
        $this->callLog[] = ['endpoint' => $endpoint, 'method' => 'GET'];

        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        if (!empty($this->apiKey)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            curl_close($ch);
            throw new \RuntimeException('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Ollama returned HTTP $httpCode: $response");
        }

        return $response;
    }

    public function GetModelsList(): array {
        $response = $this->Get('api/tags');
        $data = json_decode($response, true);
        return array_column($data['models'] ?? [], 'name');
    }

    public function GetModelInfo(string $modelName): array {
        return json_decode($this->Prompt('api/show', ['model' => $modelName]), true);
    }

    public function getCallLog(): array {
        return $this->callLog;
    }

    public function getLastCall(): ?array {
        return end($this->callLog) ?: null;
    }

    public function clearCallLog(): void {
        $this->callLog = [];
    }

    public function setResponse(string $key, string $response): void {
        $this->responses[$key] = $response;
    }

    public function clearResponses(): void {
        $this->responses = [];
    }
}
