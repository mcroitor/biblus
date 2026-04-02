# Data Pipeline Architecture

## Overview

Biblus is a PDF to Markdown converter using AI (Ollama API). The pipeline processes PDF documents through a series of Worker stages to produce formatted Markdown output.

## Pipeline Stages

```text
PDF/Directory → [ExplodePdfWorker] → [TextDetectWorker] → [ImageDetectWorker] → [FormatMarkdownWorker] → [CompileDocumentWorker] → Markdown
```

1. **ExplodePdfWorker** - Extract pages from PDF and store as images (PNG/JPEG/TIFF)
2. **TextDetectWorker** - Extract text from each page using OCR (Ollama LLM)
3. **ImageDetectWorker** - Detect and extract visual elements (diagrams, photos, charts)
4. **FormatMarkdownWorker** - Format extracted text and images as Markdown
5. **CompileDocumentWorker** - Combine all pages into a single Markdown document

## Workers

All Workers follow a common pattern:

- Constructor accepts `ollamaUrl`, `model`, optional `modelOptions`, optional `client`
- `Execute()` method performs the main processing
- Dependency injection via optional `LLMClient` parameter for testing

### ExplodePdfWorker

Converts PDF pages to images using Imagick.

```php
$worker = new ExplodePdfWorker(string $format = self::PNG, int $dpi = self::DPI);
$pages = $worker->Execute(string $pdfPath, string $outputDir): array;
```

**Output:** Array of page image paths (e.g., `page_001.png`, `page_002.png`)

**Engine:** Uses Imagick extension (requires Ghostscript for PDF support)

### TextDetectWorker

Performs OCR on page images using Ollama LLM.

```php
$worker = new TextDetectWorker(
    string $ollamaUrl,
    string $model,
    array $modelOptions = [],
    int $timeout = 600,
    int $maxRetries = 2,
    ?LLMClient $client = null
);
$textPaths = $worker->Execute(string $pagesDir, string $outputDir): array;
```

**Output:** Array of text file paths (e.g., `page_001.txt`)

### ImageDetectWorker

Detects visual elements and extracts them as separate images.

```php
$worker = new ImageDetectWorker(
    string $ollamaUrl,
    string $model,
    array $modelOptions = [],
    int $timeout = 600,
    ?LLMClient $client = null
);
$results = $worker->Execute(string $pagesDir, string $outputDir): array;
```

**Output:** Array of results with structure:

```php
[
    'page' => 'page_001',
    'pageFile' => '/path/to/page_001.png',
    'outputDir' => '/path/to/page_001',
    'imagesDir' => '/path/to/page_001/images',
    'visuals' => [...],
    'placeholders' => [
        ['box' => [...], 'md' => '![diagram](images/img_1.png)', 'desc' => 'diagram', 'file' => 'img_1.png']
    ]
]
```

### FormatMarkdownWorker

Formats OCR text and images into Markdown using Ollama LLM.

```php
$worker = new FormatMarkdownWorker(
    string $ollamaUrl,
    string $model,
    array $modelOptions = [],
    int $timeout = 600,
    ?LLMClient $client = null
);
$results = $worker->Execute(
    string $pagesDir,
    string $ocrResultsDir,
    array $imageResults,
    string $outputDir
): array;
```

**Output:** Array of Markdown files:

```php
[
    ['page' => 'page_001', 'file' => '/path/to/page_001.md', 'markdown' => '# Content...']
]
```

### CompileDocumentWorker

Combines all page Markdown files into a single document.

```php
$worker = new CompileDocumentWorker(
    string $title = 'Document',
    bool $withTableOfContents = false,
    bool $imagePathCorrection = false
);
$worker->Execute(array $markdownResults, string $outputFile, string $tempDir);
```

**Output:** Single Markdown file with optional table of contents

## CLI Scripts

### omnibus_ocr.php (Main Orchestrator)

Complete pipeline runner supporting all stages.

```bash
php omnibus_ocr.php --input <pdf_or_dir> [options]

Options:
  -i, --input              Path to input PDF file or directory with PNG images
  -o, --output-dir         Path to the output directory (default: book_export)
      --ollama-server      Ollama server URL (default: http://localhost:11434)
      --ollama-ocr-model   Ollama OCR model name (default: qwen3.5:9b)
      --ollama-img-model   Ollama model for image analysis (default: qwen3.5:32b)
      --dpi                DPI for PDF rendering (default: 300)
  -f, --first-page         First page to process (1-based, default: 1)
  -l, --last-page          Last page to process (0 = all, default: 0)
  -a, --all                Run all steps
  -e, --explode-pdf        Explode PDF pages to images
  -t, --extract-text       Perform OCR text extraction
  -p, --extract-pictures   Extract pictures from pages
  -m, --format-markdown    Format markdown with LLM
  -c, --compile-document   Compile final document
```

**Steps:**

1. PDF explosion (if input is PDF)
2. Page copying (if input is directory)
3. Text extraction (optional)
4. Picture detection (optional)
5. Markdown formatting (optional)
6. Document compilation (optional)

### png_book_ocr.php

Processes pre-rendered PNG pages (skips ExplodePdfWorker step).

### ocr.php

Simple OCR-only pipeline without image extraction.

### deepseek_ocr.php

A dedicated script for fast conversion of PDF or PNG pages to Markdown using DeepSeek-OCR via Ollama.

- Uses only the DeepSeek-OCR model (hardcoded in the script).
- Does not support the full pipeline: image detection, markdown formatting, and document compilation stages are omitted.
- Does not use Worker classes for OCR; instead, it calls OllamaClient directly for each page.
- For each page, sends a special prompt: `<|grounding|>Convert the document to markdown.`
- Does not support model selection or additional pipeline stages.

This script is intended for the fastest possible Markdown extraction from documents, skipping all intermediate steps and extra processing.

## Core Components

### config.php

Configuration loaded from environment variables:

```php
Config::$ollamaServer      // OLLAMA_SERVER or http://localhost:11434
Config::$ollamaOcrModel    // OLLAMA_OCR_MODEL or qwen3.5:9b
Config::$ollamaImgModel    // OLLAMA_IMG_MODEL or qwen3.5:32b
Config::$imageFormat      // IMAGE_FORMAT or png
Config::$imageDpi         // IMAGE_DPI or 300
Config::$timeout          // Request timeout in seconds
```

### Arguments

CLI argument parsing with support for short/long options and validation.

```php
Arguments::Set([
    'input' => [
        'short' => 'i',
        'long' => 'input',
        'description' => 'Path to input file',
        'required' => true
    ],
    'output' => [
        'short' => 'o',
        'long' => 'output',
        'description' => 'Output path',
        'required' => false,
        'default' => 'output'
    ]
]);

Arguments::Parse();
$value = Arguments::GetValue('input');
```

### OllamaClient

HTTP client for Ollama API communication.

```php
$client = new OllamaClient(string $apiUrl, string $modelName, string $apiKey = '');
$client->SetModelOptions(['temperature' => 0.2, 'num_ctx' => 4096]);
$response = $client->Prompt('api/chat', ['model' => 'qwen3.5:9b', 'messages' => [...]]);
```

### LLMClient Interface

Interface for LLM clients enabling dependency injection and testing.

```php
interface LLMClient {
    public function __construct(string $apiUrl, string $modelName, string $apiKey = '');
    public function GetApiUrl(): string;
    public function GetModelName(): string;
    public function SetModelOptions(array $options): void;
    public function GetModelsList(): array;
    public function Prompt(string $endpoint, array $data);
    public function Generate(string $prompt, array $options = []): string;
}
```

### Logger

Logging facade with console output.

```php
Logger::Stdout()->Info("Processing page...");
Logger::Stdout()->Error("Failed to process: " . $e->getMessage());
Logger::Stderr()->Warning("Optional parameter not set");
```

## Testing

Tests use mock integration pattern with `MockOllamaClient` and `IntegrationOllamaClient`.

```bash
# Mock mode (default)
./vendor/bin/phpunit tests

# Real Ollama mode
OLLAMA_MODE=real ./vendor/bin/phpunit tests
```

Workers accept optional `?LLMClient $client` parameter for dependency injection in tests.

## Directory Structure

```text
backend/
├── config.php              # Configuration
├── Core/
│   ├── Arguments.php       # CLI argument parsing
│   ├── Logger.php         # Logging
│   └── Mc/
│       ├── Alpaca/
│       │   ├── LLMClient.php      # LLM interface
│       │   ├── OllamaClient.php   # Ollama implementation
│       │   └── ...
│       └── Http.php        # HTTP client
├── Worker/
│   ├── ExplodePdfWorker.php
│   ├── TextDetectWorker.php
│   ├── ImageDetectWorker.php
│   ├── FormatMarkdownWorker.php
│   └── CompileDocumentWorker.php
└── cli/
    ├── omnibus_ocr.php     # Main orchestrator
    ├── png_book_ocr.php   # PNG processing
    ├── ocr.php           # OCR only
    └── deepseek_ocr.php   # DeepSeek variant

tests/
├── bootstrap.php
├── BaseWorkerTest.php
├── MockOllamaClient.php
├── IntegrationOllamaClient.php
├── phpunit.xml
└── Worker/
    ├── ExplodePdfWorkerTest.php
    ├── TextDetectWorkerTest.php
    ├── ImageDetectWorkerTest.php
    ├── FormatMarkdownWorkerTest.php
    ├── ValidateDataWorkerTest.php
    └── CompileDocumentWorkerTest.php
```
