<?php

/**
 * OMNIBUS GEMINI OCR (Batch Edition)
 * Processing multi-page PDFs through Gemini API
 */

// --- SETTINGS ---
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'YOUR_KEY_HERE');
define('MODEL_NAME', 'gemini-2.5-flash');
define('IMG_PATTERN', '/<image>\[(\d+),\s*(\d+),\s*(\d+),\s*(\d+)\]\((.*?)\)<\/image>/i');

// --- PROJECT MANAGEMENT FUNCTIONS ---

function ensureDirExists(string $path): void
{
    if (!is_dir($path)) mkdir($path, 0755, true);
}

function createProjectStructure(string $baseDir, string $projectName, bool $resume = false): array
{
    $folderName = $resume ? $projectName : $projectName . '_' . uniqid();
    $projectDir = $baseDir . DIRECTORY_SEPARATOR . $folderName;
    $dirs = [
        'base'    => $projectDir,
        'pages'   => $projectDir . DIRECTORY_SEPARATOR . 'pages',
        'visuals' => $projectDir . DIRECTORY_SEPARATOR . 'visuals',
        'ocr'     => $projectDir . DIRECTORY_SEPARATOR . 'ocr',
    ];
    foreach ($dirs as $dir) ensureDirExists($dir);
    return $dirs;
}

// --- IMAGE PROCESSING FUNCTIONS ---

/**
 * Optimized page extraction (one by one)
 */
function explodePages(string $pdfPath, string $outputDir): array
{
    if (!extension_loaded('imagick')) die("Error: Imagick required.\n");

    $pagePaths = [];
    $pdf = new Imagick();
    // Get the number of pages without loading the entire file
    $pdf->pingImage($pdfPath);
    $count = $pdf->getNumberImages();

    echo "Exploding $count pages...\n";

    for ($i = 0; $i < $count; $i++) {
        $im = new Imagick();
        $im->setResolution(300, 300);
        // Read strictly ONE page [index]
        $im->readImage($pdfPath . "[" . $i . "]");
        $im->setImageFormat('png');
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE); // Remove transparency

        $path = $outputDir . DIRECTORY_SEPARATOR . sprintf("page_%03d.png", $i + 1);
        if (file_exists($path)) {
            $pagePaths[] = $path;
            continue;
        }
        $im->writeImage($path);
        $pagePaths[] = $path;

        $im->clear();
        $im->destroy();
        echo ".";
    }
    echo " Done.\n";
    return $pagePaths;
}

/**
 * Fixed image extraction function
 */
function extractPictures(string $text, string $pageImagePath, string $outputDir, int $pageIdx): string
{
    $im = new Imagick($pageImagePath);
    $w = $im->getImageWidth();
    $h = $im->getImageHeight();

    // Use callback to replace each tag individually
    $processedText = preg_replace_callback(IMG_PATTERN, function ($m) use ($im, $w, $h, $outputDir, $pageIdx) {
        static $imgCount = 0;
        $imgCount++;

        $ymin = $m[1];
        $xmin = $m[2];
        $ymax = $m[3];
        $xmax = $m[4];
        $label = $m[5] ?: "visual";

        $rx = ($xmin / 1000) * $w;
        $ry = ($ymin / 1000) * $h;
        $rw = (($xmax - $xmin) / 1000) * $w;
        $rh = (($ymax - $ymin) / 1000) * $h;

        if ($rw < 10 || $rh < 10) return ""; // Skip garbage

        $fName = sprintf("p%03d_img%02d.png", $pageIdx, $imgCount);
        $crop = clone $im;
        $crop->cropImage((int)$rw, (int)$rh, (int)$rx, (int)$ry);
        $crop->writeImage($outputDir . DIRECTORY_SEPARATOR . $fName);
        $crop->destroy();

        // Return standard Markdown markup
        return "![{$label}](./visuals/{$fName})";
    }, $text);

    $im->destroy();
    return $processedText;
}

// --- NETWORKING FUNCTIONS ---

function askGemini(string $imagePath): string
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . MODEL_NAME . ":generateContent?key=" . GEMINI_API_KEY;

    $prompt = "Act as a professional OCR. Extract text to Markdown. Use LaTeX for math. Use tags for images: <image>[ymin, xmin, ymax, xmax](label)</image> (0-1000). Return ONLY Markdown.";

    $payload = [
        "contents" => [["parts" => [
            ["text" => $prompt],
            ["inline_data" => ["mime_type" => "image/png", "data" => base64_encode(file_get_contents($imagePath))]]
        ]]],
        "generationConfig" => ["temperature" => 0.1]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 429) {
        echo " (Rate limit! Waiting 30s...) ";
        sleep(30);
        return askGemini($imagePath);
    }

    //echo "\n[DEBUG] API Response: " . substr($res, 0, 200) . "...\n";
    $json = json_decode($res, true);
    return $json['candidates'][0]['content']['parts'][0]['text'] ?? "Error on page";
}

// --- MERGING FUNCTIONS ---

function mergeMarkdown(string $ocrDir, string $outputPath): void
{
    $files = glob($ocrDir . DIRECTORY_SEPARATOR . '*.md');
    natsort($files); // Correct sorting: 1, 2, 10

    $fullMd = "# Book OCR Export\n\n";
    foreach ($files as $file) {
        $fullMd .= "\n" . file_get_contents($file) . "\n\n---\n\n";
    }
    file_put_contents($outputPath, $fullMd);
}

// --- MAIN RUNNER ---

$input = $argv[1] ?? die("Usage: php {$argv[0]} <file.pdf> | <scans_dir> [--resume]\n");
$resume = in_array('--resume', $argv);
$projectName = pathinfo($input, PATHINFO_FILENAME);
$paths = createProjectStructure('.', $projectName, $resume);

// 1. Explode PDF
if (is_file($input)) {
    $pages = explodePages($input, $paths['pages']);
} else if (is_dir($input)) {
    // If it's a directory, assume it contains page images, copy them to pages dir
    $pages = glob($input . DIRECTORY_SEPARATOR . '*.{png,jpg,jpeg}', GLOB_BRACE);
    foreach ($pages as $page) {
        $dest = $paths['pages'] . DIRECTORY_SEPARATOR . basename($page);
        copy($page, $dest);
    }
    $pages = glob($paths['pages'] . DIRECTORY_SEPARATOR . '*.{png,jpg,jpeg}', GLOB_BRACE);
    natsort($pages);
} else {
    die("Error: Input must be a PDF file or a directory of images.\n");
}

// 2. OCR Loop
foreach ($pages as $idx => $pagePath) {
    $pageNum = $idx + 1;
    $mdFileName = "/page_" . sprintf("%03d", $pageNum) . ".md";
    $mdFilePath = $paths['ocr'] . $mdFileName;

    // check for existing MD to skip
    if ($resume && file_exists($mdFilePath) && filesize($mdFilePath) > 10) {
        echo "Skipping page $pageNum (already processed)...\n";
        continue;
    }
    echo "Processing page $pageNum...";

    // AI request
    $rawText = askGemini($pagePath);
    if ($rawText === "Error on page") {
        echo " FAILED (will retry next time)\n";
        continue;
    }
    // Extract images and clean text
    $cleanText = extractPictures($rawText, $pagePath, $paths['visuals'], $pageNum);

    // Save intermediate MD
    file_put_contents($paths['ocr'] . "/page_" . sprintf("%03d", $pageNum) . ".md", $cleanText);

    echo " OK\n";
    usleep(500000); // Small pause for API
}

// 3. Merging
mergeMarkdown($paths['ocr'], $paths['base'] . "/full_book.md");
echo "\nDONE! Project: " . $paths['base'] . "\n";
