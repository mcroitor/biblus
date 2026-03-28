<?php

namespace Test;

use PHPUnit\Framework\TestCase;

abstract class BaseWorkerTest extends TestCase {
    protected string $fixturesDir;
    protected string $outputDir;

    protected function setUp(): void {
        parent::setUp();
        $this->fixturesDir = dirname(__FILE__) . '/fixtures';
        $this->outputDir = dirname(__FILE__) . '/output';
        
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    protected function getOllamaClient(
        string $apiUrl = 'http://localhost:11434',
        string $modelName = 'qwen3.5:9b'
    ): \Core\Mc\Alpaca\LLMClient {
        if (getenv('OLLAMA_MODE') === 'real') {
            return new IntegrationOllamaClient($apiUrl, $modelName);
        }
        return new MockOllamaClient($apiUrl, $modelName);
    }

    protected function isMockMode(): bool {
        return getenv('OLLAMA_MODE') !== 'real';
    }

    protected function clearOutputDir(): void {
        $files = glob($this->outputDir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->removeDirectory($file);
            } else {
                unlink($file);
            }
        }
    }

    protected function removeDirectory(string $dir): void {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    protected function createTempDir(): string {
        $dir = $this->outputDir . '/temp_' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }
}
