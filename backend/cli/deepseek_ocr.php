<?php

/**
 * OCR processing script for DeepSeek using Ollama models.
 * Usage: php deepseek_ocr.php --input <pdf_or_dir> [options]
 * 
 * Mention: DeepSeek-OCR use specific prompt:
 * > "<image>\n<|grounding|>Convert the document to markdown."
 * 
 * As a result the model produces markdown output with the following structure:
 * 
 * 
 * 
 * This prompt allows to optimize the OCR process.
 */
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

include_once __DIR__ . "/../config.php";

use \Core\Arguments;
use \Core\Mc\Logger;
use \Core\Mc\Alpaca\OllamaClient;
use \Worker\ExplodePdfWorker;

// ---------- Functions ----------
/**
 * Extracts image fragments based on <image> tags in the text
 * @param string $sourcePath Path to the source PNG
 * @param string $ocrMarkdown Text obtained from LLM
 * @param string $outputFolder Folder to save the extracted fragments
 * @return array List of created files and their corresponding tags
 */
function extractVisualsWithImagick(string $sourcePath, string $ocrMarkdown, string $outputFolder): array
{
    if (!file_exists($sourcePath)) return [];

    $im = new Imagick($sourcePath);
    $width = $im->getImageWidth();
    $height = $im->getImageHeight();
    
    // Regex for <image>[ymin, xmin, ymax, xmax](label)</image>
    $pattern = '/<image>\[(\d+),\s*(\d+),\s*(\d+),\s*(\d+)\]\((.*?)\)<\/image>/i';
    preg_match_all($pattern, $ocrMarkdown, $matches, PREG_SET_ORDER);

    $extractedFiles = [];

    foreach ($matches as $index => $match) {
        // $match[1] = ymin, [2] = xmin, [3] = ymax, [4] = xmax, [5] = label
        $ymin = (int)$match[1];
        $xmin = (int)$match[2];
        $ymax = (int)$match[3];
        $xmax = (int)$match[4];
        $label = $match[5] ?: "visual";

        // Translate from 0-1000 to pixels
        $realX = ($xmin / 1000) * $width;
        $realY = ($ymin / 1000) * $height;
        $realW = (($xmax - $xmin) / 1000) * $width;
        $realH = (($ymax - $ymin) / 1000) * $height;

        // Clone the main object to avoid modifying the original during cropping
        $crop = clone $im;
        
        // cropImage(width, height, x, y)
        $crop->cropImage((int)$realW, (int)$realH, (int)$realX, (int)$realY);
        $crop->setImageFormat("png");

        $fileName = sprintf("visual_%03d_%s.png", $index + 1, preg_replace('/[^a-z0-9]/i', '_', $label));
        $fullPath = $outputFolder . DIRECTORY_SEPARATOR . $fileName;
        
        if ($crop->writeImage($fullPath)) {
            $extractedFiles[] = [
                'tag' => $match[0],
                'path' => $fullPath,
                'name' => $fileName
            ];
        }
        
        $crop->clear();
        $crop->destroy();
    }

    $im->clear();
    $im->destroy();

    return $extractedFiles;
}
// ---------- Main Script ----------

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
        'description' => 'Path to input PDF file or directory with PNG images',
        'required' => true
    ],
    'output-dir' => [
        'short' => 'o',
        'long' => 'output-dir',
        'description' => 'Path to the output directory',
        'required' => false,
        'default' => 'book_export'
    ],
    'ollama-server' => [
        'short' => null,
        'long' => 'ollama-server',
        'description' => 'Ollama server URL',
        'required' => false,
        'default' => Config::$ollamaServer
    ],
    'dpi' => [
        'short' => null,
        'long' => 'dpi',
        'description' => 'DPI for PDF rendering',
        'required' => false,
        'default' => 300
    ],
    "first-page" => [
        'short' => "f",
        'long' => 'first-page',
        'description' => 'Starting page number for processing (1-based)',
        'required' => false,
        'default' => 1
    ],
    "last-page" => [
        'short' => "l",
        'long' => 'last-page',
        'description' => 'Ending page number for processing (inclusive)',
        'required' => false,
        'default' => null
    ]
]);

Arguments::Parse();

if (Arguments::GetValue('help')) {
    echo ("Usage: php deepseek_ocr.php --input <pdf_or_dir> [options]\n");
    echo (Arguments::Help());
    exit(0);
}

$inputPath = Arguments::GetValue('input');
$outputDir = Arguments::GetValue('output-dir');
$server = Arguments::GetValue('ollama-server');
$dpi = (int) Arguments::GetValue('dpi');
$firstPage = (int) Arguments::GetValue('first-page');
$lastPage = Arguments::GetValue('last-page') !== null ? (int) Arguments::GetValue('last-page') : null;

if (empty($inputPath)) {
    Logger::Stdout()->Error("Error: Input path is required.");
    echo ("Usage: php deepseek_ocr.php --input <pdf_or_dir> [options]\n");
    echo (Arguments::Help());
    exit(1);
}

if (!file_exists($inputPath) && !is_dir($inputPath)) {
    Logger::Stdout()->Error("Error: Input path not found.");
    exit(1);
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$isPdf = is_file($inputPath) && strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'pdf';
$tempDir = $outputDir . DIRECTORY_SEPARATOR . '_temp';
$pagesDir = $tempDir . DIRECTORY_SEPARATOR . 'pages';
$visualsDir = $tempDir . DIRECTORY_SEPARATOR . 'visuals';
$ocrDir = $tempDir . DIRECTORY_SEPARATOR . 'ocr';
$markdownDir = $tempDir . DIRECTORY_SEPARATOR . 'markdown';

foreach ([$pagesDir, $visualsDir, $ocrDir, $markdownDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

if ($isPdf) {
    Logger::Stdout()->Info("Step: Exploding PDF to pages (DPI: {$dpi})...");
    $explodeWorker = new ExplodePdfWorker(ExplodePdfWorker::PNG, $dpi);
    $pages = $explodeWorker->Execute($inputPath, $pagesDir);
    Logger::Stdout()->Info("Extracted " . count($pages) . " pages.");
} elseif (is_dir($inputPath)) {
    Logger::Stdout()->Info("Step: Copying pages from input directory...");
    $files = glob($inputPath . DIRECTORY_SEPARATOR . "*.png");
    natsort($files);
    foreach ($files as $index => $file) {
        $pageName = sprintf("page_%03d", $index + 1);
        copy($file, $pagesDir . DIRECTORY_SEPARATOR . $pageName . '.png');
    }
    Logger::Stdout()->Info("Copied " . count($files) . " pages.");
} else {
    Logger::Stdout()->Error("Error: PDF explode is disabled and input is not a directory.");
    exit(1);
}

$pageFiles = glob($pagesDir . DIRECTORY_SEPARATOR . "*.png");
if (empty($pageFiles)) {
    Logger::Stdout()->Error("No pages found to process.");
    exit(1);
}

// skip pages that are outside of the specified range


$ocrClient = new OllamaClient($server, "deepseek-ocr");
$maxRetries = 2;
$firstPage = max(1, $firstPage);
$lastPage = $lastPage !== null ? max($firstPage, $lastPage) : count($pageFiles);
$pageFiles = array_slice($pageFiles, $firstPage - 1, $lastPage - $firstPage + 1);
$pageNumber = $firstPage;

foreach ($pageFiles as $pageFile) {
    Logger::Stdout()->Info("Processing page {$pageNumber}: {$pageFile}...");
    $image = file_get_contents($pageFile);
    $imageBase64 = base64_encode($image);
    $response = null;
    $retry = 0;

    while ($retry <= $maxRetries && empty($response['message']['content'])) {
        $response = $ocrClient->Prompt("api/chat", [
            "model" => $ocrClient->GetModelName(),
            "messages" => [
                [
                    "role" => "user",
                    "content" => "<|grounding|>Convert the document to markdown.",
                    "images" => [$imageBase64]
                ]
            ],
            "stream" => false
        ]);
        if (empty($response['message']['content'])) {
            $error = isset($response['error']) ? $response['error'] : 'Unknown error';
            Logger::Stdout()->Error("Received empty content from Ollama for page {$pageNumber}. Error: {$error}. Retrying... (Attempt {$retry}/{$maxRetries})");
        }
        $retry++;
    }
    file_put_contents($ocrDir . DIRECTORY_SEPARATOR . $pageName . '.log', json_encode($response, JSON_PRETTY_PRINT));
    if(empty($response['message']['content'])) {
        Logger::Stdout()->Error("Failed to get valid response from Ollama for page {$pageNumber} after {$maxRetries} attempts. Skipping page.");
        continue;
    }
    else{
        Logger::Stdout()->Info("Successfully processed page {$pageNumber}.");
        file_put_contents($ocrDir . DIRECTORY_SEPARATOR . $pageName . '.md', $response['message']['content']);
    }
    $pageNumber++;
}

Logger::Stdout()->Info("*** COMPLETED SUCCESSFULLY ***");
if ($steps['compile-document']) {
    Logger::Stdout()->Info("Final document: {$outputDir}/full_book.md");
}
