<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

include_once __DIR__ . "/../config.php";

use \Core\Arguments;
use \Core\Mc\Logger;
use \Worker\ImageDetectWorker;
use \Worker\FormatMarkdownWorker;
use \Worker\CompileDocumentWorker;

Arguments::Set([
    'help' => [
        'short' => 'h',
        'long' => 'help',
        'description' => 'Show this help message and exit',
        'required' => false
    ],
    'input-dir' => [
        'short' => null,
        'long' => 'input-dir',
        'description' => 'Path to the input directory with PNG files',
        'required' => true
    ],
    'output-dir' => [
        'short' => null,
        'long' => 'output-dir',
        'description' => 'Path to the output directory',
        'required' => false,
        'default' => 'output_dir'
    ],
    'ollama-server' => [
        'short' => null,
        'long' => 'ollama-server',
        'description' => 'Ollama server URL',
        'required' => false,
        'default' => Config::$ollamaServer
    ],
    'ollama-ocr-model' => [
        'short' => null,
        'long' => 'ollama-ocr-model',
        'description' => 'Ollama OCR model name',
        'required' => false,
        'default' => Config::$ollamaOcrModel
    ],
    'ollama-img-model' => [
        'short' => null,
        'long' => 'ollama-img-model',
        'description' => 'Ollama model for image analysis',
        'required' => false,
        'default' => Config::$ollamaImgModel
    ]
]);

Arguments::Parse();

if (Arguments::GetValue('help')) {
    echo ("Usage: php png_book_ocr.php --input-dir <input_dir> [options]");
    echo (Arguments::Help());
    exit(0);
}

$inputDir = Arguments::GetValue('input-dir');
$outputDir = Arguments::GetValue('output-dir');
$server = Arguments::GetValue('ollama-server');
$ocrModel = Arguments::GetValue('ollama-ocr-model');
$imgModel = Arguments::GetValue('ollama-img-model');

if (!is_dir($inputDir)) {
    Logger::Stdout()->Error("Error: Input directory not found.");
    exit(1);
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$pagesDir = $outputDir . DIRECTORY_SEPARATOR . 'pages';
$visualsDir = $outputDir . DIRECTORY_SEPARATOR . 'visuals';
$markdownDir = $outputDir . DIRECTORY_SEPARATOR . 'markdown';

foreach ([$pagesDir, $visualsDir, $markdownDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$files = glob($inputDir . DIRECTORY_SEPARATOR . "*.png");
natsort($files);
$files = array_values($files);

if (empty($files)) {
    Logger::Stdout()->Error("No PNG files found.");
    exit(1);
}

Logger::Stdout()->Info("Found " . count($files) . " pages. Starting processing...");

$imageWorker = new ImageDetectWorker($server, $imgModel);
if (!$imageWorker->checkModelExists()) {
    Logger::Stdout()->Error("Model '{$imgModel}' is not installed. Run: ollama pull {$imgModel}");
    exit(1);
}

$formatWorker = new FormatMarkdownWorker($server, $ocrModel);
if (!$formatWorker->checkModelExists()) {
    Logger::Stdout()->Error("Model '{$ocrModel}' is not installed. Run: ollama pull {$ocrModel}");
    exit(1);
}

Logger::Stdout()->Info("Copying pages to working directory...");
foreach ($files as $index => $file) {
    $pageName = pathinfo($file, PATHINFO_FILENAME);
    copy($file, $pagesDir . DIRECTORY_SEPARATOR . $pageName . '.png');
}

Logger::Stdout()->Info("Step 1/3: Detecting images on pages...");
$imageResults = $imageWorker->Execute($pagesDir, $visualsDir);

Logger::Stdout()->Info("Step 2/3: Formatting markdown...");
$markdownResults = $formatWorker->Execute($pagesDir, $visualsDir, $imageResults, $markdownDir);

Logger::Stdout()->Info("Step 3/3: Assembling full book...");
$compileWorker = new CompileDocumentWorker("Book OCR");
$compileWorker->Execute($markdownResults, $outputDir . DIRECTORY_SEPARATOR . 'full_book.md', $outputDir);

Logger::Stdout()->Info("SUCCESS!");
Logger::Stdout()->Info("Individual pages: {$markdownDir}/");
Logger::Stdout()->Info("Full book: {$outputDir}/full_book.md");
