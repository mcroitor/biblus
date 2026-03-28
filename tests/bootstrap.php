<?php

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/Core/Mc/Logger.php';
require_once __DIR__ . '/../backend/Core/Arguments.php';
require_once __DIR__ . '/../backend/Core/Mc/Alpaca/LLMClient.php';
require_once __DIR__ . '/../backend/Core/Mc/Alpaca/OllamaResponse.php';
require_once __DIR__ . '/../backend/Core/Mc/Alpaca/OllamaClient.php';
require_once __DIR__ . '/../backend/Core/Mc/Http.php';
require_once __DIR__ . '/../backend/Worker/ExplodePdfWorker.php';
require_once __DIR__ . '/../backend/Worker/TextDetectWorker.php';
require_once __DIR__ . '/../backend/Worker/ImageDetectWorker.php';
require_once __DIR__ . '/../backend/Worker/ValidateDataWorker.php';
require_once __DIR__ . '/../backend/Worker/FormatMarkdownWorker.php';
require_once __DIR__ . '/../backend/Worker/CompileDocumentWorker.php';

define('TESTS_FIXTURES_DIR', __DIR__ . '/fixtures');
define('TESTS_OUTPUT_DIR', __DIR__ . '/output');

if (!is_dir(TESTS_OUTPUT_DIR)) {
    mkdir(TESTS_OUTPUT_DIR, 0755, true);
}
