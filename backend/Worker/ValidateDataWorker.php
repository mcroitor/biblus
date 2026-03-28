<?php

namespace Worker;

use Core\Mc\Alpaca\LLMClient;

class ValidateDataWorker {
    private LLMClient $client;
    private int $maxRetries;
    private array $modelOptions;

    public function __construct(
        string $ollamaUrl,
        string $model,
        array $modelOptions = [],
        int $maxRetries = 2,
        ?LLMClient $client = null
    ) {
        $this->client = $client ?? new \Core\Mc\Alpaca\OllamaClient($ollamaUrl, $model);
        $this->maxRetries = $maxRetries;
        $this->modelOptions = array_merge([
            'temperature' => 0.1,
            'num_predict' => 1024
        ], $modelOptions);
        $this->client->SetModelOptions($this->modelOptions);
    }

    public function Execute(string $pagesDir, array $ocrResults, array $imageResults): array {
        if (!is_dir($pagesDir)) {
            throw new \Exception("Pages directory does not exist: $pagesDir");
        }

        $validationResults = [];
        foreach ($ocrResults as $index => $ocrFile) {
            $pageName = pathinfo($ocrFile, PATHINFO_FILENAME);
            $imageResult = $imageResults[$index] ?? null;
            
            $isValid = $this->validatePage($ocrFile, $imageResult);
            $validationResults[] = [
                'page' => $pageName,
                'ocrFile' => $ocrFile,
                'imageResult' => $imageResult,
                'isValid' => $isValid
            ];
        }

        return $validationResults;
    }

    public function validatePage(string $ocrFile, ?array $imageResult): bool {
        $ocrText = file_get_contents($ocrFile);
        if ($ocrText === false) {
            return false;
        }

        $hasText = strlen(trim($ocrText)) > 0;
        $hasImages = $imageResult !== null && !empty($imageResult['placeholders']);
        
        return $hasText;
    }

    public function validateWithVisualCheck(
        string $pageImagePath,
        string $ocrText,
        array $placeholders
    ): array {
        $imageContent = file_get_contents($pageImagePath);
        if ($imageContent === false) {
            throw new \Exception("Could not read image: $pageImagePath");
        }

        $imageData = base64_encode($imageContent);
        $placeholdersJson = json_encode($placeholders);

        $prompt = "Validate the OCR text and visual elements for this page image.

Extracted text:
{$ocrText}

Extracted visual placeholders (with bounding boxes):
{$placeholdersJson}

Please verify:
1. Does the text appear to match what is in the image?
2. Are the visual elements (images, diagrams) correctly identified?
3. Are the image placeholder tags positioned logically in the text?

Return a JSON response:
{
  \"isValid\": boolean,
  \"textIssues\": \"string or null\",
  \"imageIssues\": \"string or null\",
  \"suggestions\": \"string or null\"
}";

        $response = $this->client->Prompt("api/chat", [
            "model" => $this->client->GetModelName(),
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt,
                    "images" => [$imageData]
                ]
            ],
            "stream" => false
        ]);

        $data = json_decode($response, true);
        if ($data === null) {
            return [
                'isValid' => true,
                'textIssues' => null,
                'imageIssues' => null,
                'suggestions' => null,
                'error' => 'Invalid JSON response'
            ];
        }

        if (isset($data['error'])) {
            return [
                'isValid' => true,
                'textIssues' => null,
                'imageIssues' => null,
                'suggestions' => null,
                'error' => $data['error']
            ];
        }

        $content = $data['message']['content'] ?? '';
        
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            return json_decode($matches[0], true);
        }

        return [
            'isValid' => true,
            'textIssues' => null,
            'imageIssues' => null,
            'suggestions' => null
        ];
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
