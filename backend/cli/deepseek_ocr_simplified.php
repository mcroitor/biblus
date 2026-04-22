<?php

/**
 * OCR processing script for DeepSeek using Ollama models.
 * Usage: php deepseek_ocr_simplified.php --input=<pdf_or_dir> [options]
 * Options:
 *     --input: Path to the PDF file or directory containing PNG pages.
 *     --output: Base output directory for the project (default: ./output)
 *     --dpi: Resolution for PDF to PNG conversion (default: 300)
 *     --first-page: First page number to process (default: 1)
 *     --last-page: Last page number to process (default: -1, meaning all)
 *     --resume: Whether to resume an existing project (default: false)
 *     --language: Language hint for OCR (default: "english")
 * 
 * Mention: DeepSeek-OCR use specific prompt:
 * > "<image>\n<|grounding|>Convert the document to markdown."
 */
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

// ---------- Functions ----------
function write(string $message, $data = null)
{
    echo $message;
    if ($data !== null) {
        echo ": " . json_encode($data);
    }
    echo PHP_EOL;
}
function usage()
{
    echo "Usage: php deepseek_ocr_simplified.php --input=<pdf_or_dir> [options]\n";
    echo "Options:\n";
    echo "    --input: Path to the PDF file or directory containing PNG pages.\n";
    echo "    --output: Base output directory for the project (default: ./output)\n";
    echo "    --dpi: Resolution for PDF to PNG conversion (default: 300)\n";
    echo "    --first-page: First page number to process (default: 1)\n";
    echo "    --last-page: Last page number to process (default: -1, meaning all)\n";
    echo "    --resume: Whether to resume an existing project (default: false)\n";
    echo "    --language: Language hint for OCR (default: \"english\")\n";
}
/**
 * Creates the project directory structure for OCR processing.
 * @param string $baseDir Base directory for the project
 * @param string $projectName Name of the project
 * @param bool $resume Whether to resume an existing project
 * @return array{markdown: string, ocr: string, pages: string, project: string, visuals: string} Paths to the created directories
 */
function createProjectStructure(string $baseDir, string $projectName, bool $resume = false): array
{
    $projectDir = $baseDir . DIRECTORY_SEPARATOR . $projectName;
    $pagesDir = $projectDir . DIRECTORY_SEPARATOR . 'pages';
    $visualsDir = $projectDir . DIRECTORY_SEPARATOR . 'visuals';
    $ocrDir = $projectDir . DIRECTORY_SEPARATOR . 'ocr';
    $markdownDir = $projectDir . DIRECTORY_SEPARATOR . 'markdown';

    foreach ([$pagesDir, $visualsDir, $ocrDir, $markdownDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        } elseif (!$resume) {
            // Clear existing files if not resuming
            array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*'));
        }
    }

    return [
        'project' => $projectDir,
        'pages' => $pagesDir,
        'visuals' => $visualsDir,
        'ocr' => $ocrDir,
        'markdown' => $markdownDir
    ];
}
function isPdf(string $path): bool
{
    return is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
}

/**
 * Explodes a PDF into individual PNG pages.
 * @param string $pdfPath Path to the PDF file
 * @param string $outputDir Directory to save the PNG pages
 * @param int $dpi Resolution for the output images
 * @param int $firstPage First page number to generate
 * @param int $lastPage Last page number to generate, or -1 for all pages
 * @param bool $resume Whether to keep existing page images
 * @return string[] List of generated PNG file paths
 */
function explodePdf(
    string $pdfPath,
    string $outputDir,
    int $dpi = 300,
    int $firstPage = 1,
    int $lastPage = -1,
    bool $resume = false
): array {
    $pdf = new Imagick();
    $pdf->pingImage($pdfPath);
    $count = $pdf->getNumberImages();

    $pages = [];
    $lastPage = $lastPage > 0 ? min($lastPage, $count) : $count;

    for($i = $firstPage - 1; $i < $lastPage; $i++) {
        $pageName = sprintf("page_%03d.png", $i + 1);
        $pagePath = $outputDir . DIRECTORY_SEPARATOR . $pageName;

        if ($resume && file_exists($pagePath) && filesize($pagePath) > 0) {
            write("Skipping page " . ($i + 1) . ": already exists.");
            $pages[] = $pagePath;
            continue;
        }

        $im = new Imagick();
        $im->setResolution($dpi, $dpi);
        $im->readImage($pdfPath . "[" . $i . "]");
        $im->setImageFormat('png');
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        // process page: convert to grayscale, increase contrast
        $im->setImageType(Imagick::IMGTYPE_GRAYSCALE);
        $im->contrastImage(1);
        // set level: white point to 128, black point to 0
        $im->levelImage(0, 1.0, 65535);
        // convert to white and black only (dithered)
        $im->setImageType(Imagick::IMGTYPE_BILEVEL);

        if ($im->writeImage($pagePath)) {
            write("Generated page " . ($i + 1) . ": " . basename($pagePath));
            $pages[] = $pagePath;
        } else {
            write("Failed to generate page " . ($i + 1));
        }
        $im->clear();
        $im->destroy();
    }
    return $pages;
}
/**
 * Extracts image fragments based on <image> tags in the text
 * @param string $sourcePath Path to the source PNG
 * @param string $ocrMarkdown Text obtained from LLM
 * @param string $outputFolder Folder to save the extracted fragments
 * @return array List of created files and their corresponding tags
 */
function extractVisualsWithImagick(string $sourcePath, string $ocrMarkdown, string $outputFolder): array
{
    if (!file_exists($sourcePath)) return [];

    $im = new Imagick($sourcePath);
    $width = $im->getImageWidth();
    $height = $im->getImageHeight();

    // Regex for <image>[ymin, xmin, ymax, xmax](label)</image>
    $pattern = '/<image>\[(\d+),\s*(\d+),\s*(\d+),\s*(\d+)\]\((.*?)\)<\/image>/i';
    preg_match_all($pattern, $ocrMarkdown, $matches, PREG_SET_ORDER);

    $extractedFiles = [];

    foreach ($matches as $index => $match) {
        // $match[1] = ymin, [2] = xmin, [3] = ymax, [4] = xmax, [5] = label
        $ymin = (int)$match[1];
        $xmin = (int)$match[2];
        $ymax = (int)$match[3];
        $xmax = (int)$match[4];
        $label = $match[5] ?: "visual";

        // Translate from 0-1000 to pixels
        $realX = ($xmin / 1000) * $width;
        $realY = ($ymin / 1000) * $height;
        $realW = (($xmax - $xmin) / 1000) * $width;
        $realH = (($ymax - $ymin) / 1000) * $height;

        // Clone the main object to avoid modifying the original during cropping
        $crop = clone $im;

        // cropImage(width, height, x, y)
        $crop->cropImage((int)$realW, (int)$realH, (int)$realX, (int)$realY);
        $crop->setImageFormat("png");

        $fileName = sprintf("visual_%03d_%s.png", $index + 1, preg_replace('/[^a-z0-9]/i', '_', $label));
        $fullPath = $outputFolder . DIRECTORY_SEPARATOR . $fileName;

        if ($crop->writeImage($fullPath)) {
            $extractedFiles[] = [
                'tag' => $match[0],
                'path' => $fullPath,
                'name' => $fileName
            ];
        }

        $crop->clear();
        $crop->destroy();
    }

    $im->clear();
    $im->destroy();

    return $extractedFiles;
}

/**
 * Cleans up the OCR text by normalizing line breaks, merging hyphenated words,
 * and removing page numbers.
 * @param string $text Raw OCR text to be cleaned
 * @return string Cleaned OCR text
 */
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

/**
 * Compiles multiple markdown pages into a single document.
 * @param array $markdownPages List of markdown page file paths
 * @param string $outputPath Path to save the compiled document
 */
function compileDocument(array $markdownPages, string $outputPath)
{
    $compiled = "# Compiled Document\n\n";
    foreach ($markdownPages as $page) {
        $content = file_get_contents($page);
        $cleanContent = cleanMarkdown($content);
        $compiled .= "<!-- Page: " . basename($page) . " -->\n\n";
        $compiled .= $cleanContent . "\n\n";
    }
    file_put_contents($outputPath, $compiled);
}

// ---------- Main Script ----------

$longOptions = [
    "input:",
    "output::",
    "dpi::",
    "first-page::",
    "last-page::",
    "resume::",
    "language::"
];

$options = getopt("", $longOptions);

$inputPath = $options['input'] ?? null;
$outputDir = $options['output'] ?? __DIR__ . DIRECTORY_SEPARATOR . "output";
$dpi = isset($options['dpi']) ? (int)$options['dpi'] : 300;
$firstPage = isset($options['first-page']) ? (int)$options['first-page'] : 1;
$lastPage = isset($options['last-page']) ? (int)$options['last-page'] : -1;
$resume = isset($options['resume']) ? filter_var($options['resume'], FILTER_VALIDATE_BOOLEAN) : false;
$language = $options['language'] ?? "english";

$ollamaHost = 'http://85.120.14.163/ollama';
$ollamaHost = rtrim($ollamaHost, '/');
$ollamaModel = getenv('OLLAMA_MODEL') ?: 'deepseek-ocr:latest';

if (empty($inputPath)) {
    usage();
    exit(1);
}

$dirs = createProjectStructure($outputDir, '_temp', $resume);

if (isPdf($inputPath)) {
    write("Step: Exploding PDF into pages...");
    $pages = explodePdf($inputPath, $dirs['pages'], $dpi, $firstPage, $lastPage, $resume);
} elseif (is_dir($inputPath)) {
    write("Step: Copying PNG pages from input directory...");
    $files = glob($inputPath . DIRECTORY_SEPARATOR . "*.png");
    natsort($files);
    foreach ($files as $index => $file) {
        $pageName = sprintf("page_%03d", $index + 1);
        $destination = $dirs['pages'] . DIRECTORY_SEPARATOR . $pageName . '.png';
        if ($resume && is_file($destination) && filesize($destination) > 0) {
            continue;
        }
        copy($file, $destination);
    }
    write("Copied " . count($files) . " pages.");
} else {
    write("Error: PDF explode is disabled and input is not a directory.");
    exit(1);
}

$pageFiles = glob($dirs['pages'] . DIRECTORY_SEPARATOR . "*.png");
if (empty($pageFiles)) {
    write("Error: No pages found to process.");
    exit(1);
}
natsort($pageFiles);
$pageFiles = array_values($pageFiles);

$maxRetries = 2;
$totalPages = count($pageFiles);
$firstPage = max(1, $firstPage);

if ($firstPage > $totalPages) {
    write("Error: --first-page ({$firstPage}) is greater than the total page count ({$totalPages}).");
    exit(1);
}

$lastPage = $lastPage > 0 ? max($firstPage, $lastPage) : $totalPages;
$lastPage = min($lastPage, $totalPages);

write("Step: Processing page range {$firstPage}-{$lastPage} of {$totalPages}.");

// skip pages that are outside of the specified range
$pageFiles = array_slice($pageFiles, $firstPage - 1, $lastPage - $firstPage + 1);

$selectedPages = count($pageFiles);
$processedPages = 0;
$skippedPages = 0;
$failedPages = 0;
$pageNumber = $firstPage;

foreach ($pageFiles as $pageFile) {
    write("Processing page {$pageNumber}: {$pageFile}...");
    $pageName = pathinfo($pageFile, PATHINFO_FILENAME);
    $ocrOutputPath = $dirs['ocr'] . DIRECTORY_SEPARATOR . $pageName . '.md';

    if ($resume && is_file($ocrOutputPath) && filesize($ocrOutputPath) > 0) {
        write("Skipping page {$pageNumber}: OCR markdown already exists.");
        $skippedPages++;
        $pageNumber++;
        continue;
    }

    $image = file_get_contents($pageFile);
    $imageBase64 = base64_encode($image);
    $response = null;
    $maxAttempts = $maxRetries + 1;
    $chatEndpoint = $ollamaHost . '/api/chat';

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        write("Requesting Ollama: {$chatEndpoint} (page {$pageNumber}, attempt {$attempt}/{$maxAttempts}, model {$ollamaModel})");
        $ch = curl_init($chatEndpoint);

        $message = [
            "model" => $ollamaModel,
            "messages" => [
                [
                    "role" => "user",
                    "content" => "Convert the document to markdown. Language: {$language}",
                    "images" => [$imageBase64]
                ]
            ],
            "stream" => false
        ];

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $result = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($attempt < $maxAttempts) {
                write("[error] cURL error for page {$pageNumber}: {$curlError}. Retrying... (Attempt {$attempt}/{$maxAttempts})");
                usleep(500000);
            } else {
                write("[error] cURL error for page {$pageNumber}: {$curlError}. No attempts left (Attempt {$attempt}/{$maxAttempts})");
            }
            continue;
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            if ($attempt < $maxAttempts) {
                write("[error] Ollama returned HTTP {$httpCode} for page {$pageNumber}. Retrying... (Attempt {$attempt}/{$maxAttempts})");
                usleep(500000);
            } else {
                write("[error] Ollama returned HTTP {$httpCode} for page {$pageNumber}. No attempts left (Attempt {$attempt}/{$maxAttempts})");
            }
            continue;
        }

        $response = json_decode($result, JSON_OBJECT_AS_ARRAY);

        if (!is_array($response)) {
            $jsonError = json_last_error_msg();
            if ($attempt < $maxAttempts) {
                write("[error] Invalid JSON for page {$pageNumber}: {$jsonError}. Retrying... (Attempt {$attempt}/{$maxAttempts})");
                usleep(500000);
            } else {
                write("[error] Invalid JSON for page {$pageNumber}: {$jsonError}. No attempts left (Attempt {$attempt}/{$maxAttempts})");
            }
            continue;
        }

        if (empty($response['message']['content'])) {
            $error = isset($response['error']) ? $response['error'] : 'Unknown error';
            if ($attempt < $maxAttempts) {
                write("[error] Received empty content from Ollama for page {$pageNumber}. Error: {$error}. Retrying... (Attempt {$attempt}/{$maxAttempts})");
                usleep(500000);
            } else {
                write("[error] Received empty content from Ollama for page {$pageNumber}. Error: {$error}. No attempts left (Attempt {$attempt}/{$maxAttempts})");
            }
            write("[error] result: {$result}");
            continue;
        }

        break;
    }

    if (empty($response['message']['content'])) {
        write("[error] Failed to get valid response from Ollama for page {$pageNumber} after {$maxAttempts} attempts. Skipping page.");
        $failedPages++;
        $pageNumber++;
        continue;
    } else {
        write("Successfully processed page {$pageNumber}.");
        file_put_contents($ocrOutputPath, $response['message']['content']);
        $processedPages++;
    }
    $pageNumber++;
}

write("Summary: selected {$selectedPages} page(s), processed {$processedPages}, skipped {$skippedPages}, failed {$failedPages}.");

// clean and compile document
$markdownPages = glob($dirs['ocr'] . DIRECTORY_SEPARATOR . "*.md");
if (!empty($markdownPages)) {
    natsort($markdownPages);
    $markdownPages = array_values($markdownPages);
    write("Step: Compiling markdown pages into a single document...");
    $outputPath = $dirs['project'] . DIRECTORY_SEPARATOR . "full_book.md";
    compileDocument($markdownPages, $outputPath);
    write("Compiled document saved to: {$outputPath}");
} else {
    write("No markdown pages found to compile.");
}

write("*** COMPLETED SUCCESSFULLY ***");
