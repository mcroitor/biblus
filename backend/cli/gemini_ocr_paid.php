<?php

/**
 * OMNIBUS GEMINI OCR (Pro Edition)
 * Features: Resume capability, Cost estimation, 429 Handling, PDF/Dir support
 */

// --- SETTINGS ---
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'YOUR_KEY_HERE');
define('MODEL_NAME', 'gemini-2.5-flash');
define('IMG_PATTERN', '/<image>\[(\d+),\s*(\d+),\s*(\d+),\s*(\d+)\]\((.*?)\)<\/image>/i');

// Gemini 2.5 Flash rates ($ per 1M tokens)
define('COST_INPUT_1M', 0.075);
define('COST_OUTPUT_1M', 0.30);

const PROMPT = <<<EOT
You are an expert OCR engine. This document is a chess book page.
1. Extract all text to Markdown (headers, paragraphs, lists, tables, etc.).
2. For EVERY chess diagram, provide a bounding box: <image>[ymin, xmin, ymax, xmax](label)</image>.
3. If in doubt, provide a LARGER box. It is better to have extra white space than to cut off any part of the board.
4. Return ONLY raw Markdown.
EOT;

// --- SYSTEM FUNCTIONS ---

function ensureDirExists(string $path): void
{
    if (!is_dir($path)) mkdir($path, 0755, true);
}

function createProjectStructure(string $baseDir, string $projectName, bool $resume): array
{
    // If resume, use clean name, otherwise add ID for uniqueness
    $folderName = $resume ? $projectName : $projectName . '_' . date('Ymd_His');
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

// --- IMAGE PROCESSING ---

function explodePages(string $pdfPath, string $outputDir): array
{
    if (!extension_loaded('imagick')) die("Error: Imagick required.\n");

    $pdf = new Imagick();
    $pdf->pingImage($pdfPath);
    $count = $pdf->getNumberImages();
    $paths = [];

    echo "Checking/Exploding $count pages...\n";
    for ($i = 0; $i < $count; $i++) {
        $path = $outputDir . DIRECTORY_SEPARATOR . sprintf("page_%03d.png", $i + 1);
        if (!file_exists($path)) {
            $im = new Imagick();
            $im->setResolution(300, 300);
            $im->readImage($pdfPath . "[" . $i . "]");
            $im->setImageFormat('png');
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $im->writeImage($path);
            $im->clear();
            $im->destroy();
            echo "+";
        } else {
            echo ".";
        }
        $paths[] = $path;
    }
    echo " Done.\n";
    return $paths;
}

function extractPictures(string $text, string $pageImg, string $outDir, int $pIdx): string
{
    if (!file_exists($pageImg)) return $text;

    $im = new Imagick($pageImg);
    $w = $im->getImageWidth();
    $h = $im->getImageHeight();

    $imgCount = 0;

    $processed = preg_replace_callback(IMG_PATTERN, function ($m) use ($im, $w, $h, $outDir, $pIdx, &$imgCount) {
        $imgCount++;

        $ymin = (float)$m[1];
        $xmin = (float)$m[2];
        $ymax = (float)$m[3];
        $xmax = (float)$m[4];
        $label = $m[5] ?: "chess_diagram";

        $hPad = 0.05; // 5%
        $vPad = 0.03; // 3%
        
        $objW = $xmax - $xmin;
        $objH = $ymax - $ymin;
        
        $padW = $objW * $hPad;
        $padH = $objH * $vPad;

        // 1. Read and expand bounding box with padding
        $nx1 = max(0, $xmin - $padW);
        $ny1 = max(0, $ymin - $padH);
        $nx2 = min(1000, $xmax + $padW);
        $ny2 = min(1000, $ymax + $padH);

        // 2. Convert to PIXELS (integers)
        // First, find reference points x1, y1, x2, y2
        $ix1 = (int)round(($nx1 / 1000) * $w);
        $iy1 = (int)round(($ny1 / 1000) * $h);
        $ix2 = (int)round(($nx2 / 1000) * $w);
        $iy2 = (int)round(($ny2 / 1000) * $h);

        // 3. Calculate width and height
        $rw = $ix2 - $ix1;
        $rh = $iy2 - $iy1;

        // 4. Final geometry check (to prevent Imagick Fatal Error)
        if ($rw <= 0 || $rh <= 0) return "";
        
        // Ensure the crop does not exceed image boundaries (Safety Clamp)
        if (($ix1 + $rw) > $w) $rw = $w - $ix1;
        if (($iy1 + $rh) > $h) $rh = $h - $iy1;

        $fName = sprintf("p%03d_img%02d.png", $pIdx, $imgCount);
        
        try {
            $crop = clone $im;
            // Crop: width, height, offset X, offset Y
            $crop->cropImage($rw, $rh, $ix1, $iy1);
            
            // Remove extra white margins if they were included during padding expansion
            $crop->trimImage(0);
            // After trimImage, reset the page to make the file "clean"
            $crop->setImagePage(0, 0, 0, 0);
            
            $crop->writeImage($outDir . DIRECTORY_SEPARATOR . $fName);
            $crop->destroy();
            
            // Path relative to the project root (assuming MD is in the root)
            return "![{$label}](./visuals/{$fName})";
        } catch (Exception $e) {
            return "";
        }
    }, $text);

    $im->destroy();
    return $processed;
}

function cleanMarkdown(string $text): string
{
    // 1. Normalize line breaks and trim trailing whitespace
    $clean = preg_replace("/\r\n|\r/", "\n", $text);
    $clean = preg_replace("/[ \t]+$/m", "", $clean); // Trim invisible spaces at the end of lines

    // 2. Merge word-break hyphenations (with support for Cyrillic /u)
    // Consider that after a hyphen there may be spaces before the line break
    $clean = preg_replace("/([а-яА-Яa-zA-Z])-\s*\n\s*([а-яА-Яa-zA-Z])/u", "$1$2", $clean);

    // 3. Remove page numbers (more cautious)
    // Remove only isolated numbers (1-3 digits), if they are not part of a list
    $clean = preg_replace("/^\s*\d{1,3}\s*$/m", "", $clean);

    // 4. Collapse excessive empty lines
    $clean = preg_replace("/\n{3,}/", "\n\n", $clean);

    return trim($clean);
}

// --- NETWORKING AND STATISTICS ---

$totalCost = 0;

function askGemini(string $imagePath): array
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . MODEL_NAME . ":generateContent?key=" . GEMINI_API_KEY;
    $prompt = PROMPT;

    $payload = [
        "contents" => [["parts" => [
            ["text" => $prompt],
            ["inline_data" => ["mime_type" => "image/png", "data" => base64_encode(file_get_contents($imagePath))]]
        ]]],
        "generationConfig" => ["temperature" => 0.1]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


    do {
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode == 429) {
            echo " (Rate limit! Sleep 20s...) ";
            sleep(20);
        }
    } while ($httpCode == 429);
    curl_close($ch);

    $json = json_decode($res, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

    // Calculate tokens for statistics (very rough: 1 token ≈ 4 characters for text)
    $inTokens = 1000; // Fixed average cost per image
    $outTokens = $text ? strlen($text) / 4 : 0;
    $cost = ($inTokens / 1000000 * COST_INPUT_1M) + ($outTokens / 1000000 * COST_OUTPUT_1M);

    return ['text' => $text, 'cost' => $cost];
}

// --- MERGING FUNCTIONS ---

function mergeMarkdown(string $ocrDir, string $outputPath): void
{
    $files = glob($ocrDir . DIRECTORY_SEPARATOR . '*.md');
    natsort($files);
    $fullMd = "# OCR Export - " . date('Y-m-d') . "\n\n";
    foreach ($files as $file) {
        $text = file_get_contents($file);
        $fullMd .= $text . "\n\n---\n\n";
    }
    $cleanMd = cleanMarkdown($fullMd);
    file_put_contents($outputPath, $cleanMd);
}

// --- RUNNER ---

$input = $argv[1] ?? die("Usage: php omnibus_gemini.php <file.pdf> | <scans_dir> [--resume]\n");
$resume = in_array('--resume', $argv);
$projectName = pathinfo($input, PATHINFO_FILENAME);
$paths = createProjectStructure('.', $projectName, $resume);

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

foreach ($pages as $idx => $pagePath) {
    $pageNum = $idx + 1;
    $mdFile = $paths['ocr'] . "/page_" . sprintf("%03d", $pageNum) . ".md";

    if ($resume && file_exists($mdFile) && filesize($mdFile) > 10) {
        echo "Page $pageNum: Already exists. Skipping.\n";
        continue;
    }

    echo "Processing page $pageNum...";
    $result = askGemini($pagePath);

    if (!$result['text'] || $result['text'] === "Error on page") {
        echo " FAILED.\n";
        continue;
    }

    $cleanText = extractPictures($result['text'], $pagePath, $paths['visuals'], $pageNum);
    file_put_contents($mdFile, $cleanText);

    $totalCost += $result['cost'];
    printf(" OK (est. cost: $%.4f)\n", $result['cost']);
    usleep(300000);
}

mergeMarkdown($paths['ocr'], $paths['base'] . "/full_book.md");
printf("\nDONE! Project: %s\nTotal Estimated Cost: $%.4f\n", $paths['base'], $totalCost);
