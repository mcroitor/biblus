<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

$ollama_server = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
$model = getenv('OLLAMA_MODEL') ?: 'qwen3-vl:235b-cloud'; // 'qwen3-vl:8b';

/**
 * CLI script for OCR using Ollama (Qwen3-VL)
 * Usage: php ocr.php image.png [output_file.md]
 */

function validateArguments(int $argc, array $argv): array
{
    if ($argc < 2) {
        die("Usage: php ocr.php <path_to_image> [output_file.md]\n");
    }

    $imagePath = $argv[1];
    $outputPath = $argv[2] ?? $argv[1] . '.result.md';

    if (!file_exists($imagePath)) {
        die("Error: Image file not found: $imagePath\n");
    }

    return ['imagePath' => $imagePath, 'outputPath' => $outputPath];
}

function readImageAsBase64(string $imagePath): string
{
    echo "Reading image and converting to Base64...\n";
    $content = file_get_contents($imagePath);
    if ($content === false) {
        die("Error: Could not read image file: $imagePath\n");
    }
    return base64_encode($content);
}

function buildPayload(string $model, string $imageData): array
{
    return [
        "model" => $model,
        "messages" => [
            [
                "role" => "user",
                "content" => "Act as a professional OCR engine. Extract all text from the attached image and format it into a clean, structured Markdown document. Use headers, lists, and tables where applicable. Use LaTeX for math. Return ONLY the Markdown content without any conversational filler.",
                "images" => [$imageData]
            ]
        ],
        "stream" => false,
        "options" => [
            "temperature" => 0.1,
            "num_predict" => 4096
        ]
    ];
}

function sendRequest(string $server, array $payload): array
{
    echo "Sending request to Ollama (this may take a moment)...\n";

    $ch = curl_init($server . '/api/chat');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        die("cURL Error: " . curl_error($ch) . "\n");
    }

    curl_close($ch);

    return ['httpCode' => $httpCode, 'response' => $response];
}

function parseResponse(array $responseData): string
{
    if ($responseData['httpCode'] !== 200) {
        die("Error: Ollama returned HTTP {$responseData['httpCode']}. Response: {$responseData['response']}\n");
    }

    $data = json_decode($responseData['response'], true);
    if ($data === null) {
        die("Error: Invalid JSON response from Ollama.\n");
    }

    $markdownResult = $data['message']['content'] ?? '';

    if (empty($markdownResult)) {
        die("Error: Received empty response from model.\n");
    }

    return $markdownResult;
}

function saveResult(string $outputPath, string $content): void
{
    if (file_put_contents($outputPath, $content)) {
        echo "Successfully saved OCR result to: $outputPath\n";
    } else {
        die("Error: Could not write to file $outputPath\n");
    }
}

function checkModelExists(string $server, string $model): bool
{
    echo "Checking if model '$model' is available...\n";

    $ch = curl_init($server . '/api/tags');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno) {
        die("Error: Could not connect to Ollama server at $server\ncURL Error: $curlError\n");
    }

    $data = json_decode($response, true);
    if ($data === null) {
        die("Error: Invalid response from Ollama server.\n");
    }

    $models = array_column($data['models'] ?? [], 'name');

    if (!in_array($model, $models)) {
        die("Error: Model '$model' is not installed. Run: ollama pull $model\n");
    }

    echo "Model '$model' is available.\n";
    return true;
}

function runOcr(string $imagePath, string $outputPath, string $model, string $server): void
{
    checkModelExists($server, $model);
    $imageData = readImageAsBase64($imagePath);
    $payload = buildPayload($model, $imageData);
    $response = sendRequest($server, $payload);
    $markdownResult = parseResponse($response);
    saveResult($outputPath, $markdownResult);
}

$args = validateArguments($argc, $argv);
runOcr($args['imagePath'], $args['outputPath'], $model, $ollama_server);
