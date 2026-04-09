<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

include_once __DIR__ . '/../config.php';

use \Core\Arguments;
use \Core\Mc\Alpaca\OllamaClient;
use \Core\Mc\Logger;
use \Worker\TranslateWorker;


Arguments::Set([
    'help' => [
        'short' => 'h',
        'long' => 'help',
        'description' => 'Show this help message and exit',
        'required' => false
    ],
    "inputDir" => [
        "short" => "i",
        "long" => "inputDir",
        "description" => "Path to the input directory containing Markdown text files.",
        "required" => true
    ],
    "outputDir" => [
        "short" => "o",
        "long" => "outputDir",
        "description" => "Path to the output directory where translated files will be saved.",
        "required" => true
    ],
    "targetLanguage" => [
        "short" => "t",
        "long" => "targetLanguage",
        "description" => "Target language for translation (e.g., 'en' for English, 'ru' for Russian).",
        "required" => true
    ],
    "model" => [
        "short" => "m",
        "long" => "model",
        "description" => "Language model to use for translation (e.g., '" . \Config::$ollamaCheckerModel . "').",
        "required" => false,
        "default" => \Config::$ollamaCheckerModel
    ],
    "ollamaServer" => [
        "short" => null,
        "long" => "ollamaServer",
        "description" => "Ollama server URL (default: 'http://localhost:11434').",
        "required" => false,
        "default" => \Config::$ollamaServer
    ],
    "firstPage" => [
        "short" => "f",
        "long" => "firstPage",
        "description" => "First page to start translation from (default: 1).",
        "required" => false,
        "default" => 1
    ],
    "lastPage" => [
        "short" => "l",
        "long" => "lastPage",
        "description" => "Last page to translate (default: all pages).",
        "required" => false,
        "default" => null
    ]
]);

Arguments::Parse();

if (Arguments::GetValue('help')) {
    Logger::Stdout()->Info('Usage: php translate.php -i <input_dir> -o <output_dir> -t <target_language> [options]');
    Logger::Stdout()->Info(Arguments::Help());
    exit(0);
}

$inputDir = Arguments::GetValue('inputDir');
$outputDir = Arguments::GetValue('outputDir');
$targetLanguage = trim((string) Arguments::GetValue('targetLanguage'));
$model = Arguments::GetValue('model');
$server = Arguments::GetValue('ollamaServer');
$firstPage = (int) Arguments::GetValue('firstPage');
$lastPage = (int) Arguments::GetValue('lastPage');


if (!is_dir($inputDir)) {
    Logger::Stdout()->Error("Input directory does not exist: {$inputDir}");
    exit(1);
}

if ($targetLanguage === '') {
    Logger::Stdout()->Error('Target language must not be empty.');
    exit(1);
}

try {
    $client = new OllamaClient($server, $model);
    $installedModels = $client->GetModelsList();
    if (!in_array($model, $installedModels, true)) {
        Logger::Stdout()->Error("Model '{$model}' is not installed. Run: ollama pull {$model}");
        exit(1);
    }

    $worker = new TranslateWorker(
        $server,
        $model,
        Config::$ollamaModelOptions,
        (int) Config::$timeout
    );

    Logger::Stdout()->Info("Translating Markdown files from '{$inputDir}' to '{$targetLanguage}'...");
    $results = $worker->Execute(
        $inputDir,
        $outputDir,
        $targetLanguage,
        $firstPage,
        $lastPage
    );

    if (empty($results)) {
        Logger::Stdout()->Info('No Markdown files were translated. Ensure input directory contains .md files.');
        exit(0);
    }

    Logger::Stdout()->Info('Translated files: ' . count($results));
    Logger::Stdout()->Info("Output directory: {$outputDir}");
    Logger::Stdout()->Info('*** COMPLETED SUCCESSFULLY ***');
} catch (\Throwable $e) {
    Logger::Stdout()->Error('Translation failed: ' . $e->getMessage());
    exit(1);
}

