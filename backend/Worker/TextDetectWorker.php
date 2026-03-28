<?php

namespace Worker;

use Core\Mc\Alpaca\LLMClient;
use Core\Mc\Logger;

class TextDetectWorker {
    private LLMClient $client;
    private int $timeout;
    private int $maxRetries;
    private array $modelOptions;

    public function __construct(
        string $ollamaUrl,
        string $model,
        array $modelOptions = [],
        int $timeout = 600,
        int $maxRetries = 2,
        ?LLMClient $client = null
    ) {
        $this->client = $client ?? new \Core\Mc\Alpaca\OllamaClient($ollamaUrl, $model);
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->modelOptions = array_merge([
            'temperature' => 0.2,
            'num_predict' => 4096
        ], $modelOptions);
        $this->client->SetModelOptions($this->modelOptions);
    }

    public function Execute(string $pagesDir, string $outputDir): array {
        if (!is_dir($pagesDir)) {
            throw new \Exception("Pages directory does not exist: $pagesDir");
        }

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \Exception("Cannot create output directory: $outputDir");
            }
        }

        $pageFiles = glob($pagesDir . DIRECTORY_SEPARATOR . "*.png");
        natsort($pageFiles);

        Logger::Stdout()->Info("TextDetectWorker: Found " . count($pageFiles) . " pages");

        $results = [];
        foreach ($pageFiles as $pageFile) {
            $pageName = pathinfo($pageFile, PATHINFO_FILENAME);
            $textPath = $outputDir . DIRECTORY_SEPARATOR . $pageName . ".txt";
            try {
                $this->Ocr($pageFile, $textPath);
                $results[] = $textPath;
            } catch (\Throwable $e) {
                Logger::Stdout()->Error("TextDetectWorker: " . $e->getMessage());
            }
        }

        return $results;
    }

    public function Ocr(string $imagePath, string $outputPath): string {
        Logger::Stdout()->Info("TextDetectWorker: Processing " . basename($imagePath));

        $imageContent = file_get_contents($imagePath);
        if ($imageContent === false) {
            throw new \Exception("Could not read image: $imagePath");
        }

        $imageData = base64_encode($imageContent);
        $prompt = "Act as a professional OCR engine. Extract all text from the attached image and format it into a clean, structured Markdown document. Use headers, lists, and tables where applicable. Use LaTeX for math. Return ONLY the Markdown content without any conversational filler.";

        $response = $this->sendOcrRequest($prompt, [$imageData]);
        
        Logger::Stdout()->Info("TextDetectWorker: Got response, length=" . strlen($response));
        
        file_put_contents($outputPath, $response);
        
        return $response;
    }

    private function sendOcrRequest(string $prompt, array $images): string {
        Logger::Stdout()->Info("TextDetectWorker: Sending request to " . $this->client->GetApiUrl());
        
        $response = $this->client->Prompt("api/chat", [
            "model" => $this->client->GetModelName(),
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt,
                    "images" => $images
                ]
            ],
            "stream" => false
        ]);

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \Exception("Invalid JSON response from Ollama: " . substr($response, 0, 200));
        }

        if (isset($data['error'])) {
            throw new \Exception("Ollama error: " . $data['error']);
        }

        return $data['message']['content'] ?? '';
    }

    public function checkModelExists(): bool {
        $models = $this->client->GetModelsList();
        return in_array($this->client->GetModelName(), $models);
    }

    public function setTemperature(float $temperature): void {
        $this->modelOptions['temperature'] = $temperature;
        $this->client->SetModelOptions($this->modelOptions);
    }
}
