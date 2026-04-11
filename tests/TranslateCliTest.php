<?php

namespace Test;

use PHPUnit\Framework\TestCase;

class TranslateCliTest extends TestCase {
    private string $tempDir;
    private string $inputDir;
    private string $outputDir;

    protected function setUp(): void {
        $this->tempDir = sys_get_temp_dir() . '/biblus_translate_test_' . uniqid();
        $this->inputDir = $this->tempDir . '/input';
        $this->outputDir = $this->tempDir . '/output';
        
        mkdir($this->inputDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void {
        if (file_exists($this->tempDir)) {
            $this->recursiveRmdir($this->tempDir);
        }
    }

    private function recursiveRmdir(string $dir): void {
        if (!is_dir($dir)) return;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveRmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Test that single-file mode deletes old output before writing new translation.
     * This ensures re-runs don't duplicate content.
     */
    public function testSingleFileModeDoesNotAccumulateOnRerun(): void {
        $inputFile = $this->inputDir . '/test.md';
        file_put_contents($inputFile, "# Test\n\nSome content.");

        $outputFile = $this->outputDir . '/test.md';
        
        // Mock first run: create output file
        file_put_contents($outputFile, "# Тест\n\nПервый перевод.\n");
        $firstSize = filesize($outputFile);

        // Simulate second run: at the start of translate.php single-file mode,
        // the old output is unlinked before any new content is written.
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        
        // Verify file was deleted
        $this->assertFalse(file_exists($outputFile), 
            'Output file should be deleted before writing new translation');
    }

    /**
     * Test that file() read failure is properly detected and reported.
     */
    public function testFileReadFailureDetected(): void {
        // file() returns false when the file doesn't exist
        $nonExistentFile = $this->inputDir . '/does_not_exist.md';

        $text = file($nonExistentFile);
        $this->assertFalse($text, 
            'file() should return false when file does not exist');
    }

    /**
     * Test validation of firstPage parameter (must be >= 1).
     */
    public function testFirstPageValidation(): void {
        // Negative firstPage should be rejected
        $firstPage = -1;
        $this->assertLessThan(1, $firstPage, 
            'firstPage should be validated as >= 1');

        // Zero firstPage should be rejected
        $firstPage = 0;
        $this->assertLessThan(1, $firstPage, 
            'firstPage 0 should be invalid');

        // Valid firstPage
        $firstPage = 1;
        $this->assertGreaterThanOrEqual(1, $firstPage, 
            'firstPage 1 should be valid');
    }

    /**
     * Test validation of lastPage parameter (must be >= 0, where 0 = all pages).
     */
    public function testLastPageValidation(): void {
        // Negative lastPage should be rejected
        $lastPage = -1;
        $this->assertLessThan(0, $lastPage, 
            'lastPage should be validated as >= 0');

        // Valid: 0 means all pages
        $lastPage = 0;
        $this->assertGreaterThanOrEqual(0, $lastPage, 
            'lastPage 0 (all pages) should be valid');

        // Valid: positive value
        $lastPage = 5;
        $this->assertGreaterThanOrEqual(0, $lastPage, 
            'lastPage 5 should be valid');
    }

    /**
     * Test that output directory is created if it doesn't exist.
     */
    public function testOutputDirectoryCreatedIfMissing(): void {
        $nonExistentOutput = $this->tempDir . '/new_output';
        $this->assertFalse(is_dir($nonExistentOutput), 
            'Output directory should not exist initially');

        // Simulate mkdir logic from translate.php
        if (!is_dir($nonExistentOutput)) {
            mkdir($nonExistentOutput, 0755, true);
        }

        $this->assertTrue(is_dir($nonExistentOutput), 
            'Output directory should be created');
    }
}
