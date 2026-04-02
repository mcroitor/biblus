<?php

namespace Worker;

class CompileDocumentWorker
{
    private string $title;
    private string $imagePathCorrection;

    public function __construct(string $title = "Document", string $imagePathCorrection = '')
    {
        $this->title = $title;
        $this->imagePathCorrection = $imagePathCorrection;
    }

    public function Execute(array $markdownFiles, string $outputPath, ?string $baseOutputDir = null): string
    {
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

            $content = $this->cleanMarkdown($content);

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

    private function cleanMarkdown(string $markdown): string
    {

        // 1. Normalize line breaks and trim trailing whitespace
        $clean = preg_replace("/\r\n|\r/", "\n", $markdown);
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

    public function compileWithTableOfContents(array $markdownFiles, string $outputPath, ?string $baseOutputDir = null): string
    {
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
                    "({$correction}/$1)",
                    $content
                );
            }

            $fullContent .= "## " . strtoupper($name) . " {#" . $this->slugify($name) . "}\n\n";
            $fullContent .= $content . "\n\n---\n\n";
        }

        file_put_contents($outputPath, $fullContent);

        return $outputPath;
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setImagePathCorrection(string $correction): void
    {
        $this->imagePathCorrection = $correction;
    }
}
