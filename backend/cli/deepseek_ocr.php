<?php

/**
 * OCR processing script for DeepSeek using Ollama models.
 * Usage: php deepseek_ocr.php --input <pdf_or_dir> [options]
 * 
 * Mention: DeepSeek-OCR use specific prompt:
 * > "<image>\n<|grounding|>Convert the document to markdown."
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
 * Creates the project directory structure for OCR processing.
 * @param string $baseDir Base directory for the project
 * @param string $projectName Name of the project
 * @param bool $resume Whether to resume an existing project
 * @return array{markdown: string, ocr: string, pages: string, project: string, visuals: string} Paths to the created directories
 */
function createProjectStructure(string $baseDir, string $projectName, bool $resume = false): array
{
    $projectDir = $baseDir . DIRECTORY_SEPARATOR . $projectName;
    $pagesDir = $projectDir . DIRECTORY_SEPARATOR . 'pages';
    $visualsDir = $projectDir . DIRECTORY_SEPARATOR . 'visuals';
    $ocrDir = $projectDir . DIRECTORY_SEPARATOR . 'ocr';
    $markdownDir = $projectDir . DIRECTORY_SEPARATOR . 'markdown';

    foreach ([$pagesDir, $visualsDir, $ocrDir, $markdownDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        } elseif (!$resume) {
            // Clear existing files if not resuming
            array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*'));
        }
    }

    return [
        'project' => $projectDir,
        'pages' => $pagesDir,
        'visuals' => $visualsDir,
        'ocr' => $ocrDir,
        'markdown' => $markdownDir
    ];
}
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

/**
 * Cleans up the OCR text by normalizing line breaks, merging hyphenated words,
 * and removing page numbers.
 * @param string $text Raw OCR text to be cleaned
 * @return string Cleaned OCR text
 */
function cleanMarkdown(string $text): string
{
    // 1. Normalize line breaks and trim trailing whitespace
    $clean = preg_replace("/\r\n|\r/", "\n", $text);
    $clean = preg_replace("/[ \t]+$/m", "", $clean); // Trim invisible spaces at the end of lines

    // 2. Merge word-break hyphenations (with support for Cyrillic /u)
    // Consider that after a hyphen there may be spaces before the line break
    $clean = preg_replace("/([а-яА-Яa-zA-Z])-\s*\n\s*([а-яА-Яa-zA-Z])/u", "$1$2", $clean);

    // 3. Remove page numbers (more cautious)
    // Remove only isolated numbers (1-3 digits), if they are not part of a list
    $clean = preg_replace("/^\s*\d{1,3}\s*$/m", "", $clean);

    // 4. Collapse excessive empty lines
    $clean = preg_replace("/\n{3,}/", "\n\n", $clean);

    return trim($clean);
}

/**
 * Compiles multiple markdown pages into a single document.
 * @param array $markdownPages List of markdown page file paths
 * @param string $outputPath Path to save the compiled document
 */
function compileDocument(array $markdownPages, string $outputPath)
{
    $compiled = "# Compiled Document\n\n";
    foreach ($markdownPages as $page) {
        $content = file_get_contents($page);
        $cleanContent = cleanMarkdown($content);
        $compiled .= "<!-- Page: " . basename($page) . " -->\n\n";
        $compiled .= $cleanContent . "\n\n";
    }
    file_put_contents($outputPath, $compiled);
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
    ],
    "language" => [
        'short' => null,
        'long' => 'lang',
        'description' => 'Language of the document (e.g., "english", "russian")',
        'required' => false,
        'default' => 'english'
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
$language = Arguments::GetValue('language');

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

$dirs = createProjectStructure($outputDir, '_temp', false);

$isPdf = is_file($inputPath) && strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'pdf';
$tempDir = $dirs['project'];
$pagesDir = $dirs['pages'];
$visualsDir = $dirs['visuals'];
$ocrDir = $dirs['ocr'];
$markdownDir = $dirs['markdown'];

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

$ocrClient = new OllamaClient($server, "deepseek-ocr");
$maxRetries = 2;
$firstPage = max(1, $firstPage);
$lastPage = $lastPage !== null ? max($firstPage, $lastPage) : count($pageFiles);

// skip pages that are outside of the specified range
$pageFiles = array_slice($pageFiles, $firstPage - 1, $lastPage - $firstPage + 1);

$pageNumber = $firstPage;

foreach ($pageFiles as $pageFile) {
    Logger::Stdout()->Info("Processing page {$pageNumber}: {$pageFile}...");
    $pageName = pathinfo($pageFile, PATHINFO_FILENAME);
    $image = file_get_contents($pageFile);
    $imageBase64 = base64_encode($image);
    $response = null;
    $retry = 0;

    while ($retry <= $maxRetries && empty($response['message']['content'])) {
        $result = $ocrClient->Prompt("api/chat", [
            "model" => $ocrClient->GetModelName(),
            "messages" => [
                [
                    "role" => "user",
                    "content" => "<|grounding|>Convert the document to markdown. Language: {$language}",
                    "images" => [$imageBase64]
                ]
            ],
            "stream" => false
        ]);
        $response = json_decode($result, JSON_OBJECT_AS_ARRAY);

        if (empty($response['message']['content'])) {
            $error = isset($response['error']) ? $response['error'] : 'Unknown error';
            Logger::Stdout()->Error("Received empty content from Ollama for page {$pageNumber}. Error: {$error}. Retrying... (Attempt {$retry}/{$maxRetries})");
            Logger::Stdout()->Error("result: {$result}");
        }
        $retry++;
    }
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

// clean and compile document
$markdownPages = glob($ocrDir . DIRECTORY_SEPARATOR . "*.md");
if (!empty($markdownPages)) {
    Logger::Stdout()->Info("Step: Compiling markdown pages into a single document...");
    $outputPath = $dirs['project'] . DIRECTORY_SEPARATOR . "full_book.md";
    compileDocument($markdownPages, $outputPath);
    Logger::Stdout()->Info("Compiled document saved to: {$outputPath}");
} else {
    Logger::Stdout()->Error("No markdown pages found to compile.");
}

Logger::Stdout()->Info("*** COMPLETED SUCCESSFULLY ***");

