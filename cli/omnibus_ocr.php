<?php

/**
 * OMNIBUS OCR: PDF/PNG -> Markdown with image preservation
 * Usage: php omnibus_ocr.php <input_path> [output_dir]
 */

// --- SETTINGS ---
define('OLLAMA_API_URL', 'http://localhost:11434/api/chat');
define('OLLAMA_MODEL', 'deepseek-v2'); // Recommended model with Vision (16b+), Llama-3.2-Vision
define('PDF_DPI', 300);                // DPI for high-quality OCR
define('TIMEOUT_SECONDS', 600);        // 10 minutes per page

// --- 1. ENVIRONMENT AND ARGUMENT CHECK ---
if ($argc < 2) {
    die("Usage: php omnibus_ocr.php <input_pdf_or_dir> [output_dir]\n");
}

$inputPath = rtrim($argv[1], DIRECTORY_SEPARATOR);
$baseOutputDir = rtrim($argv[2] ?? 'book_export', DIRECTORY_SEPARATOR);

if (!is_dir($baseOutputDir)) mkdir($baseOutputDir, 0755, true);

// --- 2. PREPARING THE LIST OF PAGES ---
$pagesToProcess = [];
$isTempDir = false;
$tempDir = $baseOutputDir . DIRECTORY_SEPARATOR . "_internal_temp";

if (is_file($inputPath) && strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'pdf') {
    $pagesToProcess = convertPdfToPng($inputPath, $tempDir);
    $isTempDir = true;
} elseif (is_dir($inputPath)) {
    $pagesToProcess = glob($inputPath . DIRECTORY_SEPARATOR . "*.png");
    natsort($pagesToProcess);
} else {
    die("Error: Input must be a PDF file or a directory containing PNG images.\n");
}

if (empty($pagesToProcess)) die("Error: No pages found to process.\n");

// --- 3. MAIN PROCESSING LOOP ---
$manifest = [];
echo "\nStarting OCR for " . count($pagesToProcess) . " pages...\n";

foreach ($pagesToProcess as $index => $imagePath) {
    $pageName = sprintf("page_%03d", $index + 1);
    echo "Processing [$pageName]...";

    $pageDir = $baseOutputDir . DIRECTORY_SEPARATOR . $pageName;
    if (!is_dir($pageDir)) mkdir($pageDir, 0755, true);

    $imgDir = $pageDir . DIRECTORY_SEPARATOR . "images";
    if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);

    $mdFile = $pageDir . DIRECTORY_SEPARATOR . "index.md";

    try {
        // Step A: Detect and crop visual elements
        $grounding = detectVisuals($imagePath);
        $placeholders = [];
        if (!empty($grounding)) {
            $placeholders = cropVisuals($imagePath, $grounding, $imgDir, "images");
        }

        // Step B: OCR text with placeholders for cropped visuals
        $markdown = performOcr($imagePath, $placeholders);
        file_put_contents($mdFile, $markdown);

        $manifest[] = ['name' => $pageName, 'file' => $mdFile];
        echo " OK\n";

    } catch (Exception $e) {
        echo " FAILED: " . $e->getMessage() . "\n";
    }
}

// --- 4. ASSEMBLING THE FINAL BOOK ---
assembleFullBook($baseOutputDir, $manifest);

// Cleaning up temporary files
if ($isTempDir) {
    echo "Cleaning up temporary PNG files...\n";
    foreach (glob($tempDir . "/*.png") as $tFile) unlink($tFile);
    rmdir($tempDir);
}

echo "\n*** COMPLETED SUCCESSFULLY ***\n";
echo "Final document: $baseOutputDir/full_book.md\n";


// ============================================================================
// FUNCTIONS
// ============================================================================

function convertPdfToPng($pdf, $out) {
    if (!is_dir($out)) mkdir($out, 0755, true);
    echo "Rendering PDF to high-res PNGs (this may take time)... \n";
    $im = new Imagick();
    $im->setResolution(PDF_DPI, PDF_DPI);
    $im->readImage($pdf);
    $paths = [];
    foreach ($im as $i => $page) {
        $page->setImageFormat('png');
        $target = $out . DIRECTORY_SEPARATOR . sprintf("page_%03d.png", $i + 1);
        $page->writeImage($target);
        $paths[] = $target;
        echo ".";
    }
    $im->clear(); $im->destroy();
    echo " Done.\n";
    return $paths;
}

function detectVisuals($path) {
    $img = base64_encode(file_get_contents($path));
    $prompt = "Analyze the image. Identify images, diagrams, or charts. Return a JSON array of their [ymin, xmin, ymax, xmax] coordinates (0-1000) and descriptions. Output ONLY JSON. Format: [{\"box_2d\": [y1,x1,y2,x2], \"label\": \"description\"}]";
    $raw = sendRequest($prompt, [$img]);
    if (preg_match('/\[\s*\{.*\}\s*\]/s', $raw, $m)) {
        return json_decode($m[0], true) ?: [];
    }
    return [];
}

function cropVisuals($src, $info, $out, $rel) {
    $gd = imagecreatefrompng($src);
    list($w_orig, $h_orig) = getimagesize($src);
    $pls = [];
    foreach ($info as $i => $item) {
        $b = $item['box_2d'];
        $y1 = ($b[0]/1000)*$h_orig; $x1 = ($b[1]/1000)*$w_orig;
        $y2 = ($b[2]/1000)*$h_orig; $x2 = ($b[3]/1000)*$w_orig;
        $cw = max(2, $x2-$x1); $ch = max(2, $y2-$y1);
        
        $crop = imagecrop($gd, ['x'=>$x1, 'y'=>$y1, 'width'=>$cw, 'height'=>$ch]);
        if ($crop) {
            $fname = "img_" . ($i+1) . ".png";
            imagepng($crop, $out.DIRECTORY_SEPARATOR.$fname);
            $pls[] = [
                'box' => $b,
                'md' => "![" . ($item['label']??"visual") . "]($rel/$fname)",
                'desc' => $item['label']??"visual"
            ];
            imagedestroy($crop);
        }
    }
    imagedestroy($gd);
    return $pls;
}

function performOcr($path, $pls) {
    $ctx = "I have extracted visual elements. Use these tags at their locations:\n";
    foreach ($pls as $p) $ctx .= "- Around [".implode(',',$p['box'])."]: {$p['md']}\n";
    
    $prompt = "Perform high-fidelity OCR to Markdown. $ctx\nMaintain structure (headers, tables). Insert visual tags logically. Use LaTeX for math. NO filler, ONLY Markdown.";
    return sendRequest($prompt, [base64_encode(file_get_contents($path))], 0.1);
}

function assembleFullBook($base, $manifest) {
    echo "Assembling full_book.md...\n";
    $full = "# Full Book OCR\n\n";
    foreach ($manifest as $page) {
        $c = file_get_contents($page['file']);
        // Correct image paths for the combined file
        // From: (images/element_1.png) -> To: (page_name/images/element_1.png)
        $c = preg_replace('/\((images\/.*?)\)/', "(" . $page['name'] . "/$1)", $c);
        $full .= "## " . strtoupper($page['name']) . "\n\n" . $c . "\n\n---\n\n";
    }
    file_put_contents($base . DIRECTORY_SEPARATOR . "full_book.md", $full);
}

function sendRequest($prompt, $imgs, $temp=0.2) {
    $p = [
        "model" => OLLAMA_MODEL,
        "messages" => [["role" => "user", "content" => $prompt, "images" => $imgs]],
        "stream" => false,
        "options" => ["temperature" => $temp, "num_predict" => 4096]
    ];
    $ch = curl_init(OLLAMA_API_URL);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($p));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_SECONDS);
    $r = curl_exec($ch);
    $res = json_decode($r, true);
    curl_close($ch);
    return $res['message']['content'] ?? '';
}
