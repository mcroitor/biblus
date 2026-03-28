<?php

namespace Test;

use Core\Mc\Alpaca\LLMClient;

class MockOllamaClient implements LLMClient {
    private string $apiUrl;
    private string $modelName;
    private string $apiKey;
    private array $options = [];
    private array $responses = [];
    private array $callLog = [];

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
        
        if ($endpoint === 'api/tags') {
            return json_encode([
                'models' => [
                    ['name' => $this->modelName],
                    ['name' => 'other-model:latest']
                ]
            ]);
        }

        $responseKey = $this->buildResponseKey($data);
        
        if (isset($this->responses[$responseKey])) {
            return $this->responses[$responseKey];
        }

        if (isset($this->responses['default'])) {
            return $this->responses['default'];
        }

        return json_encode(['error' => 'No mock response configured']);
    }

    public function GetModelsList(): array {
        $response = $this->Prompt('api/tags', []);
        $data = json_decode($response, true);
        return array_column($data['models'] ?? [], 'name');
    }

    public function GetModelInfo(string $modelName): array {
        return [
            'name' => $modelName,
            'modified_at' => date('c'),
            'parameters' => []
        ];
    }

    public function setResponse(string $key, string $response): void {
        $this->responses[$key] = $response;
    }

    public function setDefaultResponse(string $response): void {
        $this->responses['default'] = $response;
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

    public function clearResponses(): void {
        $this->responses = [];
    }

    private function buildResponseKey(array $data): string {
        if (isset($data['messages'][0]['content'])) {
            $content = $data['messages'][0]['content'];
            if (str_contains($content, 'visual elements')) {
                return 'detect_visuals';
            }
            if (str_contains($content, 'Markdown')) {
                return 'markdown';
            }
            if (str_contains($content, 'OCR')) {
                return 'ocr';
            }
            if (str_contains($content, 'Validate')) {
                return 'validate';
            }
        }
        return 'default';
    }
}
