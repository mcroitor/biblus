<?php

namespace Test\Worker;

use Test\BaseWorkerTest;
use Worker\ExplodePdfWorker;

class ExplodePdfWorkerTest extends BaseWorkerTest {
    protected function setUp(): void {
        parent::setUp();
        $this->clearOutputDir();
    }

    public function testWorkerCanBeInstantiated(): void {
        $worker = new ExplodePdfWorker();
        $this->assertInstanceOf(ExplodePdfWorker::class, $worker);
    }

    public function testWorkerWithPngFormat(): void {
        $worker = new ExplodePdfWorker(ExplodePdfWorker::PNG, 150);
        $this->assertInstanceOf(ExplodePdfWorker::class, $worker);
    }

    public function testWorkerWithJpegFormat(): void {
        $worker = new ExplodePdfWorker(ExplodePdfWorker::JPEG, 300);
        $this->assertInstanceOf(ExplodePdfWorker::class, $worker);
    }

    public function testConstantsAreDefined(): void {
        $this->assertEquals('jpeg', ExplodePdfWorker::JPEG);
        $this->assertEquals('png', ExplodePdfWorker::PNG);
        $this->assertEquals('tiff', ExplodePdfWorker::TIFF);
        $this->assertEquals(300, ExplodePdfWorker::DPI);
    }

    public function testExecuteThrowsExceptionForNonExistentPdf(): void {
        $worker = new ExplodePdfWorker();
        $outputDir = $this->createTempDir();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PDF file does not exist');

        $worker->Execute('/non/existent/file.pdf', $outputDir);
    }

    public function testExecuteThrowsExceptionForNonExistentOutputDir(): void {
        $worker = new ExplodePdfWorker();
        $pdfPath = $this->fixturesDir . '/sample.pdf';

        if (!file_exists($pdfPath)) {
            $this->markTestSkipped('Sample PDF fixture not found');
        }

        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension not loaded');
        }

        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            $this->markTestSkipped('Test behavior varies on Windows due to path handling');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot create output directory');

        $worker->Execute($pdfPath, '/non/existent/output/dir');
    }

    public function testExecuteWithRealPdfImagick(): void {
        $pdfPath = $this->fixturesDir . '/sample.pdf';
        
        if (!file_exists($pdfPath)) {
            $this->markTestSkipped('Sample PDF fixture not found');
        }

        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension not loaded');
        }

        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            $this->markTestSkipped('Ghostscript may not be available on Windows');
        }

        $worker = new ExplodePdfWorker(ExplodePdfWorker::PNG, 72);
        $outputDir = $this->createTempDir();

        $pages = $worker->Execute($pdfPath, $outputDir);

        $this->assertNotEmpty($pages);
        $this->assertGreaterThan(0, count($pages));
        
        foreach ($pages as $page) {
            $this->assertFileExists($page);
            $this->assertStringEndsWith('.png', $page);
        }
    }

    public function testExecuteWithRealPdfPdftoppm(): void {
        $pdfPath = $this->fixturesDir . '/sample.pdf';
        
        if (!file_exists($pdfPath)) {
            $this->markTestSkipped('Sample PDF fixture not found');
        }

        if (!is_executable('pdftoppm') && !file_exists('/usr/bin/pdftoppm')) {
            $this->markTestSkipped('pdftoppm not available');
        }

        $worker = new ExplodePdfWorker(ExplodePdfWorker::PNG, 72);
        $outputDir = $this->createTempDir();

        $pages = $worker->Execute($pdfPath, $outputDir);

        $this->assertNotEmpty($pages);
        
        foreach ($pages as $page) {
            $this->assertFileExists($page);
        }
    }
}
