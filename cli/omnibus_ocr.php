<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

/**
 * OMNIBUS OCR: PDF/PNG -> Markdown with image preservation
 * Usage: php omnibus_ocr.php <input_path> [output_dir]
 */

define('OLLAMA_URL', getenv('OLLAMA_SERVER') ?: 'http://localhost:11434');
define('OLLAMA_API_URL', OLLAMA_URL . '/api/chat');
define('OLLAMA_MODEL', getenv('OLLAMA_MODEL') ?: 'qwen3.5:32b');
define('PDF_DPI', 300);
define('TIMEOUT_SECONDS', 600);

function validateArguments(array $argv): array
{
    if (count($argv) < 2) {
        die("Usage: php omnibus_ocr.php <input_pdf_or_dir> [output_dir]\n");
    }

    $inputPath = rtrim($argv[1], DIRECTORY_SEPARATOR);
    $baseOutputDir = rtrim($argv[2] ?? 'book_export', DIRECTORY_SEPARATOR);

    return ['inputPath' => $inputPath, 'baseOutputDir' => $baseOutputDir];
}

function ensureDirectoryExists(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
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

    echo "Preloading model to GPU...\n";
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'Error: ' . curl_error($ch) . "\n";
    } else {
        echo "Model successfully loaded into GPU memory.\n";
    }

    curl_close($ch);
}

function sendRequest(string $prompt, array $imgs, float $temp = 0.2): string
{
    $payload = [
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
            "num_predict" => 4096,
            "num_ctx" => 32768,
        ]
    ];

    $ch = curl_init(OLLAMA_API_URL);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
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

function convertPdfToPng(string $pdfPath, string $outDir): array
{
    if (!extension_loaded('imagick')) {
        die("Error: Imagick extension is not installed. Run: apt install php-imagick\n");
    }

    if (!file_exists($pdfPath)) {
        die("Error: PDF file not found: $pdfPath\n");
    }

    ensureDirectoryExists($outDir);
    echo "Rendering PDF to high-res PNGs (this may take time)...\n";

    $im = new Imagick();
    $im->setResolution(PDF_DPI, PDF_DPI);
    $im->readImage($pdfPath);

    $paths = [];
    foreach ($im as $i => $page) {
        $page->setImageFormat('png');
        $target = $outDir . DIRECTORY_SEPARATOR . sprintf("page_%03d.png", $i + 1);

        if (!$page->writeImage($target)) {
            echo "\nWarning: Could not write $target\n";
            continue;
        }

        $paths[] = $target;
        echo ".";
    }

    $im->clear();
    $im->destroy();
    echo " Done.\n";

    if (empty($paths)) {
        die("Error: No pages were rendered from PDF.\n");
    }

    return $paths;
}

function findInputFiles(string $inputPath, string $tempDir): array
{
    $pagesToProcess = [];
    $isTempDir = false;

    if (is_file($inputPath) && strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'pdf') {
        $pagesToProcess = convertPdfToPng($inputPath, $tempDir);
        $isTempDir = true;
    } elseif (is_dir($inputPath)) {
        $pagesToProcess = glob($inputPath . DIRECTORY_SEPARATOR . "*.png");
        natsort($pagesToProcess);
        $pagesToProcess = array_values($pagesToProcess);
    } else {
        die("Error: Input must be a PDF file or a directory containing PNG images.\n");
    }

    if (empty($pagesToProcess)) {
        die("Error: No pages found to process.\n");
    }

    return ['pages' => $pagesToProcess, 'isTempDir' => $isTempDir];
}

function detectVisuals(string $imagePath): array
{
    $imageContent = file_get_contents($imagePath);
    if ($imageContent === false) {
        die("Error: Could not read image: $imagePath\n");
    }

    $imageData = base64_encode($imageContent);
    $prompt = "Identify all visual elements (diagrams, photos, charts, logos) in this image. 
For each identified element, describe what it is and provide its bounding box coordinates.
Use normalized coordinates from 0 to 1000.

Return the result STRICTLY as a JSON array of objects:
[
  {\"box\": {\"ymin\": integer, \"xmin\": integer, \"ymax\": integer, \"xmax\": integer}, \"label\": \"string\"}
]

Do not include any other text or explanations.";

    $raw = sendRequest($prompt, [$imageData]);

    if (preg_match('/(\[\s*\[.*\]\s*\]|\[\s*\{.*\}\s*\])/s', $raw, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $item) {
                if (!isset($item['box']) || !is_array($item['box']) || !isset($item['box']['ymin'], $item['box']['xmin'], $item['box']['ymax'], $item['box']['xmax'])) {
                    return [];
                }
            }
            return $decoded;
        }
    }

    return [];
}

function cropVisuals(string $sourcePath, array $info, string $outDir, string $relDir): array
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
        if (!isset($item['box']) || !is_array($item['box']) || !isset($item['box']['ymin'], $item['box']['xmin'], $item['box']['ymax'], $item['box']['xmax'])) {
            continue;
        }

        $b = $item['box'];
        $y1 = ($b['ymin'] / 1000) * $h_orig;
        $x1 = ($b['xmin'] / 1000) * $w_orig;
        $y2 = ($b['ymax'] / 1000) * $h_orig;
        $x2 = ($b['xmax'] / 1000) * $w_orig;

        $cw = max(2, $x2 - $x1);
        $ch = max(2, $y2 - $y1);

        $crop = imagecrop($img, ['x' => (int)$x1, 'y' => (int)$y1, 'width' => (int)$cw, 'height' => (int)$ch]);
        if ($crop) {
            $fname = "img_" . ($i + 1) . ".png";
            imagepng($crop, $outDir . DIRECTORY_SEPARATOR . $fname);
            $label = $item['label'] ?? "visual";
            $placeholders[] = [
                'box' => $b,
                'md' => "![" . $label . "]($relDir/$fname)",
                'desc' => $label
            ];
            imagedestroy($crop);
        }
    }

    imagedestroy($img);
    return $placeholders;
}

function performOcr(string $imagePath, array $placeholders): string
{
    $ctx = "I have extracted visual elements. Use these tags at their locations:\n";
    foreach ($placeholders as $p) {
        $box = $p['box'];
        $ctx .= "- Around [ymin: {$box['ymin']}, xmin: {$box['xmin']}, ymax: {$box['ymax']}, xmax: {$box['xmax']}]: {$p['md']}\n";
    }

    $prompt = "Perform high-fidelity OCR to Markdown. $ctx\nMaintain structure (headers, tables). Insert visual tags logically. Use LaTeX for math. NO filler, ONLY Markdown.";

    $imageContent = file_get_contents($imagePath);
    if ($imageContent === false) {
        die("Error: Could not read image: $imagePath\n");
    }

    return sendRequest($prompt, [base64_encode($imageContent)], 0.1);
}

function processPage(string $imagePath, string $baseOutputDir, int $index): array
{
    $pageName = sprintf("page_%03d", $index + 1);
    echo "Processing [$pageName]...";

    $pageDir = $baseOutputDir . DIRECTORY_SEPARATOR . $pageName;
    ensureDirectoryExists($pageDir);

    $imgDir = $pageDir . DIRECTORY_SEPARATOR . "images";
    ensureDirectoryExists($imgDir);

    $mdFile = $pageDir . DIRECTORY_SEPARATOR . "index.md";

    $grounding = detectVisuals($imagePath);
    $placeholders = [];

    ensureDirectoryExists("logs");
    file_put_contents("logs/grounding_$pageName.json", json_encode($grounding, JSON_PRETTY_PRINT));

    if (!empty($grounding)) {
        $placeholders = cropVisuals($imagePath, $grounding, $imgDir, "images");
    }

    $markdown = performOcr($imagePath, $placeholders);

    if (file_put_contents($mdFile, $markdown) === false) {
        die("Error: Could not write to file: $mdFile\n");
    }

    echo " OK\n";

    return ['name' => $pageName, 'file' => $mdFile];
}

function assembleFullBook(string $baseOutputDir, array $manifest): void
{
    echo "Assembling full_book.md...\n";

    $full = "# Full Book OCR\n\n";
    $full .= "> Generated on: " . date('Y-m-d H:i:s') . "\n\n---\n\n";

    foreach ($manifest as $page) {
        $content = file_get_contents($page['file']);
        if ($content === false) {
            echo "Warning: Could not read file: {$page['file']}\n";
            continue;
        }

        $content = preg_replace('/\((images\/.*?)\)/', "(" . $page['name'] . "/$1)", $content);
        $full .= "## " . strtoupper($page['name']) . "\n\n" . $content . "\n\n---\n\n";
    }

    if (file_put_contents($baseOutputDir . DIRECTORY_SEPARATOR . "full_book.md", $full) === false) {
        die("Error: Could not write full_book.md\n");
    }
}

function cleanupTempFiles(string $tempDir): void
{
    echo "Cleaning up temporary PNG files...\n";

    $files = glob($tempDir . "/*.png");
    if ($files) {
        foreach ($files as $file) {
            unlink($file);
        }
    }

    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }
}

function runOcr(string $inputPath, string $baseOutputDir): void
{
    $tempDir = $baseOutputDir . DIRECTORY_SEPARATOR . "_internal_temp";
    $input = findInputFiles($inputPath, $tempDir);
    $pagesToProcess = $input['pages'];
    $isTempDir = $input['isTempDir'];

    $manifest = [];
    echo "\nStarting OCR for " . count($pagesToProcess) . " pages...\n";

    checkModelExists();
    preloadModel();

    foreach ($pagesToProcess as $index => $imagePath) {
        try {
            $result = processPage($imagePath, $baseOutputDir, $index);
            $manifest[] = $result;
        } catch (Exception $e) {
            echo " FAILED: " . $e->getMessage() . "\n";
        }
    }

    if (!empty($manifest)) {
        assembleFullBook($baseOutputDir, $manifest);
    }

    if ($isTempDir) {
        cleanupTempFiles($tempDir);
    }

    echo "\n*** COMPLETED SUCCESSFULLY ***\n";
    echo "Final document: $baseOutputDir/full_book.md\n";
}

$args = validateArguments($argv);
ensureDirectoryExists($args['baseOutputDir']);
runOcr($args['inputPath'], $args['baseOutputDir']);
