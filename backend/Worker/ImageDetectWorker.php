<?php

namespace Worker;

use Core\Mc\Alpaca\OllamaClient;

class ImageDetectWorker {
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

        $results = [];
        foreach ($pageFiles as $pageFile) {
            $pageName = pathinfo($pageFile, PATHINFO_FILENAME);
            $pageOutputDir = $outputDir . DIRECTORY_SEPARATOR . $pageName;
            $imagesOutputDir = $pageOutputDir . DIRECTORY_SEPARATOR . "images";
            
            mkdir($imagesOutputDir, 0755, true);
            
            $visuals = $this->detectVisuals($pageFile);
            $placeholders = $this->cropVisuals($pageFile, $visuals, $imagesOutputDir, "images");
            
            $results[] = [
                'page' => $pageName,
                'pageFile' => $pageFile,
                'outputDir' => $pageOutputDir,
                'imagesDir' => $imagesOutputDir,
                'visuals' => $visuals,
                'placeholders' => $placeholders
            ];
        }

        return $results;
    }

    public function detectVisuals(string $imagePath): array {
        $imageContent = file_get_contents($imagePath);
        if ($imageContent === false) {
            throw new \Exception("Could not read image: $imagePath");
        }

        $imageData = base64_encode($imageContent);
        $prompt = "Identify all visual elements (diagrams, photos, charts, logos) in this image. 
For each identified element, describe what it is and provide its bounding box coordinates.
Use normalized coordinates from 0 to 1000.

Return the result STRICTLY as a JSON array of objects:
[
  {\"box\": {\"ymin\": integer, \"xmin\": integer, \"ymax\": integer, \"xmax\": integer}, \"label\": \"string\"}
]

Do not include any other text or explanations.";

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
            return [];
        }

        if (isset($data['error'])) {
            throw new \Exception("Ollama error: " . $data['error']);
        }

        $content = $data['message']['content'] ?? '';
        
        if (preg_match('/(\[\s*\[.*\]\s*\]|\[\s*\{.*\}\s*\])/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded) && !empty($decoded)) {
                foreach ($decoded as $item) {
                    if (!isset($item['box']) || !is_array($item['box']) || 
                        !isset($item['box']['ymin'], $item['box']['xmin'], $item['box']['ymax'], $item['box']['xmax'])) {
                        return [];
                    }
                }
                return $decoded;
            }
        }

        return [];
    }

    public function cropVisuals(string $sourcePath, array $visualsInfo, string $outDir, string $relDir): array {
        $img = imagecreatefrompng($sourcePath);
        if (!$img) {
            throw new \Exception("Could not read image: $sourcePath");
        }

        $imageSize = getimagesize($sourcePath);
        if ($imageSize === false) {
            imagedestroy($img);
            throw new \Exception("Could not get image size: $sourcePath");
        }

        $w_orig = $imageSize[0];
        $h_orig = $imageSize[1];
        $placeholders = [];

        foreach ($visualsInfo as $i => $item) {
            if (!isset($item['box']) || !is_array($item['box']) || count($item['box']) !== 4) {
                continue;
            }

            $b = $item['box'];
            $y1 = ($b['ymin'] / 1000) * $h_orig;
            $x1 = ($b['xmin'] / 1000) * $w_orig;
            $y2 = ($b['ymax'] / 1000) * $h_orig;
            $x2 = ($b['xmax'] / 1000) * $w_orig;

            $cw = max(2, $x2 - $x1);
            $ch = max(2, $y2 - $y1);

            $crop = imagecrop($img, ['x' => (int)$x1, 'y' => (int)$y1, 'width' => (int)$cw, 'height' => (int)$ch]);
            if ($crop) {
                $fname = "img_" . ($i + 1) . ".png";
                imagepng($crop, $outDir . DIRECTORY_SEPARATOR . $fname);
                $label = $item['label'] ?? "visual";
                $placeholders[] = [
                    'box' => $b,
                    'md' => "![" . $label . "]($relDir/$fname)",
                    'desc' => $label,
                    'file' => $fname
                ];
                imagedestroy($crop);
            }
        }

        imagedestroy($img);
        return $placeholders;
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
