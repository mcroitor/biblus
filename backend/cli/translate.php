<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

include_once __DIR__ . '/../config.php';

use \Core\Arguments;
use \Core\Mc\Alpaca\OllamaClient;
use \Core\Mc\Logger;
use \Worker\TranslateWorker;

const BATCH_SIZE = 4 * 1024; // 4 KB

Arguments::Set([
    'help' => [
        'short' => 'h',
        'long' => 'help',
        'description' => 'Show this help message and exit',
        'required' => false
    ],
    "input" => [
        "short" => "i",
        "long" => "input",
        "description" => "Path to the input directory containing Markdown text files or Markdown file.",
        "required" => true
    ],
    "output" => [
        "short" => "o",
        "long" => "output",
        "description" => "Path to the output directory where translated files will be saved.",
        "required" => true
    ],
    "target-language" => [
        "short" => "t",
        "long" => "target-language",
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
    "ollama-server" => [
        "short" => null,
        "long" => "ollama-server",
        "description" => "Ollama server URL (default: 'http://localhost:11434').",
        "required" => false,
        "default" => \Config::$ollamaServer
    ],
    "first-page" => [
        "short" => "f",
        "long" => "first-page",
        "description" => "First page to start translation from (default: 1).",
        "required" => false,
        "default" => 1
    ],
    "last-page" => [
        "short" => "l",
        "long" => "last-page",
        "description" => "Last page to translate (default: all pages).",
        "required" => false,
        "default" => 0
    ],
    'resume' => [
        'short' => 'r',
        'long' => 'resume',
        'description' => 'Resume translation and skip already translated files',
        'required' => false
    ]
]);

Arguments::Parse();

$usage = 'Usage: php ' . basename(__FILE__) . ' --input <input_path> --output <output_dir> --target-language <lang> [options]';

if (Arguments::GetValue('help')) {
    echo $usage . "\n";
    echo Arguments::Help();
    exit(0);
}

$input = Arguments::GetValue('input');
$output = Arguments::GetValue('output');
$targetLanguage = trim((string) Arguments::GetValue('target-language'));
$model = Arguments::GetValue('model');
$server = Arguments::GetValue('ollama-server');
$firstPage = (int) Arguments::GetValue('first-page');
$lastPage = (int) Arguments::GetValue('last-page');
$resume = Arguments::GetValue('resume') === true;

if (empty($input) || empty($output) || $targetLanguage === '') {
    Logger::Stdout()->Error('Required arguments: --input, --output, --target-language.');
    echo $usage . PHP_EOL;
    echo Arguments::Help();
    exit(1);
}

// Validate pagination parameters
if ($firstPage < 1) {
    Logger::Stdout()->Error('first-page must be >= 1.');
    exit(1);
}
if ($lastPage < 0) {
    Logger::Stdout()->Error('last-page must be >= 0 (0 means all pages).');
    exit(1);
}


if(!file_exists($input)) {
    Logger::Stdout()->Error("Input path does not exist: {$input}");
    exit(1);
}

if(!is_dir($output)) {
    if (!mkdir($output, 0755, true)) {
        Logger::Stdout()->Error("Cannot create output directory: {$output}");
        exit(1);
    }
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
        900
    );

    if(is_file($input)) {
        Logger::Stdout()->Info("Translating single Markdown file '{$input}' to '{$targetLanguage}'...");
        $fileName = pathinfo($input, PATHINFO_FILENAME);
        $translatedPath = $output . DIRECTORY_SEPARATOR . $fileName . ".md";

        if ($resume && file_exists($translatedPath)) {
            Logger::Stdout()->Info("Resume: translated file already exists, skipping: {$translatedPath}");
            Logger::Stdout()->Info('*** COMPLETED SUCCESSFULLY ***');
            exit(0);
        }

        $text = file($input);
        if ($text === false) {
            Logger::Stdout()->Error("Cannot read input file: {$input}");
            exit(1);
        }
        
        // Remove output file if it exists to avoid duplication on re-runs
        if (!$resume && file_exists($translatedPath)) {
            unlink($translatedPath);
        }

        $batch_idx = 0;

        for($idx = 0; $idx < count($text); $batch_idx++) {
            Logger::Stdout()->Info("Translating batch " . ($batch_idx + 1));
            // prepare batch
            $batch = $text[$idx];
            $idx++;

            while($idx < count($text) && strlen($batch) + strlen($text[$idx]) <= BATCH_SIZE) {
                $batch .= $text[$idx];
                $idx++;
            }
            $maxRetries = 3;
            $attempt = 0;
            while ($attempt < $maxRetries) {
                try {
                    $translated = $worker->TranslateText($batch, $targetLanguage);
                    file_put_contents($translatedPath, $translated . PHP_EOL, FILE_APPEND);
                    break; // Break out of retry loop on success
                } catch (\Throwable $e) {
                    $attempt++;
                    Logger::Stdout()->Error("Error translating batch " . ($batch_idx + 1) . ": " . $e->getMessage());
                    if ($attempt < $maxRetries) {
                        Logger::Stdout()->Info("Retrying batch " . ($batch_idx + 1) . " (Attempt {$attempt}/{$maxRetries})...");
                        sleep(2); // Wait before retrying
                    } else {
                        Logger::Stdout()->Error("Failed to translate batch " . ($batch_idx + 1) . " after {$maxRetries} attempts. Skipping.");
                    }
                }
            }            
        }
        Logger::Stdout()->Info("Translated file: {$translatedPath}");
        Logger::Stdout()->Info('*** COMPLETED SUCCESSFULLY ***');
        exit(0);
    }

    Logger::Stdout()->Info("Translating Markdown files from '{$input}' to '{$targetLanguage}'...");

    $executeInput = $input;
    $tempResumeInputDir = null;
    if ($resume) {
        $textFiles = glob($input . DIRECTORY_SEPARATOR . '*.md');
        natsort($textFiles);
        $textFiles = array_values($textFiles);

        if ($lastPage === 0 || $lastPage > count($textFiles)) {
            $lastPage = count($textFiles);
        }

        $sliceLength = max(0, $lastPage - $firstPage + 1);
        $selectedFiles = array_slice($textFiles, $firstPage - 1, $sliceLength);
        $pendingFiles = [];
        foreach ($selectedFiles as $textFile) {
            $fileName = pathinfo($textFile, PATHINFO_FILENAME);
            $translatedPath = $output . DIRECTORY_SEPARATOR . $fileName . '.md';
            if (!file_exists($translatedPath)) {
                $pendingFiles[] = $textFile;
            }
        }

        Logger::Stdout()->Info('Resume mode: pending files ' . count($pendingFiles) . '/' . count($selectedFiles));
        if (empty($pendingFiles)) {
            Logger::Stdout()->Info('No pending Markdown files were found for translation.');
            exit(0);
        }

        $tempResumeInputDir = $output . DIRECTORY_SEPARATOR . '_resume_input_' . uniqid();
        mkdir($tempResumeInputDir, 0755, true);
        foreach ($pendingFiles as $pendingFile) {
            copy($pendingFile, $tempResumeInputDir . DIRECTORY_SEPARATOR . basename($pendingFile));
        }
        $executeInput = $tempResumeInputDir;
        $firstPage = 1;
        $lastPage = 0;
    }

    $results = $worker->Execute(
        $executeInput,
        $output,
        $targetLanguage,
        $firstPage,
        $lastPage
    );

    if ($tempResumeInputDir !== null && is_dir($tempResumeInputDir)) {
        array_map('unlink', glob($tempResumeInputDir . DIRECTORY_SEPARATOR . '*.md'));
        rmdir($tempResumeInputDir);
    }

    if (empty($results)) {
        Logger::Stdout()->Info('No Markdown files were translated. Ensure input directory contains .md files.');
        exit(0);
    }

    Logger::Stdout()->Info('Translated files: ' . count($results));
    Logger::Stdout()->Info("Output directory: {$output}");
    Logger::Stdout()->Info('*** COMPLETED SUCCESSFULLY ***');
} catch (\Throwable $e) {
    Logger::Stdout()->Error('Translation failed: ' . $e->getMessage());
    exit(1);
}

