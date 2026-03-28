<?php

namespace Test\Worker;

use Test\BaseWorkerTest;
use Worker\CompileDocumentWorker;

class CompileDocumentWorkerTest extends BaseWorkerTest {
    protected function setUp(): void {
        parent::setUp();
        $this->clearOutputDir();
    }

    public function testWorkerCanBeInstantiated(): void {
        $worker = new CompileDocumentWorker();
        $this->assertInstanceOf(CompileDocumentWorker::class, $worker);
    }

    public function testWorkerWithCustomTitle(): void {
        $worker = new CompileDocumentWorker('My Custom Book');
        $this->assertInstanceOf(CompileDocumentWorker::class, $worker);
    }

    public function testWorkerWithCustomImagePathCorrection(): void {
        $worker = new CompileDocumentWorker('Book', 'corrected/path');
        $this->assertInstanceOf(CompileDocumentWorker::class, $worker);
    }

    public function testSetTitle(): void {
        $worker = new CompileDocumentWorker();
        $worker->setTitle('New Title');
        $this->assertInstanceOf(CompileDocumentWorker::class, $worker);
    }

    public function testSetImagePathCorrection(): void {
        $worker = new CompileDocumentWorker();
        $worker->setImagePathCorrection('new/path');
        $this->assertInstanceOf(CompileDocumentWorker::class, $worker);
    }

    public function testExecuteThrowsExceptionForEmptyFiles(): void {
        $worker = new CompileDocumentWorker();
        $outputPath = $this->outputDir . '/empty_book.md';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No markdown files provided');

        $worker->Execute([], $outputPath);
    }

    public function testExecuteCreatesValidDocument(): void {
        $worker = new CompileDocumentWorker('Test Book');
        
        $pagesDir = $this->createTempDir();
        $page1 = $pagesDir . '/page_001.md';
        $page2 = $pagesDir . '/page_002.md';
        
        file_put_contents($page1, "# Page 1\n\nContent of page 1.");
        file_put_contents($page2, "# Page 2\n\nContent of page 2.");

        $outputPath = $this->outputDir . '/book.md';

        $result = $worker->Execute(
            [['file' => $page1, 'name' => 'page_001'], ['file' => $page2, 'name' => 'page_002']],
            $outputPath
        );

        $this->assertEquals($outputPath, $result);
        $this->assertFileExists($outputPath);
        
        $content = file_get_contents($outputPath);
        $this->assertStringContainsString('# Test Book', $content);
        $this->assertStringContainsString('PAGE_001', $content);
        $this->assertStringContainsString('PAGE_002', $content);
    }

    public function testExecuteWithSimpleArray(): void {
        $worker = new CompileDocumentWorker();

        $pagesDir = $this->createTempDir();
        $page1 = $pagesDir . '/chapter_1.md';
        $page2 = $pagesDir . '/chapter_2.md';
        
        file_put_contents($page1, "# Chapter 1\n\nText here.");
        file_put_contents($page2, "# Chapter 2\n\nMore text.");

        $outputPath = $this->outputDir . '/book.md';

        $result = $worker->Execute(
            [$page1, $page2],
            $outputPath
        );

        $this->assertEquals($outputPath, $result);
        $this->assertFileExists($outputPath);
    }

    public function testImagePathCorrection(): void {
        $worker = new CompileDocumentWorker('Book', 'corrected_base');
        
        $pagesDir = $this->createTempDir();
        $pageDir = $pagesDir . '/page_001';
        mkdir($pageDir);
        mkdir($pageDir . '/images');
        
        $pageFile = $pageDir . '/index.md';
        $imgFile = $pageDir . '/images/diagram.png';
        file_put_contents($pageFile, "See diagram: ![](images/diagram.png)");
        touch($imgFile);

        $outputPath = $this->outputDir . '/book.md';

        $worker->Execute([['file' => $pageFile, 'dir' => 'page_001']], $outputPath, $pagesDir);

        $content = file_get_contents($outputPath);
        $this->assertStringContainsString('corrected_base/images/diagram.png', $content);
    }

    public function testCompileWithTableOfContents(): void {
        $worker = new CompileDocumentWorker('Book with TOC');
        
        $pagesDir = $this->createTempDir();
        $page1 = $pagesDir . '/intro.md';
        $page2 = $pagesDir . '/chapter1.md';
        
        file_put_contents($page1, "# Introduction\n\nWelcome.");
        file_put_contents($page2, "# Chapter 1\n\nFirst chapter content.");

        $outputPath = $this->outputDir . '/book_with_toc.md';

        $result = $worker->compileWithTableOfContents(
            [['file' => $page1, 'name' => 'intro'], ['file' => $page2, 'name' => 'chapter1']],
            $outputPath
        );

        $this->assertEquals($outputPath, $result);
        $this->assertFileExists($outputPath);
        
        $content = file_get_contents($outputPath);
        $this->assertStringContainsString('## Table of Contents', $content);
        $this->assertStringContainsString('[intro](#intro)', $content);
        $this->assertStringContainsString('[chapter1](#chapter1)', $content);
    }

    public function testSlugify(): void {
        $worker = new CompileDocumentWorker();

        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('slugify');
        $method->setAccessible(true);

        $this->assertEquals('hello-world', $method->invoke($worker, 'Hello World'));
        $this->assertEquals('hello-world-123', $method->invoke($worker, 'Hello World 123'));
        $this->assertEquals('special-chars', $method->invoke($worker, 'Special Chars!@#$'));
        $this->assertEquals('trimmed', $method->invoke($worker, '  trimmed  '));
    }

    public function testExecuteSkipsMissingFiles(): void {
        $worker = new CompileDocumentWorker();
        
        $pagesDir = $this->createTempDir();
        $existingPage = $pagesDir . '/existing.md';
        file_put_contents($existingPage, "# Existing\n\nContent.");
        
        $outputPath = $this->outputDir . '/book.md';

        $result = $worker->Execute(
            [
                ['file' => $existingPage, 'name' => 'existing'],
                ['file' => '/non/existent/file.md', 'name' => 'missing']
            ],
            $outputPath
        );

        $this->assertEquals($outputPath, $result);
        $this->assertFileExists($outputPath);
        
        $content = file_get_contents($outputPath);
        $this->assertStringContainsString('EXISTING', $content);
        $this->assertStringNotContainsString('MISSING', $content);
    }

    public function testExecuteAddsGeneratedDate(): void {
        $worker = new CompileDocumentWorker('Dated Book');
        
        $pagesDir = $this->createTempDir();
        $page = $pagesDir . '/page.md';
        file_put_contents($page, "# Page\n\nContent.");

        $outputPath = $this->outputDir . '/book.md';

        $worker->Execute([['file' => $page, 'name' => 'page']], $outputPath);

        $content = file_get_contents($outputPath);
        $this->assertStringContainsString('> Generated on:', $content);
    }
}
