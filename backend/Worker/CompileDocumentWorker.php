<?php

namespace Worker;

class CompileDocumentWorker {
    private string $title;
    private string $imagePathCorrection;

    public function __construct(string $title = "Document", string $imagePathCorrection = '') {
        $this->title = $title;
        $this->imagePathCorrection = $imagePathCorrection;
    }

    public function Execute(array $markdownFiles, string $outputPath, ?string $baseOutputDir = null): string {
        if (empty($markdownFiles)) {
            throw new \Exception("No markdown files provided");
        }

        $baseDir = $baseOutputDir ?? dirname($outputPath);

        $fullContent = "# {$this->title}\n\n";
        $fullContent .= "> Generated on: " . date('Y-m-d H:i:s') . "\n\n---\n\n";

        foreach ($markdownFiles as $item) {
            $file = is_array($item) ? $item['file'] : $item;
            $name = is_array($item) ? ($item['name'] ?? pathinfo($file, PATHINFO_FILENAME)) : pathinfo($file, PATHINFO_FILENAME);

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            if (!empty($this->imagePathCorrection) || $baseDir) {
                $correction = $this->imagePathCorrection ?: $name;
                $content = preg_replace(
                    '/\((images\/.*?)\)/',
                    "(" . $correction . "/$1)",
                    $content
                );
            }

            $fullContent .= "## " . strtoupper($name) . "\n\n";
            $fullContent .= $content . "\n\n---\n\n";
        }

        file_put_contents($outputPath, $fullContent);

        return $outputPath;
    }

    public function compileWithTableOfContents(array $markdownFiles, string $outputPath, ?string $baseOutputDir = null): string {
        if (empty($markdownFiles)) {
            throw new \Exception("No markdown files provided");
        }

        $baseDir = $baseOutputDir ?? dirname($outputPath);
        $toc = [];
        $fullContent = "# {$this->title}\n\n";
        $fullContent .= "> Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        $fullContent .= "## Table of Contents\n\n";

        foreach ($markdownFiles as $index => $item) {
            $file = is_array($item) ? $item['file'] : $item;
            $name = is_array($item) ? ($item['name'] ?? pathinfo($file, PATHINFO_FILENAME)) : pathinfo($file, PATHINFO_FILENAME);
            
            $toc[] = "- [{$name}](#" . $this->slugify($name) . ")";
        }

        $fullContent .= implode("\n", $toc) . "\n\n---\n\n";

        foreach ($markdownFiles as $item) {
            $file = is_array($item) ? $item['file'] : $item;
            $name = is_array($item) ? ($item['name'] ?? pathinfo($file, PATHINFO_FILENAME)) : pathinfo($file, PATHINFO_FILENAME);

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            if (!empty($this->imagePathCorrection) || $baseDir) {
                $correction = $this->imagePathCorrection ?: $name;
                $content = preg_replace(
                    '/\((images\/.*?)\)/',
                    "(" . $correction . "/$1)",
                    $content
                );
            }

            $fullContent .= "## " . strtoupper($name) . " {#" . $this->slugify($name) . "}\n\n";
            $fullContent .= $content . "\n\n---\n\n";
        }

        file_put_contents($outputPath, $fullContent);

        return $outputPath;
    }

    private function slugify(string $text): string {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text;
    }

    public function setTitle(string $title): void {
        $this->title = $title;
    }

    public function setImagePathCorrection(string $correction): void {
        $this->imagePathCorrection = $correction;
    }
}
