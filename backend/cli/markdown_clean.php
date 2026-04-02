<?php

include_once __DIR__ . '/../config.php';

use \Core\Arguments;
use \Core\Mc\Alpaca\OllamaClient;
use \Core\Mc\Logger;

$logger = Logger::Stdout();

$PROMPT = '### ROLE: Expert Chess Book Editor & OCR Specialist

### TASK:
Your goal is to clean and format the provided OCR text from a chess book. 
Maintain the original meaning while fixing scanning artifacts.

### 1. TEXT CLEANUP RULES:
- **De-hyphenation:** Merge words split by line breaks (e.g., "stra-tegy" -> "strategy").
- **Paragraphs:** Merge lines that belong to the same sentence. Only create a new paragraph if there is a clear thematic break or a double newline in the source.
- **Artifacts:** Silently remove page numbers, running headers (book titles at the top/bottom), and random OCR noise (e.g., "|", "_", "°").
- **Language:** The book is in [RUSSIAN/ENGLISH]. Maintain the original tone.

### 2. CHESS NOTATION RULES (CRITICAL):
- **Verification:** If a move looks like an OCR error (e.g., "еб" instead of "e6", "0-0-0" with Cyrillic "О"), fix it to standard algebraic notation.
- **LaTeX:** Wrap all moves and variations in LaTeX inline blocks if they are not already. Example: $1. e4 e5$.
- **Consistency:** Ensure pieces are represented by their standard letters (K, Q, R, B, N in English or К, Ф, Л, С, К в русском). Do not mix languages.
- **Diagram References:** If you see text like "см. диагр. 45" or "<image>...</image>", DO NOT delete it. Keep all image placeholders exactly where they are.

### 3. CONSTRAINTS:
- DO NOT summarize.
- DO NOT add your own commentary.
- DO NOT "hallucinate" better moves for the players.
- If a sentence is completely garbled and impossible to fix, wrap it in <FIXME>...</FIXME> tags.

### OUTPUT FORMAT:
Return ONLY the cleaned Markdown text.';

Arguments::Set([
    "input" => [
        "short" => "i",
        "long" => "input",
        "description" => "Path to the input OCR text file (Markdown format).",
        "required" => true
    ],
    "output" => [
        "short" => "o",
        "long" => "output",
        "description" => "Path to save the cleaned Markdown text file.",
        "required" => false,
        "default" => __DIR__ . "/cleaned_output.md"
    ],
    "model" => [
        "short" => "m",
        "long" => "model",
        "description" => "Language model to use for cleaning (e.g., '" . \Config::$ollamaOcrModel . "').",
        "required" => false,
        "default" => \Config::$ollamaOcrModel
    ],
    "ollamaServer" => [
        "short" => null,
        "long" => "ollama-server",
        "description" => "Ollama server host (default: " . \Config::$ollamaServer . ").",
        "required" => false,
        "default" => \Config::$ollamaServer
    ]
]);

Arguments::Parse();

if(empty(Arguments::$input)) {
    $logger->Error("Input file path is required. Use -i or --input to specify it.");
    echo "Usage: php {$argv[0]} -i <input_file> [<options>]\n";
    echo Arguments::Help();
    exit(1);
}

$inputPath = Arguments::Get("input");
$outputPath = Arguments::Get("output");
$model = Arguments::Get("model");
$ollamaServer = Arguments::Get("ollamaServer");

if(!file_exists($inputPath)) {
    $logger->Error("Input file not found: {$inputPath}");
    exit(1);
}
$inputMarkdown = file_get_contents($inputPath);

$ollama = new OllamaClient($ollamaServer);
$logger->Info("Sending OCR text to Ollama for cleaning using model '{$model}'...");

$ollama->SetModelName($model);
$ollama->SetModelOptions([
    'temperature' => 0.1,
    'num_predict' => 4096,
    'num_ctx' => 4096
]);

