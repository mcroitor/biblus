<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

include_once __DIR__ . "/../config.php";

use \Core\Arguments;
use \Core\Mc\Logger;
use \Worker\TextDetectWorker;

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
        'description' => 'Path to the input image file (PNG)',
        'required' => true
    ],
    'image' => [
        'short' => null,
        'long' => 'image',
        'description' => '[Deprecated] Alias for --input',
        'required' => false
    ],
    'output' => [
        'short' => 'o',
        'long' => 'output',
        'description' => 'Path to save the output Markdown file',
        'required' => false
    ],
    'ollama-server' => [
        'short' => null,
        'long' => 'ollama-server',
        'description' => 'URL of the Ollama server',
        'required' => false,
        'default' => Config::$ollamaServer
    ],
    'model' => [
        'short' => null,
        'long' => 'model',
        'description' => 'Ollama model to use for OCR',
        'required' => false,
        'default' => Config::$ollamaOcrModel
    ]
]);

Arguments::Parse();

$usage = 'Usage: php ' . basename(__FILE__) . ' --input <image.png> [options]';

if (Arguments::GetValue('help')) {
    echo $usage . "\n";
    echo Arguments::Help();
    exit(0);
}

$inputPath = Arguments::GetValue('input') ?? Arguments::GetValue('image');
$imagePath = $inputPath;

if (empty($imagePath)) {
    Logger::Stdout()->Error('Error: Input image path is required.');
    echo $usage . "\n";
    echo Arguments::Help();
    exit(1);
}

if (!is_file($imagePath)) {
    Logger::Stdout()->Error("Error: Input image not found: {$imagePath}");
    exit(1);
}

$outputPath = Arguments::GetValue('output') ?? (pathinfo($imagePath, PATHINFO_FILENAME) . '.md');
$server = Arguments::GetValue('ollama-server');
$model = Arguments::GetValue('model');

$worker = new TextDetectWorker($server, $model);
if (!$worker->checkModelExists()) {
    Logger::Stdout()->Error("Model '{$model}' is not installed. Run: ollama pull {$model}");
    exit(1);
}

Logger::Stdout()->Info("Performing OCR on: {$imagePath}");

$tempDir = sys_get_temp_dir() . '/ocr_' . uniqid();
mkdir($tempDir);

try {
    $worker->Execute(dirname($imagePath), $tempDir);
    $resultFile = $tempDir . DIRECTORY_SEPARATOR . pathinfo($imagePath, PATHINFO_FILENAME) . '.txt';
    
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        file_put_contents($outputPath, $content);
        Logger::Stdout()->Info("Successfully saved OCR result to: {$outputPath}");
    }
} catch (\Throwable $e) {
    Logger::Stdout()->Error("Error: " . $e->getMessage());
    exit(1);
} finally {
    array_map('unlink', glob("$tempDir/*"));
    rmdir($tempDir);
}
