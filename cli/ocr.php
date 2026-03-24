<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

$ollama_server = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
$model = getenv('OLLAMA_MODEL') ?: 'qwen3-vl:235b-cloud' ;// 'deepseek-ocr:3b';

/**
 * CLI скрипт для OCR через Ollama (DeepSeek-VL)
 * Использование: php ocr.php image.png [output_file.md]
 */

// 1. Проверка аргументов
if ($argc < 2) {
    die("Usage: php ocr.php <path_to_image> [output_file.md]\n");
}

$imagePath = $argv[1];
$outputPath = $argv[2] ?? $argv[1] . '.result.md';

if (!file_exists($imagePath)) {
    die("Error: Image file not found: $imagePath\n");
}

echo "Reading image and converting to Base64...\n";
$imageData = base64_encode(file_get_contents($imagePath));

// 2. Формирование Payload
$payload = [
    "model" => $model, // Убедитесь, что модель скачана (ollama pull deepseek-v2)
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

// 3. Отправка запроса через cURL
echo "Sending request to Ollama (this may take a moment)...\n";

$ch = curl_init($ollama_server . '/api/chat');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 минут таймаут для тяжелых моделей

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    die("cURL Error: " . curl_error($ch) . "\n");
}

// 4. Обработка ответа
if ($httpCode !== 200) {
    die("Error: Ollama returned HTTP $httpCode. Response: $response\n");
}

$data = json_decode($response, true);
$markdownResult = $data['message']['content'] ?? '';

if (empty($markdownResult)) {
    die("Error: Received empty response from model.\n");
}

// 5. Сохранение результата
if (file_put_contents($outputPath, $markdownResult)) {
    echo "Successfully saved OCR result to: $outputPath\n";
} else {
    die("Error: Could not write to file $outputPath\n");
}