<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

include_once __DIR__ . "/../config.php";

use \Core\Arguments;
use \Core\Mc\Logger;
use \Worker\ExplodePdfWorker;
use \Worker\ImageDetectWorker;
use \Worker\TextDetectWorker;
use \Worker\FormatMarkdownWorker;
use \Worker\CompileDocumentWorker;

function buildPendingPagesDir(array $sourcePageFiles, string $pendingDir, callable $shouldInclude): array
{
    if (!is_dir($pendingDir)) {
        mkdir($pendingDir, 0755, true);
    }

    array_map('unlink', glob($pendingDir . DIRECTORY_SEPARATOR . '*.png'));

    $pending = [];
    foreach ($sourcePageFiles as $pageFile) {
        $pageName = pathinfo($pageFile, PATHINFO_FILENAME);
        if (!$shouldInclude($pageName)) {
            continue;
        }

        $target = $pendingDir . DIRECTORY_SEPARATOR . basename($pageFile);
        copy($pageFile, $target);
        $pending[] = $target;
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
        'description' => 'Path to input PDF file or directory with PNG images',
        'required' => true
    ],
    'output' => [
        'short' => 'o',
        'long' => 'output',
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
    'ollama-checker-model' => [
        'short' => null,
        'long' => 'ollama-checker-model',
        'description' => 'Ollama model for checking and validation results',
        'required' => false,
        'default' => Config::$ollamaCheckerModel
    ],
    'dpi' => [
        'short' => null,
        'long' => 'dpi',
        'description' => 'DPI for PDF rendering',
        'required' => false,
        'default' => 300
    ],
    'first-page' => [
        'short' => 'f',
        'long' => 'first-page',
        'description' => 'First page to process (1-based index)',
        'required' => false,
        'default' => 1
    ],
    'last-page' => [
        'short' => 'l',
        'long' => 'last-page',
        'description' => 'Last page to process (1-based index)',
        'required' => false,
        'default' => 0
    ],
    'all' => [
        'short' => 'a',
        'long' => 'all',
        'description' => 'Run all steps (explode, extract-text, extract-pictures, format-markdown, compile)',
        'required' => false,
  //      'default' => false
    ],
    'explode-pdf' => [
        'short' => 'e',
        'long' => 'explode-pdf',
        'description' => 'Whether to explode PDF pages to images (if input is PDF)',
        'required' => false,
//        'default' => false
    ],
    'extract-text' => [
        'short' => 't',
        'long' => 'extract-text',
        'description' => 'Whether to perform OCR text extraction',
        'required' => false,
//        'default' => false
    ],
    'extract-pictures' => [
        'short' => 'p',
        'long' => 'extract-pictures',
        'description' => 'Whether to extract pictures from pages',
        'required' => false,
//        'default' => false
    ],
    'format-markdown' => [
        'short' => 'm',
        'long' => 'format-markdown',
        'description' => 'Whether to format markdown with LLM',
        'required' => false,
//        'default' => false
    ],
    'compile-document' => [
        'short' => 'c',
        'long' => 'compile-document',
        'description' => 'Whether to compile final document',
        'required' => false,
//        'default' => false
    ],
    'resume' => [
        'short' => 'r',
        'long' => 'resume',
        'description' => 'Resume processing and skip already generated outputs',
        'required' => false
    ]
]);

Arguments::Parse();

$usage = 'Usage: php ' . basename(__FILE__) . ' --input <pdf_or_dir> [options]';

if (Arguments::GetValue('help')) {
    echo $usage . PHP_EOL;
    echo Arguments::Help();
    exit(0);
}

$inputPath = Arguments::GetValue('input');
$outputDir = Arguments::GetValue('output');
$server = Arguments::GetValue('ollama-server');
$ocrModel = Arguments::GetValue('ollama-ocr-model');
$imgModel = Arguments::GetValue('ollama-img-model');
$checkerModel = Arguments::GetValue('ollama-checker-model');
$dpi = (int) Arguments::GetValue('dpi');
$firstPage = (int) Arguments::GetValue('first-page');
$lastPage = (int)Arguments::GetValue('last-page');
$resume = Arguments::GetValue('resume') === true;

if(empty($inputPath)) {
    Logger::Stdout()->Error("Error: Input path is required.");
    echo $usage . PHP_EOL;
    echo Arguments::Help();
    exit(1);
}

$runAll = Arguments::GetValue('all') === true;
$steps = [
    'explode-pdf' => $runAll || Arguments::GetValue('explode-pdf') === true,
    'extract-text' => $runAll || Arguments::GetValue('extract-text') === true,
    'extract-pictures' => $runAll || Arguments::GetValue('extract-pictures') === true,
    'format-markdown' => $runAll || Arguments::GetValue('format-markdown') === true,
    'compile-document' => $runAll || Arguments::GetValue('compile-document') === true,
];

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
$selectedPagesDir = $tempDir . DIRECTORY_SEPARATOR . 'selected_pages';
$visualsDir = $tempDir . DIRECTORY_SEPARATOR . 'visuals';
$ocrDir = $tempDir . DIRECTORY_SEPARATOR . 'ocr';
$markdownDir = $tempDir . DIRECTORY_SEPARATOR . 'markdown';

foreach ([$pagesDir, $selectedPagesDir, $visualsDir, $ocrDir, $markdownDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

if ($steps['explode-pdf'] && $isPdf) {
    $existingPages = glob($pagesDir . DIRECTORY_SEPARATOR . '*.png');
    if ($resume && !empty($existingPages)) {
        Logger::Stdout()->Info("Step: Resume enabled, reusing existing exploded pages (" . count($existingPages) . ").");
    } else {
        Logger::Stdout()->Info("Step: Exploding PDF to pages (DPI: {$dpi})...");
        $explodeWorker = new ExplodePdfWorker(ExplodePdfWorker::PNG, $dpi);
        $pages = $explodeWorker->Execute($inputPath, $pagesDir);
        Logger::Stdout()->Info("Extracted " . count($pages) . " pages.");
    }
} elseif (is_dir($inputPath)) {
    Logger::Stdout()->Info("Step: Copying pages from input directory...");
    $files = glob($inputPath . DIRECTORY_SEPARATOR . "*.png");
    natsort($files);
    $files = array_values($files);
    foreach ($files as $index => $file) {
        $pageName = sprintf("page_%03d", $index + 1);
        $target = $pagesDir . DIRECTORY_SEPARATOR . $pageName . '.png';
        if ($resume && file_exists($target)) {
            continue;
        }
        copy($file, $target);
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

if($lastPage == 0) {
    $lastPage = count($pageFiles);
}

$firstPage = max(1, $firstPage);
$lastPage = min(count($pageFiles), $lastPage);
if ($firstPage > $lastPage) {
    $tmp = $firstPage;
    $firstPage = $lastPage;
    $lastPage = $tmp;
}

$pageFiles = array_values(array_slice($pageFiles, $firstPage - 1, $lastPage - $firstPage + 1));
Logger::Stdout()->Info("Processing pages from $firstPage to $lastPage...");

if (!$resume) {
    $selectedPages = glob($selectedPagesDir . DIRECTORY_SEPARATOR . "*.png");
    foreach ($selectedPages as $selectedPage) {
        unlink($selectedPage);
    }
}

foreach ($pageFiles as $pageFile) {
    $targetFile = $selectedPagesDir . DIRECTORY_SEPARATOR . basename($pageFile);
    if ($resume && file_exists($targetFile)) {
        continue;
    }
    copy($pageFile, $targetFile);
}

$imageResults = [];
$markdownResults = [];
$pendingBaseDir = $tempDir . DIRECTORY_SEPARATOR . 'pending';
if (!is_dir($pendingBaseDir)) {
    mkdir($pendingBaseDir, 0755, true);
}

$selectedPageFiles = glob($selectedPagesDir . DIRECTORY_SEPARATOR . '*.png');
natsort($selectedPageFiles);
$selectedPageFiles = array_values($selectedPageFiles);

if ($steps['extract-pictures']) {
    Logger::Stdout()->Info("Step: Detecting and extracting pictures...");
    $imageWorker = new ImageDetectWorker($server, $imgModel);

    if ($resume) {
        $pendingPages = buildPendingPagesDir(
            $selectedPageFiles,
            $pendingBaseDir . DIRECTORY_SEPARATOR . 'images',
            function (string $pageName) use ($visualsDir): bool {
                $imagesDir = $visualsDir . DIRECTORY_SEPARATOR . $pageName . DIRECTORY_SEPARATOR . 'images';
                return !is_dir($imagesDir) || empty(glob($imagesDir . DIRECTORY_SEPARATOR . '*.png'));
            }
        );

        if (empty($pendingPages)) {
            Logger::Stdout()->Info("Resume: no pending pages for image extraction.");
        } else {
            $imageResults = $imageWorker->Execute($pendingBaseDir . DIRECTORY_SEPARATOR . 'images', $visualsDir);
        }
    } else {
        $imageResults = $imageWorker->Execute($selectedPagesDir, $visualsDir);
    }
}

if ($steps['extract-text']) {
    Logger::Stdout()->Info("Step: Extracting text (OCR)...");
    $ocrWorker = new TextDetectWorker($server, $ocrModel);

    if ($resume) {
        $pendingPages = buildPendingPagesDir(
            $selectedPageFiles,
            $pendingBaseDir . DIRECTORY_SEPARATOR . 'ocr',
            function (string $pageName) use ($ocrDir): bool {
                return !file_exists($ocrDir . DIRECTORY_SEPARATOR . $pageName . '.txt');
            }
        );

        if (empty($pendingPages)) {
            Logger::Stdout()->Info("Resume: no pending pages for OCR.");
        } else {
            $ocrWorker->Execute($pendingBaseDir . DIRECTORY_SEPARATOR . 'ocr', $ocrDir);
        }
    } else {
        $ocrWorker->Execute($selectedPagesDir, $ocrDir);
    }
}

if ($steps['format-markdown']) {
    Logger::Stdout()->Info("Step: Formatting markdown...");
    $formatWorker = new FormatMarkdownWorker($server, $checkerModel);

    if ($resume) {
        $pendingPages = buildPendingPagesDir(
            $selectedPageFiles,
            $pendingBaseDir . DIRECTORY_SEPARATOR . 'markdown',
            function (string $pageName) use ($markdownDir): bool {
                return !file_exists($markdownDir . DIRECTORY_SEPARATOR . $pageName . '.md');
            }
        );

        if (empty($pendingPages)) {
            Logger::Stdout()->Info("Resume: no pending pages for markdown formatting.");
        } else {
            $markdownResults = $formatWorker->Execute($pendingBaseDir . DIRECTORY_SEPARATOR . 'markdown', $ocrDir, $imageResults, $markdownDir);
        }
    } else {
        $markdownResults = $formatWorker->Execute($selectedPagesDir, $ocrDir, $imageResults, $markdownDir);
    }
}

if ($steps['compile-document']) {
    Logger::Stdout()->Info("Step: Compiling document...");

    if ($resume && empty($markdownResults)) {
        $markdownResults = glob($markdownDir . DIRECTORY_SEPARATOR . '*.md');
        natsort($markdownResults);
        $markdownResults = array_values($markdownResults);
    }

    $compileWorker = new CompileDocumentWorker("Full Book OCR");
    $compileWorker->Execute($markdownResults, $outputDir . DIRECTORY_SEPARATOR . 'full_book.md', $tempDir);
}

$hasWork = $steps['explode-pdf'] || $steps['extract-pictures'] || $steps['extract-text'] || $steps['format-markdown'] || $steps['compile-document'];
if (!$hasWork) {
    Logger::Stdout()->Info("No processing steps enabled. Use --all or specific flags.");
    Logger::Stdout()->Info("Available steps: --explode-pdf, --extract-text, --extract-pictures, --format-markdown, --compile-document");
    exit(0);
}

// Logger::Stdout()->Info("Cleaning up temporary files...");
// if ($isPdf && !$steps['explode-pdf']) {
//     array_map('unlink', glob("$pagesDir/*"));
// }
// foreach ([$visualsDir, $ocrDir, $markdownDir] as $dir) {
//     if (is_dir($dir)) {
//         $subdirs = glob("$dir/*", GLOB_ONLYDIR);
//         foreach ($subdirs as $subdir) {
//             array_map('unlink', glob("$subdir/*"));
//             rmdir($subdir);
//         }
//         array_map('unlink', glob("$dir/*"));
//         rmdir($dir);
//     }
// }
// rmdir($tempDir);

Logger::Stdout()->Info("*** COMPLETED SUCCESSFULLY ***");
if ($steps['compile-document']) {
    Logger::Stdout()->Info("Final document: {$outputDir}/full_book.md");
}
