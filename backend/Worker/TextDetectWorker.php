<?php

namespace Core\Worker;

use Core\Mc\Alpaca\OllamaClient;

class TextDetectWorker {
    private $ollama_url;
    private $model_ocr;
    private $timeout;
    private $max_retries;
    private $ollama_client;

    public function __construct($ollama_url, $model_ocr, $timeout = 300, $max_retries = 2) {
        $this->ollama_url = $ollama_url;
        $this->model_ocr = $model_ocr;
        $this->timeout = $timeout;
        $this->max_retries = $max_retries;
        $this->ollama_client = new OllamaClient($ollama_url, $timeout, $max_retries);
    }

    /**
     * Executes the OCR process on the extracted page images.
     * @param string $pagesDir Directory containing the extracted page images.
     * @param string $outputDir Directory where the OCR results will be saved.
     */
    public function Execute(string $pagesDir, string $outputDir) {
        $pageFiles = array_diff(scandir($pagesDir), ['.', '..']);

        foreach ($pageFiles as $pageFile) {
            $pagePath = $pagesDir . DIRECTORY_SEPARATOR . $pageFile;
            $textPath = $outputDir . DIRECTORY_SEPARATOR . pathinfo($pageFile, PATHINFO_FILENAME) . ".txt";
            $this->Ocr($pagePath, $textPath);
        }
    }

    public function Ocr(string $imagePath, string $outputPath) {
        $prompt = "Perform OCR on the following image and return the extracted text:\n\n![image]({$imagePath})";
        $response = $this->ollama_client->Generate(
            $this->model_ocr, 
            ["prompt" => $prompt]
            );
        file_put_contents($outputPath, $response);
    }
   
}
