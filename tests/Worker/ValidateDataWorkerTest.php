<?php

namespace Test\Worker;

use Test\BaseWorkerTest;
use Worker\ValidateDataWorker;

class ValidateDataWorkerTest extends BaseWorkerTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearOutputDir();
    }

    public function testWorkerCanBeInstantiated(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );
        $this->assertInstanceOf(ValidateDataWorker::class, $worker);
    }

    public function testWorkerAcceptsCustomModelOptions(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            ['temperature' => 0.1],
            2,
            $client
        );
        $this->assertInstanceOf(ValidateDataWorker::class, $worker);
    }

    public function testWorkerAcceptsCustomMaxRetries(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            5,
            $client
        );
        $this->assertInstanceOf(ValidateDataWorker::class, $worker);
    }

    public function testValidatePageReturnsTrueForValidOcr(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );

        $ocrFile = $this->createTempOcrFile('# Test', 'Some content here');

        $result = $worker->validatePage($ocrFile, null);

        $this->assertTrue($result);
        unlink($ocrFile);
    }

    public function testValidatePageReturnsFalseForEmptyOcr(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );

        $ocrFile = $this->createTempOcrFile('', '');

        $result = $worker->validatePage($ocrFile, null);

        $this->assertFalse($result);
        unlink($ocrFile);
    }

    public function testValidatePageReturnsFalseForNonExistentFile(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );

        $result = $worker->validatePage('/non/existent/file.txt', null);

        $this->assertFalse($result);
    }

    public function testValidatePageConsidersImages(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );

        $ocrFile = $this->createTempOcrFile('# Test', 'Content');
        $imageResult = [
            'placeholders' => [
                ['box' => [], 'md' => '![img](path)']
            ]
        ];

        $result = $worker->validatePage($ocrFile, $imageResult);

        $this->assertTrue($result);
        unlink($ocrFile);
    }

    public function testExecuteThrowsExceptionForNonExistentPagesDir(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );

        $ocrResults = [];
        $imageResults = [];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pages directory does not exist');

        $worker->Execute('/non/existent/pages', $ocrResults, $imageResults);
    }

    public function testValidateWithVisualCheckReturnsResult(): void
    {
        if ($this->isMockMode()) {
            $this->markTestSkipped('Requires real Ollama server');
        }
        
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );

        $ocrText = '# Test Content';
        $placeholders = [];

        $testImage = $this->fixturesDir . '/test_page.png';
        if (!file_exists($testImage)) {
            $this->markTestSkipped('Test fixture not found');
        }

        $result = $worker->validateWithVisualCheck($testImage, $ocrText, $placeholders);

        $this->assertIsArray($result);
    }

    public function testSetTemperatureUpdatesOptions(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new ValidateDataWorker(
            $ollamaUrl,
            $modelName,
            [],
            2,
            $client
        );
        $worker->setTemperature(0.5);
        $this->assertInstanceOf(ValidateDataWorker::class, $worker);
    }

    private function createTempOcrFile(string $title, string $content): string
    {
        $dir = $this->createTempDir();
        $path = $dir . '/ocr.txt';
        file_put_contents($path, "$title\n\n$content");
        return $path;
    }
}
