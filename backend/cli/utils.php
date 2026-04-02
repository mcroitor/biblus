<?php

function ensureDirExists(string $path): void {
    if (!is_dir($path)) mkdir($path, 0755, true);
}

function createProjectStructure(string $baseDir, string $projectName): array {
    $projectDir = $baseDir . DIRECTORY_SEPARATOR . $projectName . '_' . uniqid();
    $dirs = [
        'base'    => $projectDir,
        'pages'   => $projectDir . DIRECTORY_SEPARATOR . 'pages',
        'visuals' => $projectDir . DIRECTORY_SEPARATOR . 'visuals',
        'ocr'     => $projectDir . DIRECTORY_SEPARATOR . 'ocr',
    ];
    foreach ($dirs as $dir) ensureDirExists($dir);
    return $dirs;
}

function explodePages(string $pdfPath, string $outputDir): array {
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
        $im->writeImage($path);
        $pagePaths[] = $path;
        
        $im->clear();
        $im->destroy();
        echo ".";
    }
    echo " Done.\n";
    return $pagePaths;
}

function extractPictures(string $text, string $pageImagePath, string $outputDir, int $pageIdx): string {
    $im = new Imagick($pageImagePath);
    $w = $im->getImageWidth();
    $h = $im->getImageHeight();

    // Use callback to replace each tag individually
    $processedText = preg_replace_callback(IMG_PATTERN, function($m) use ($im, $w, $h, $outputDir, $pageIdx) {
        static $imgCount = 0;
        $imgCount++;

        $ymin = $m[1]; $xmin = $m[2]; $ymax = $m[3]; $xmax = $m[4];
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

function mergeMarkdown(string $ocrDir, string $outputPath): void {
    $files = glob($ocrDir . DIRECTORY_SEPARATOR . '*.md');
    natsort($files); // Correct sorting: 1, 2, 10

    $fullMd = "# Book OCR Export\n\n";
    foreach ($files as $file) {
        $fullMd .= "\n" . file_get_contents($file) . "\n\n---\n\n";
    }
    file_put_contents($outputPath, $fullMd);
}