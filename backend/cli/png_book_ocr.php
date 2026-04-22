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

function collectPendingPngPages(array $sourceFiles, string $markdownDir): array
{
    $pending = [];
    foreach ($sourceFiles as $file) {
        $pageName = pathinfo($file, PATHINFO_FILENAME);
        $markdownFile = $markdownDir . DIRECTORY_SEPARATOR . $pageName . '.md';
        if (!file_exists($markdownFile)) {
            $pending[] = $file;
        }
    }

    return $pending;
}

Arguments::Set([
    'help' => [
        'short' => 'h',
        'long' => 'help',
        'description' => 'Show this help message and exit',
        'required' => false
    ],
    'input' => [
        'short' => 'i',
        'long' => 'input',
        'description' => 'Path to the input directory with PNG files',
        'required' => true
    ],
    'output' => [
        'short' => 'o',
        'long' => 'output',
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
    ],
    'resume' => [
        'short' => 'r',
        'long' => 'resume',
        'description' => 'Resume processing and skip pages with existing markdown output',
        'required' => false
    ]
]);

Arguments::Parse();

$usage = 'Usage: php ' . basename(__FILE__) . ' --input <input_dir> [options]';

if (Arguments::GetValue('help')) {
    echo ($usage . "\n");
    echo (Arguments::Help());
    exit(0);
}

$inputDir = Arguments::GetValue('input');
$outputDir = Arguments::GetValue('output');
$server = Arguments::GetValue('ollama-server');
$ocrModel = Arguments::GetValue('ollama-ocr-model');
$imgModel = Arguments::GetValue('ollama-img-model');
$resume = Arguments::GetValue('resume') === true;

if (empty($inputDir)) {
    Logger::Stdout()->Error('Error: Input directory path is required.');
    echo $usage . PHP_EOL;
    echo Arguments::Help();
    exit(1);
}

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
    $target = $pagesDir . DIRECTORY_SEPARATOR . $pageName . '.png';
    if ($resume && file_exists($target)) {
        continue;
    }
    copy($file, $target);
}

$filesToProcess = $files;
if ($resume) {
    $filesToProcess = collectPendingPngPages($files, $markdownDir);
    Logger::Stdout()->Info("Resume mode: pending pages " . count($filesToProcess) . "/" . count($files));
}

$processingPagesDir = $pagesDir;
if ($resume) {
    $processingPagesDir = $outputDir . DIRECTORY_SEPARATOR . 'pending_pages';
    if (!is_dir($processingPagesDir)) {
        mkdir($processingPagesDir, 0755, true);
    }
    array_map('unlink', glob($processingPagesDir . DIRECTORY_SEPARATOR . '*.png'));
    foreach ($filesToProcess as $file) {
        $pageName = pathinfo($file, PATHINFO_FILENAME);
        copy($pagesDir . DIRECTORY_SEPARATOR . $pageName . '.png', $processingPagesDir . DIRECTORY_SEPARATOR . $pageName . '.png');
    }
}

$markdownResults = [];
if (!empty($filesToProcess)) {
    Logger::Stdout()->Info("Step 1/3: Detecting images on pages...");
    $imageResults = $imageWorker->Execute($processingPagesDir, $visualsDir);

    Logger::Stdout()->Info("Step 2/3: Formatting markdown...");
    $markdownResults = $formatWorker->Execute($processingPagesDir, $visualsDir, $imageResults, $markdownDir);
} else {
    Logger::Stdout()->Info("Resume: no pending pages for processing.");
}

if (empty($markdownResults)) {
    $markdownResults = glob($markdownDir . DIRECTORY_SEPARATOR . '*.md');
    natsort($markdownResults);
    $markdownResults = array_values($markdownResults);
}

Logger::Stdout()->Info("Step 3/3: Assembling full book...");
$compileWorker = new CompileDocumentWorker("Book OCR");
$compileWorker->Execute($markdownResults, $outputDir . DIRECTORY_SEPARATOR . 'full_book.md', $outputDir);

Logger::Stdout()->Info("SUCCESS!");
Logger::Stdout()->Info("Individual pages: {$markdownDir}/");
Logger::Stdout()->Info("Full book: {$outputDir}/full_book.md");
