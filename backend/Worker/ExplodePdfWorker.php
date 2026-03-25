<?php

namespace Core\Worker;

class ExplodePdfWorker {
    public const string JPEG = "jpeg";
    public const string PNG = "png";
    public const string TIFF = "tiff";
    public const int DPI = 300;
    private $pdf2ppm = "pdftoppm";
    private $config = [
        "format" => self::PNG,
        "dpi" => self::DPI
    ];

    public function __construct($pdf2ppm = "pdftoppm", $config = []) {
        if(!is_executable($pdf2ppm)) {
            throw new \Exception("pdf2ppm is not executable: $pdf2ppm");
        }
        $this->pdf2ppm = $pdf2ppm;
        $this->config = array_merge($this->config, $config) ;
    }

    public function Execute($pdfPath, $outputDir) {
        if(!file_exists($pdfPath)) {
            throw new \Exception("PDF file does not exist: $pdfPath");
        }
        if(!is_dir($outputDir)) {
            throw new \Exception("Output directory does not exist: $outputDir");
        }

        $command = escapeshellcmd("{$this->pdf2ppm} -{$this->config['format']} -r {$this->config['dpi']} " . escapeshellarg($pdfPath) . " " . escapeshellarg("$outputDir/page"));
        exec($command, $output, $returnVar);
        if($returnVar !== 0) {
            throw new \Exception("Failed to explode PDF: " . implode("\n", $output));
        }
        // return list of generated image paths
        $generatedFiles = [];
        foreach ($output as $line) {
            if (preg_match('/^page-\d+\.(jpg|jpeg|png|tiff)$/i', $line)) {
                $generatedFiles[] = "$outputDir/$line";
            }
        }
        return $generatedFiles;
    }
}
