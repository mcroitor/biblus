<?php

namespace Worker;

use Core\Mc\Alpaca\LLMClient;
use Core\Mc\Logger;

class TextDetectWorker {
    private LLMClient $client;
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
        $prompt = "Act as an expert Document AI and OCR engine. Your goal is to transform the provided image into a high-fidelity Markdown document with precise visual grounding.

### GUIDELINES:
1.  **Text Extraction**: Recognize all text with 100% accuracy. Maintain the original document flow.
2.  **Formatting**: 
    - Use Markdown headers (#, ##) for titles. 
    - Use | tables | for tabular data. 
    - Use [ ] for checkboxes if present.
3.  **Visual Grounding (CRITICAL)**: 
    - Whenever you encounter a non-text element (diagram, photo, chess board, logo), insert a tag:
      <image>[ymin, xmin, ymax, xmax](label)</image>
    - Coordinates MUST be normalized to a 0-1000 scale.
    - [ymin, xmin] is the top-left corner, [ymax, xmax] is the bottom-right.
    - Place the tag exactly where the image sits in the flow of text.

### CONSTRAINTS:
- DO NOT use your internal coordinate tokens like <|box_start|>.
- DO NOT provide descriptions of the page or conversational filler.
- RETURN ONLY the raw Markdown content.";

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
