<?php


namespace Worker;

use Core\Mc\Alpaca\LLMClient;
use Core\Mc\Logger;

class TranslateWorker
{
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
            'max_tokens' => 4096,
            'num_predict' => 8192,
            'num_ctx' => 8192,
        ], $modelOptions);
        $this->client->SetModelOptions($this->modelOptions);
        \Core\Mc\Alpaca\OllamaClient::SetRequestTimeout($timeout);
    }

    public function Execute(
        string $inputDir,
        string $outputDir,
        string $targetLanguage,
        int $firstPage = 1,
        int $lastPage = 0
    ): array {
        if (!is_dir($inputDir)) {
            throw new \Exception("Input directory does not exist: $inputDir");
        }

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \Exception("Cannot create output directory: $outputDir");
            }
        }

        $textFiles = glob($inputDir . DIRECTORY_SEPARATOR . "*.md");
        natsort($textFiles);
        if($lastPage === 0 || $lastPage > count($textFiles)) {
            $lastPage = count($textFiles);
        }
        $textFiles = array_slice($textFiles, $firstPage - 1, $lastPage - $firstPage + 1);


        Logger::Stdout()->Info("TranslateWorker: Found " . count($textFiles) . " text files to translate");

        $results = [];
        foreach ($textFiles as $textFile) {
            Logger::Stdout()->Info("TranslateWorker: Translating file: {$textFile}");
            $fileName = pathinfo($textFile, PATHINFO_FILENAME);
            $translatedPath = $outputDir . DIRECTORY_SEPARATOR . $fileName . ".md";
            try {
                $this->TranslateTextFile($textFile, $translatedPath, $targetLanguage);
                $results[] = $translatedPath;
                Logger::Stdout()->Info("TranslateWorker: Successfully translated to {$translatedPath}");
            } catch (\Throwable $e) {
                Logger::Stdout()->Error("TranslateWorker: " . $e->getMessage());
            }
        }

        return $results;
    }

    public function TranslateText(string $text, string $targetLanguage): string
    {
        $prompt = $this->BuildMarkdownTranslationPrompt($text, $targetLanguage);
        $response = $this->client->Prompt("api/chat", [
            "model" => $this->client->GetModelName(),
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ],
            "stream" => false
        ]);

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to parse translation response: " . json_last_error_msg());
        }

        return $data['message']['content'] ?? '';
    }

    private function TranslateTextFile(string $inputPath, string $outputPath, string $targetLanguage): void
    {
        $text = file_get_contents($inputPath);

        $translatedText = $this->TranslateText($text, $targetLanguage);

        file_put_contents($outputPath, $translatedText);
    }

    private function BuildMarkdownTranslationPrompt(string $markdown, string $targetLanguage): string
    {
        return <<<PROMPT
You are a professional technical translator.

Translate the following Markdown document to {$targetLanguage}.

Hard requirements:
- Preserve valid Markdown structure exactly (headings, lists, tables, blockquotes, horizontal rules).
- Preserve code fences, inline code, HTML tags, URLs, image paths, and link targets unchanged.
- Translate human-readable text, including link labels and image alt text.
- Keep original line breaks and paragraph structure as much as possible.
- Do not add notes, explanations, comments, or surrounding code fences.
- Return only the translated Markdown document.

Markdown to translate:

{$markdown}
PROMPT;
    }
}
