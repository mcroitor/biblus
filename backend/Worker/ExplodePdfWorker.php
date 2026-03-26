<?php

namespace Worker;

class ExplodePdfWorker {
    public const string JPEG = "jpeg";
    public const string PNG = "png";
    public const string TIFF = "tiff";
    public const int DPI = 300;
    
    private string $format;
    private int $dpi;

    public function __construct(string $format = self::PNG, int $dpi = self::DPI) {
        $this->format = $format;
        $this->dpi = $dpi;
    }

    public function Execute(string $pdfPath, string $outputDir): array {
        if (!file_exists($pdfPath)) {
            throw new \Exception("PDF file does not exist: $pdfPath");
        }

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \Exception("Cannot create output directory: $outputDir");
            }
        }

        return $this->explode($pdfPath, $outputDir);
    }

    private function explode(string $pdfPath, string $outputDir): array {
        if (!extension_loaded('imagick')) {
            throw new \Exception("Imagick extension is not installed");
        }

        $im = new \Imagick();
        $im->setResolution($this->dpi, $this->dpi);
        $im->readImage($pdfPath);

        $paths = [];
        foreach ($im as $i => $page) {
            $page->setImageFormat($this->format);
            $target = $outputDir . DIRECTORY_SEPARATOR . sprintf("page_%03d.%s", $i + 1, $this->format);
            
            if (!$page->writeImage($target)) {
                continue;
            }
            $paths[] = $target;
        }

        $im->clear();
        $im->destroy();

        if (empty($paths)) {
            throw new \Exception("No pages were rendered from PDF");
        }

        return $paths;
    }
}
