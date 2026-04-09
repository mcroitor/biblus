<?php

namespace Test\Worker;

use Test\BaseWorkerTest;
use Test\MockOllamaClient;
use Worker\TranslateWorker;

class TranslateWorkerTest extends BaseWorkerTest {
    protected function setUp(): void {
        parent::setUp();
        $this->clearOutputDir();
    }

    public function testExecuteThrowsExceptionForMissingInputDirectory(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $worker = new TranslateWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            2,
            $client
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Input directory does not exist');

        $worker->Execute('/non/existent/input', $this->createTempDir(), 'Russian');
    }

    public function testExecuteBuildsMarkdownPromptAndWritesTranslatedFile(): void {
        $ollamaUrl = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        $modelName = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:9b';
        $client = $this->getOllamaClient($ollamaUrl, $modelName);

        $this->assertInstanceOf(MockOllamaClient::class, $client);
        /** @var MockOllamaClient $client */
        $client->setDefaultResponse(json_encode([
            'message' => ['content' => "# Заголовок\n\nПереведенный текст"]
        ]));

        $worker = new TranslateWorker(
            $ollamaUrl,
            $modelName,
            [],
            600,
            2,
            $client
        );

        $inputDir = $this->createTempDir();
        $outputDir = $this->createTempDir();

        $sourceMarkdown = "# Title\n\nParagraph with a [link](https://example.com).\n\n```php\necho 'Hello';\n```\n";
        file_put_contents($inputDir . DIRECTORY_SEPARATOR . 'page_001.md', $sourceMarkdown);

        $result = $worker->Execute($inputDir, $outputDir, 'Russian');

        $this->assertCount(1, $result);
        $this->assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'page_001.md');
        $this->assertSame(
            "# Заголовок\n\nПереведенный текст",
            file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'page_001.md')
        );

        $lastCall = $client->getLastCall();
        $this->assertNotNull($lastCall);
        $this->assertSame('api/chat', $lastCall['endpoint']);

        $prompt = $lastCall['data']['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('Translate the following Markdown document to Russian.', $prompt);
        $this->assertStringContainsString('Preserve valid Markdown structure exactly', $prompt);
        $this->assertStringContainsString('Return only the translated Markdown document.', $prompt);
        $this->assertStringContainsString('```php', $prompt);
        $this->assertStringContainsString('Markdown to translate:', $prompt);
    }
}
