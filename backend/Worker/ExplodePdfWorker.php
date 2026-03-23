<?php

namespace Core\Worker;

class ExplodePdfWorker {
    private $pdf2ppm;
    private $config;

    public function __construct($pdf2ppm = "pdf2ppm", $config = []) {
        if(!is_executable($pdf2ppm)) {
            throw new \Exception("pdf2ppm is not executable: $pdf2ppm");
        }
        $this->pdf2ppm = $pdf2ppm;
        $this->config = $config;
    }

    public function Execute($pdfPath, $outputDir) {
        if(!file_exists($pdfPath)) {
            throw new \Exception("PDF file does not exist: $pdfPath");
        }
        if(!is_dir($outputDir)) {
            throw new \Exception("Output directory does not exist: $outputDir");
        }

        $command = escapeshellcmd("$this->pdf2ppm -png " . escapeshellarg($pdfPath) . " " . escapeshellarg("$outputDir/page"));
        exec($command, $output, $returnVar);
        if($returnVar !== 0) {
            throw new \Exception("Failed to explode PDF: " . implode("\n", $output));
        }
    }
}
