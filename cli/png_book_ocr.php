<?php

/**
 * CLI script for batch OCR of a book with merging into a single file
 * Usage: php book_ocr.php ./input_dir ./output_results
 */

define('OLLAMA_API_URL', 'http://localhost:11434/api/chat');
define('OLLAMA_MODEL', 'deepseek-v2'); 
define('TIMEOUT_SECONDS', 600);

if ($argc < 2) {
    die("Usage: php book_ocr.php <input_dir> [output_dir]\n");
}

$inputDir = rtrim($argv[1], DIRECTORY_SEPARATOR);
$baseOutputDir = rtrim($argv[2] ?? 'output_dir', DIRECTORY_SEPARATOR);

if (!is_dir($inputDir)) die("Error: Input directory not found.\n");
if (!is_dir($baseOutputDir)) mkdir($baseOutputDir, 0755, true);

$files = glob($inputDir . DIRECTORY_SEPARATOR . "*.png");
natsort($files);

if (empty($files)) die("No PNG files found.\n");

$processedPages = [];

echo "Found " . count($files) . " pages. Starting processing...\n";

foreach ($files as $index => $imagePath) {
    $fileName = basename($imagePath, '.png');
    echo "\n>>> Page [" . ($index + 1) . "/" . count($files) . "]: $fileName\n";

    $pageOutputDir = $baseOutputDir . DIRECTORY_SEPARATOR . $fileName;
    if (!is_dir($pageOutputDir)) mkdir($pageOutputDir, 0755, true);

    $imagesSubDir = $pageOutputDir . DIRECTORY_SEPARATOR . "images";
    if (!is_dir($imagesSubDir)) mkdir($imagesSubDir, 0755, true);

    $outputMdFile = $pageOutputDir . DIRECTORY_SEPARATOR . "index.md";

    try {
        // 1. Detect image zones and get their coordinates
        $groundingInfo = detectImagesAndCoordinates($imagePath);
        
        $placeholders = [];
        if (!empty($groundingInfo)) {
            $placeholders = cropImagesAndGetPlaceholders($imagePath, $groundingInfo, $imagesSubDir, "images");
        }

        // 2. OCR text with placeholders
        $finalMarkdown = performOcrWithImageReferences($imagePath, $placeholders);

        file_put_contents($outputMdFile, $finalMarkdown);
        $processedPages[] = ['dir' => $fileName, 'file' => $outputMdFile];
        
        echo " - Done.\n";

    } catch (Exception $e) {
        echo " [!] Error: " . $e->getMessage() . "\n";
    }
}

// --- FINAL STEP: Assembling the entire book ---
echo "\n--- Assembling full_book.md ---\n";

$fullBookContent = "# Combined OCR Result\n\n";
$fullBookContent .= "> Generated on: " . date('Y-m-d H:i:s') . "\n\n---\n\n";

foreach ($processedPages as $page) {
    $content = file_get_contents($page['file']);
    
    // Correct image paths for the combined file
    // Was: (images/element_1.png) -> Now: (page_name/images/element_1.png)
    $correctedContent = preg_replace('/\((images\/.*?)\)/', "(" . $page['dir'] . "/$1)", $content);
    
    $fullBookContent .= "## Page: {$page['dir']}\n\n";
    $fullBookContent .= $correctedContent . "\n\n---\n\n";
}

file_put_contents($baseOutputDir . DIRECTORY_SEPARATOR . "full_book.md", $fullBookContent);

echo "\n*** SUCCESS! ***\n";
echo "Individual pages: $baseOutputDir/[page_name]/\n";
echo "Full book: $baseOutputDir/full_book.md\n";

// ============================================================================
// Additional functions for image detection, cropping, and OCR with placeholders
// ============================================================================

function detectImagesAndCoordinates($imagePath) {
    $imageData = base64_encode(file_get_contents($imagePath));
    $prompt = "Analyze this page. Identify all images, diagrams, or charts. Return a JSON array of their normalized [ymin, xmin, ymax, xmax] coordinates (0-1000) and labels. Output ONLY JSON. Format: [{\"box_2d\": [0,0,0,0], \"label\": \"description\"}]";
    $res = sendOllamaRequest($prompt, [$imageData]);
    return (preg_match('/\[\s*\{.*\}\s*\]/s', $res, $m)) ? json_decode($m[0], true) : [];
}

function cropImagesAndGetPlaceholders($sourcePath, $info, $outDir, $relDir) {
    $img = imagecreatefrompng($sourcePath);
    list($w_orig, $h_orig) = getimagesize($sourcePath);
    $placeholders = [];
    foreach ($info as $i => $item) {
        $b = $item['box_2d'];
        $y1 = ($b[0]/1000)*$h_orig; $x1 = ($b[1]/1000)*$w_orig;
        $y2 = ($b[2]/1000)*$h_orig; $x2 = ($b[3]/1000)*$w_orig;
        $cw = max(1, $x2-$x1); $ch = max(1, $y2-$y1);
        $name = "img_" . ($i+1) . ".png";
        $crop = imagecrop($img, ['x'=>$x1, 'y'=>$y1, 'width'=>$cw, 'height'=>$ch]);
        if ($crop) {
            imagepng($crop, $outDir.DIRECTORY_SEPARATOR.$name);
            $placeholders[] = ['box'=>$b, 'markdown'=>"![" . ($item['label']??"img") . "]($relDir/$name)", 'desc'=>$item['label']??"img"];
            imagedestroy($crop);
        }
    }
    imagedestroy($img);
    return $placeholders;
}

function performOcrWithImageReferences($path, $pls) {
    $ctx = "Visuals identified:\n";
    foreach ($pls as $p) $ctx .= "- Coords [".implode(',',$p['box'])."]: {$p['markdown']}\n";
    $prompt = "Perform OCR on this image. $ctx\nInsert the Markdown tags at their correct positions. Output ONLY raw Markdown.";
    return sendOllamaRequest($prompt, [base64_encode(file_get_contents($path))], 0.1);
}

function sendOllamaRequest($prompt, $imgs, $temp=0.2) {
    $p = ["model"=>OLLAMA_MODEL, "messages"=>[["role"=>"user", "content"=>$prompt, "images"=>$imgs]], "stream"=>false, "options"=>["temperature"=>$temp, "num_predict"=>4096]];
    $ch = curl_init(OLLAMA_API_URL);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($p));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_SECONDS);
    $r = curl_exec($ch);
    return json_decode($r, true)['message']['content'] ?? '';
}
