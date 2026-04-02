<?php

namespace Worker;

class ExplodePdfWorker
{
    public const string JPEG = "jpeg";
    public const string PNG = "png";
    public const string TIFF = "tiff";
    public const int DPI = 300;

    private string $format;
    private int $dpi;

    public function __construct(string $format = self::PNG, int $dpi = self::DPI)
    {
        $this->format = $format;
        $this->dpi = $dpi;
    }

    public function Execute(string $pdfPath, string $outputDir): array
    {
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

    private function explode(string $pdfPath, string $outputDir): array
    {
        if (!extension_loaded('imagick')) {
            throw new \Exception("Imagick extension is not installed");
        }

        $pdf = new \Imagick();
        $pdf->setResolution($this->dpi, $this->dpi);
        $pdf->readImage($pdfPath);
        $count = $pdf->getNumberImages();

        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $path = $outputDir . DIRECTORY_SEPARATOR . sprintf("page_%03d.png", $i + 1);
            if (file_exists($path)){
                echo ".";
                $paths[] = $path;
                continue;
            }
        
            $page = new \Imagick();
            $page->setResolution(300, 300);
            $page->readImage($pdfPath . "[" . $i . "]");
            $page->setImageFormat('png');
            $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $page->writeImage($path);
            $page->clear();
            $page->destroy();
            echo "+";
            $paths[] = $path;
        }

        $pdf->clear();
        $pdf->destroy();

        if (empty($paths)) {
            throw new \Exception("No pages were rendered from PDF");
        }

        return $paths;
    }
}
