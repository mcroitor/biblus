<?php

namespace Test\Worker;

use Test\BaseWorkerTest;
use Worker\FormatMarkdownWorker;

class FormatMarkdownWorkerTest extends BaseWorkerTest {
    protected function setUp(): void {
        parent::setUp();
        $this->clearOutputDir();
    }

    public function testWorkerCanBeInstantiated(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new FormatMarkdownWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );
        $this->assertInstanceOf(FormatMarkdownWorker::class, $worker);
    }

    public function testWorkerAcceptsCustomModelOptions(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new FormatMarkdownWorker(
            $ollamaUrl,
            $modelName,
            ['temperature' => 0.1, 'num_ctx' => 16384],
            600,
            $client
        );
        $this->assertInstanceOf(FormatMarkdownWorker::class, $worker);
    }

    public function testCheckModelExistsReturnsTrue(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        if (!$this->isMockMode()) {
            $worker = new FormatMarkdownWorker(
                $ollamaUrl,
                $modelName,
                [],
                600,
                $client
            );
            $this->assertTrue($worker->checkModelExists());
        } else {
            $this->markTestSkipped('Requires real Ollama server');
        }
    }

    public function testSetTemperatureUpdatesOptions(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new FormatMarkdownWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );
        $worker->setTemperature(0.3);
        $this->assertInstanceOf(FormatMarkdownWorker::class, $worker);
    }

    public function testExecuteThrowsExceptionForNonExistentPagesDir(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new FormatMarkdownWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );
        $outputDir = $this->createTempDir();
        $ocrDir = $this->createTempDir();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pages directory does not exist');

        $worker->Execute('/non/existent/pages', $ocrDir, [], $outputDir);
    }

    public function testExecuteThrowsExceptionForNonExistentOcrDir(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new FormatMarkdownWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );
        $pagesDir = $this->createTempDir();
        $outputDir = $this->createTempDir();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('OCR results directory does not exist');

        $worker->Execute($pagesDir, '/non/existent/ocr', [], $outputDir);
    }

    public function testFormatPageMarkdownReturnsContent(): void {
        if ($this->isMockMode()) {
            $this->markTestSkipped('Requires real Ollama server');
        }
        
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new FormatMarkdownWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );

        $testImage = $this->fixturesDir . '/test_page.png';
        if (!file_exists($testImage)) {
            $this->markTestSkipped('Test fixture not found');
        }

        $result = $worker->formatPageMarkdown($testImage, null, null);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testFormatPageMarkdownThrowsExceptionOnOllamaError(): void {
        if ($this->isMockMode()) {
            $this->markTestSkipped('Requires real Ollama server');
        }
        
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new FormatMarkdownWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );

        $testImage = $this->fixturesDir . '/test_page.png';
        if (!file_exists($testImage)) {
            $this->markTestSkipped('Test fixture not found');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('model');

        $worker->formatPageMarkdown($testImage, null, null);
    }

    public function testFindImageResultReturnsCorrectResult(): void {
        $imageResults = [
            ['page' => 'page_001', 'placeholders' => []],
            ['page' => 'page_002', 'placeholders' => [['box' => [], 'md' => '![img](path)']]],
        ];

        $result = $this->invokePrivateFindImageResult($imageResults, 'page_002');

        $this->assertNotNull($result);
        $this->assertEquals('page_002', $result['page']);
    }

    public function testFindImageResultReturnsNullForMissingPage(): void {
        $imageResults = [
            ['page' => 'page_001', 'placeholders' => []],
        ];

        $result = $this->invokePrivateFindImageResult($imageResults, 'page_999');

        $this->assertNull($result);
    }

    private function invokePrivateFindImageResult(array $imageResults, string $pageName): ?array {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new FormatMarkdownWorker($ollamaUrl, $modelName, [], 600, $client);
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('findImageResult');
        $method->setAccessible(true);
        return $method->invoke($worker, $imageResults, $pageName);
    }
}
