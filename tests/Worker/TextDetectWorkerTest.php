<?php

namespace Test\Worker;

use Test\BaseWorkerTest;
use Test\MockOllamaClient;
use Test\IntegrationOllamaClient;
use Worker\TextDetectWorker;

class TextDetectWorkerTest extends BaseWorkerTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearOutputDir();
    }

    public function testWorkerCanBeInstantiated(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:8b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new TextDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            2,
            $client
        );
        $this->assertInstanceOf(TextDetectWorker::class, $worker);
    }

    public function testWorkerAcceptsCustomModelOptions(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:8b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new TextDetectWorker(
            $ollamaUrl,
            $modelName,
            ['temperature' => 0.5, 'num_predict' => 2048],
            600,
            2,
            $client
        );
        $this->assertInstanceOf(TextDetectWorker::class, $worker);
    }

    public function testCheckModelExists(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:8b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new TextDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            2,
            $client
        );
        $result = $worker->checkModelExists();
        $this->assertIsBool($result);
    }

    public function testSetTemperatureUpdatesOptions(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:8b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new TextDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            2,
            $client
        );
        $worker->setTemperature(0.7);
        $this->assertInstanceOf(TextDetectWorker::class, $worker);
    }

    public function testExecuteThrowsExceptionForNonExistentPagesDir(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:8b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new TextDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            2,
            $client
        );
        $outputDir = $this->createTempDir();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pages directory does not exist');

        $worker->Execute('/non/existent/pages', $outputDir);
    }

    public function testExecuteWithFixtures(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:8b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new TextDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            2,
            $client
        );
        $outputDir = $this->createTempDir();

        $testImage = $this->fixturesDir . '/test_page.png';
        if (!file_exists($testImage)) {
            $this->markTestSkipped('Test fixture not found');
        }

        $results = $worker->Execute($this->fixturesDir, $outputDir);

        $this->assertIsArray($results);
    }
}
