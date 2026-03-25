<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

/**
 * CLI script for batch OCR of a book with merging into a single file
 * Usage: php book_ocr.php <input_dir> [output_dir]
 */

define('OLLAMA_URL', getenv('OLLAMA_SERVER') ?: 'http://localhost:11434');
define('OLLAMA_API_URL', OLLAMA_URL . '/api/chat');
define('OLLAMA_MODEL', getenv('OLLAMA_MODEL') ?: 'qwen3-vl:235b-cloud');
define('TIMEOUT_SECONDS', 600);

function validateArguments(array $argv): array
{
    if (count($argv) < 2) {
        die("Usage: php book_ocr.php <input_dir> [output_dir]\n");
    }
    
    $inputDir = rtrim($argv[1], DIRECTORY_SEPARATOR);
    $baseOutputDir = rtrim($argv[2] ?? 'output_dir', DIRECTORY_SEPARATOR);

    if (!is_dir($inputDir)) {
        die("Error: Input directory not found.\n");
    }

    return ['inputDir' => $inputDir, 'baseOutputDir' => $baseOutputDir];
}

function ensureDirectoryExists(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

function findPngFiles(string $inputDir): array
{
    $files = glob($inputDir . DIRECTORY_SEPARATOR . "*.png");
    natsort($files);
    
    if (empty($files)) {
        die("No PNG files found.\n");
    }
    
    return $files;
}

function preloadModel(): void
{
    $data = [
        "model" => OLLAMA_MODEL,
        "keep_alive" => -1
    ];

    $ch = curl_init(OLLAMA_URL . '/api/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    echo "Preload Model to GPU...\n";
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'Error: ' . curl_error($ch) . "\n";
    } else {
        echo "Model successfully loaded into GPU memory.\n";
    }
    
    curl_close($ch);
}

function checkModelExists(): bool
{
    echo "Checking if model '" . OLLAMA_MODEL . "' is available...\n";

    $ch = curl_init(OLLAMA_URL . '/api/tags');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno) {
        die("Error: Could not connect to Ollama server at " . OLLAMA_URL . "\ncURL Error: $curlError\n");
    }

    $data = json_decode($response, true);
    if ($data === null) {
        die("Error: Invalid response from Ollama server.\n");
    }

    $models = array_column($data['models'] ?? [], 'name');

    if (!in_array(OLLAMA_MODEL, $models)) {
        die("Error: Model '" . OLLAMA_MODEL . "' is not installed. Run: ollama pull " . OLLAMA_MODEL . "\n");
    }

    echo "Model '" . OLLAMA_MODEL . "' is available.\n";
    return true;
}

function sendOllamaRequest(string $prompt, array $imgs, float $temp = 0.2): string
{
    $p = [
        "model" => OLLAMA_MODEL,
        "messages" => [
            [
                "role" => "user",
                "content" => $prompt,
                "images" => $imgs
            ]
        ],
        "stream" => false,
        "options" => [
            "temperature" => $temp,
            "num_predict" => 4096
        ]
    ];

    $ch = curl_init(OLLAMA_API_URL);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($p));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_SECONDS);

    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlErrno) {
        die("cURL Error: $curlError\n");
    }

    $data = json_decode($response, true);
    if ($data === null) {
        die("Error: Invalid JSON response from Ollama.\n");
    }

    return $data['message']['content'] ?? '';
}

function detectImagesAndCoordinates(string $imagePath): array
{
    $imageContent = file_get_contents($imagePath);
    if ($imageContent === false) {
        die("Error: Could not read image: $imagePath\n");
    }
    
    $imageData = base64_encode($imageContent);
    $prompt = "Analyze this page. Identify all images, diagrams, or charts. Return a JSON array of their normalized [ymin, xmin, ymax, xmax] coordinates (0-1000) and labels. Output ONLY JSON. Format: [{\"box_2d\": [0,0,0,0], \"label\": \"description\"}]";
    
    $response = sendOllamaRequest($prompt, [$imageData]);
    
    if (preg_match('/(\[\s*\[.*\]\s*\]|\[\s*\{.*\}\s*\])/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $item) {
                if (!isset($item['box_2d']) || !is_array($item['box_2d']) || count($item['box_2d']) !== 4) {
                    return [];
                }
            }
            return $decoded;
        }
    }
    
    return [];
}

function cropImagesAndGetPlaceholders(string $sourcePath, array $info, string $outDir, string $relDir): array
{
    $img = imagecreatefrompng($sourcePath);
    if (!$img) {
        die("Error: Could not read image: $sourcePath\n");
    }
    
    $imageSize = getimagesize($sourcePath);
    if ($imageSize === false) {
        imagedestroy($img);
        die("Error: Could not get image size: $sourcePath\n");
    }
    
    $w_orig = $imageSize[0];
    $h_orig = $imageSize[1];
    $placeholders = [];
    
    foreach ($info as $i => $item) {
        if (!isset($item['box_2d']) || !is_array($item['box_2d']) || count($item['box_2d']) !== 4) {
            continue;
        }
        
        $b = $item['box_2d'];
        $y1 = ($b[0] / 1000) * $h_orig;
        $x1 = ($b[1] / 1000) * $w_orig;
        $y2 = ($b[2] / 1000) * $h_orig;
        $x2 = ($b[3] / 1000) * $w_orig;
        $cw = max(1, $x2 - $x1);
        $ch = max(1, $y2 - $y1);
        $name = "img_" . ($i + 1) . ".png";
        
        $crop = imagecrop($img, ['x' => (int)$x1, 'y' => (int)$y1, 'width' => (int)$cw, 'height' => (int)$ch]);
        if ($crop) {
            imagepng($crop, $outDir . DIRECTORY_SEPARATOR . $name);
            $label = $item['label'] ?? "img";
            $placeholders[] = [
                'box' => $b,
                'markdown' => "![" . $label . "]($relDir/$name)",
                'desc' => $label
            ];
            imagedestroy($crop);
        }
    }
    
    imagedestroy($img);
    return $placeholders;
}

function performOcrWithImageReferences(string $path, array $placeholders): string
{
    $ctx = "Visuals identified:\n";
    foreach ($placeholders as $p) {
        $ctx .= "- Coords [" . implode(',', $p['box']) . "]: {$p['markdown']}\n";
    }
    
    $prompt = "Perform OCR on this image. $ctx\nInsert the Markdown tags at their correct positions. Output ONLY raw Markdown.";
    
    $imageContent = file_get_contents($path);
    if ($imageContent === false) {
        die("Error: Could not read image: $path\n");
    }
    
    $imageData = base64_encode($imageContent);
    
    return sendOllamaRequest($prompt, [$imageData], 0.1);
}

function processPage(string $imagePath, string $baseOutputDir, int $index, int $total): array
{
    $fileName = basename($imagePath, '.png');
    echo "\n>>> Page [" . ($index + 1) . "/" . $total . "]: $fileName\n";

    $pageOutputDir = $baseOutputDir . DIRECTORY_SEPARATOR . $fileName;
    ensureDirectoryExists($pageOutputDir);

    $imagesSubDir = $pageOutputDir . DIRECTORY_SEPARATOR . "images";
    ensureDirectoryExists($imagesSubDir);

    $outputMdFile = $pageOutputDir . DIRECTORY_SEPARATOR . "index.md";

    $groundingInfo = detectImagesAndCoordinates($imagePath);

    $placeholders = [];
    if (!empty($groundingInfo)) {
        $placeholders = cropImagesAndGetPlaceholders($imagePath, $groundingInfo, $imagesSubDir, "images");
    }

    $finalMarkdown = performOcrWithImageReferences($imagePath, $placeholders);
    
    if (file_put_contents($outputMdFile, $finalMarkdown) === false) {
        die("Error: Could not write to file: $outputMdFile\n");
    }

    echo " - Done.\n";
    
    return ['dir' => $fileName, 'file' => $outputMdFile];
}

function assembleFullBook(array $processedPages, string $baseOutputDir): void
{
    echo "\n--- Assembling full_book.md ---\n";

    $fullBookContent = "# Combined OCR Result\n\n";
    $fullBookContent .= "> Generated on: " . date('Y-m-d H:i:s') . "\n\n---\n\n";

    foreach ($processedPages as $page) {
        $content = file_get_contents($page['file']);
        if ($content === false) {
            echo "Warning: Could not read file: {$page['file']}\n";
            continue;
        }
        
        $correctedContent = preg_replace(
            '/\((images\/.*?)\)/',
            "(" . $page['dir'] . "/$1)",
            $content
        );

        $fullBookContent .= "## Page: {$page['dir']}\n\n";
        $fullBookContent .= $correctedContent . "\n\n---\n\n";
    }

    if (file_put_contents($baseOutputDir . DIRECTORY_SEPARATOR . "full_book.md", $fullBookContent) === false) {
        die("Error: Could not write full_book.md\n");
    }
}

function runBatchOcr(string $inputDir, string $baseOutputDir): void
{
    $files = findPngFiles($inputDir);
    echo "Found " . count($files) . " pages. Starting processing...\n";

    checkModelExists();
    preloadModel();

    $processedPages = [];

    foreach ($files as $index => $imagePath) {
        try {
            $result = processPage($imagePath, $baseOutputDir, $index, count($files));
            $processedPages[] = $result;
        } catch (Exception $e) {
            echo " [!] Error: " . $e->getMessage() . "\n";
        }
    }

    if (!empty($processedPages)) {
        assembleFullBook($processedPages, $baseOutputDir);
    }

    echo "\n*** SUCCESS! ***\n";
    echo "Individual pages: $baseOutputDir/[page_name]/\n";
    echo "Full book: $baseOutputDir/full_book.md\n";
}

$args = validateArguments($argv);
ensureDirectoryExists($args['baseOutputDir']);
runBatchOcr($args['inputDir'], $args['baseOutputDir']);
