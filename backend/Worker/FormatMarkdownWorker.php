<?php

namespace Worker;

use Core\Mc\Alpaca\OllamaClient;

class FormatMarkdownWorker {
    private OllamaClient $client;
    private int $timeout;
    private array $modelOptions;

    public function __construct(
        string $ollamaUrl,
        string $model,
        array $modelOptions = [],
        int $timeout = 600
    ) {
        $this->client = new OllamaClient($ollamaUrl, $model);
        $this->timeout = $timeout;
        $this->modelOptions = array_merge([
            'temperature' => 0.1,
            'num_predict' => 4096,
            'num_ctx' => 32768
        ], $modelOptions);
        $this->client->SetModelOptions($this->modelOptions);
    }

    public function Execute(
        string $pagesDir,
        string $ocrResultsDir,
        array $imageResults,
        string $outputDir
    ): array {
        if (!is_dir($pagesDir)) {
            throw new \Exception("Pages directory does not exist: $pagesDir");
        }

        if (!is_dir($ocrResultsDir)) {
            throw new \Exception("OCR results directory does not exist: $ocrResultsDir");
        }

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \Exception("Cannot create output directory: $outputDir");
            }
        }

        $pageFiles = glob($pagesDir . DIRECTORY_SEPARATOR . "*.png");
        natsort($pageFiles);
        
        $ocrFiles = glob($ocrResultsDir . DIRECTORY_SEPARATOR . "*.txt");
        natsort($ocrFiles);

        $results = [];
        foreach ($pageFiles as $index => $pageFile) {
            $pageName = pathinfo($pageFile, PATHINFO_FILENAME);
            $ocrFile = $ocrFiles[$index] ?? null;
            $imageResult = $this->findImageResult($imageResults, $pageName);
            
            $outputFile = $outputDir . DIRECTORY_SEPARATOR . $pageName . ".md";
            $markdown = $this->formatPageMarkdown($pageFile, $ocrFile, $imageResult);
            
            file_put_contents($outputFile, $markdown);
            
            $results[] = [
                'page' => $pageName,
                'file' => $outputFile,
                'markdown' => $markdown
            ];
        }

        return $results;
    }

    private function findImageResult(array $imageResults, string $pageName): ?array {
        foreach ($imageResults as $result) {
            if ($result['page'] === $pageName) {
                return $result;
            }
        }
        return null;
    }

    public function formatPageMarkdown(
        string $pageImagePath,
        ?string $ocrFile,
        ?array $imageResult
    ): string {
        $imageContent = file_get_contents($pageImagePath);
        if ($imageContent === false) {
            throw new \Exception("Could not read image: $pageImagePath");
        }

        $imageData = base64_encode($imageContent);
        
        $placeholders = $imageResult['placeholders'] ?? [];
        $ctx = "";
        if (!empty($placeholders)) {
            $ctx = "I have extracted visual elements. Use these tags at their locations:\n";
            foreach ($placeholders as $p) {
                $box = $p['box'];
                $ctx .= "- Around [ymin: {$box['ymin']}, xmin: {$box['xmin']}, ymax: {$box['ymax']}, xmax: {$box['xmax']}]: {$p['md']}\n";
            }
        }

        $ocrText = "";
        if ($ocrFile !== null && file_exists($ocrFile)) {
            $ocrText = file_get_contents($ocrFile);
        }

        if (!empty($ocrText)) {
            $prompt = "Refine and format this OCR text into clean Markdown. {$ctx}\n\nExisting OCR text:\n{$ocrText}\n\nMaintain structure (headers, tables). Insert visual tags logically. Use LaTeX for math. NO filler, ONLY Markdown.";
        } else {
            $prompt = "Perform high-fidelity OCR to Markdown. {$ctx}\nMaintain structure (headers, tables). Insert visual tags logically. Use LaTeX for math. NO filler, ONLY Markdown.";
        }

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
            throw new \Exception("Invalid JSON response from Ollama");
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
