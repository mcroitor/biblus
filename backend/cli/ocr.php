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
    'image' => [
        'short' => 'i',
        'long' => 'image',
        'description' => 'Path to the input image file (PNG)',
        'required' => true
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

if (Arguments::GetValue('help')) {
    Logger::Stdout()->Info("Usage: php ocr.php -i <image.png> [options]");
    Logger::Stdout()->Info(Arguments::Help());
    exit(0);
}

$imagePath = Arguments::GetValue('image');
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
