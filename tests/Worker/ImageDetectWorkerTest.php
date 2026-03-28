<?php

namespace Test\Worker;

use Test\BaseWorkerTest;
use Worker\ImageDetectWorker;

class ImageDetectWorkerTest extends BaseWorkerTest
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
        $worker = new ImageDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );
        $this->assertInstanceOf(ImageDetectWorker::class, $worker);
    }

    public function testWorkerAcceptsCustomModelOptions(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ImageDetectWorker(
            $ollamaUrl,
            $modelName,
            ['temperature' => 0.3],
            600,
            $client
        );
        $this->assertInstanceOf(ImageDetectWorker::class, $worker);
    }

    public function testCheckModelExistsReturnsTrue(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        if (!$this->isMockMode()) {
            $worker = new ImageDetectWorker(
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

    public function testSetTemperatureUpdatesOptions(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ImageDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );
        $worker->setTemperature(0.5);
        $this->assertInstanceOf(ImageDetectWorker::class, $worker);
    }

    public function testExecuteThrowsExceptionForNonExistentPagesDir(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ImageDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );
        $outputDir = $this->createTempDir();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pages directory does not exist');

        $worker->Execute('/non/existent/pages', $outputDir);
    }

    public function testDetectVisualsReturnsValidBboxes(): void
    {
        if ($this->isMockMode()) {
            $this->markTestSkipped('Requires real Ollama server');
        }
        
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new ImageDetectWorker(
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

        $visuals = $worker->detectVisuals($testImage);

        $this->assertIsArray($visuals);
    }

    public function testDetectVisualsReturnsEmptyOnInvalidResponse(): void
    {
        if ($this->isMockMode()) {
            $this->markTestSkipped('Requires real Ollama server');
        }
        
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new ImageDetectWorker(
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

        $visuals = $worker->detectVisuals($testImage);

        $this->assertIsArray($visuals);
    }

    public function testDetectVisualsThrowsExceptionOnOllamaError(): void
    {
        if ($this->isMockMode()) {
            $this->markTestSkipped('Requires real Ollama server');
        }
        
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new ImageDetectWorker(
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

        $worker->detectVisuals($testImage);
    }

    public function testCropVisualsCalculatesCoordsCorrectly(): void
    {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);
        $worker = new ImageDetectWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            $client
        );

        $testImage = $this->createTestImage(1000, 1000);
        $outputDir = $this->createTempDir();

        $visualsInfo = [
            [
                'box' => ['ymin' => 100, 'xmin' => 100, 'ymax' => 500, 'xmax' => 500],
                'label' => 'test-image'
            ]
        ];

        $placeholders = $worker->cropVisuals($testImage, $visualsInfo, $outputDir, 'images');

        $this->assertCount(1, $placeholders);
        $this->assertEquals('test-image', $placeholders[0]['desc']);
        $this->assertFileExists($outputDir . '/img_1.png');

        unlink($testImage);
    }

    private function createTestImage(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);

        $path = $this->outputDir . '/test_image_' . uniqid() . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
