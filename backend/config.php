<?php


// autoload classes
spl_autoload_register(function ($class_name) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class_name) . '.php';
    if (file_exists($file)) {
        include_once $file;
    }
});

class Config
{
    public static $ollamaServer;
    public static $ollamaOcrModel;
    public static $ollamaImgModel;
    public static $ollamaModelOptions = [
        'temperature' => 0.2,
        'max_tokens' => 2048,
        'num_predict' => 4096,
        'num_ctx' => 4096,
    ];
    public static $imageFormat;
    public static $imageDpi;
    public static $tempDir = __DIR__ . '/../temp';
    public static $timeout = 300; // seconds

    public static function Init()
    {
        self::$ollamaServer = getenv('OLLAMA_SERVER') ?: 'http://localhost:11434';
        self::$ollamaOcrModel = getenv('OLLAMA_OCR_MODEL') ?: 'qwen3.5:8b';
        self::$ollamaImgModel = getenv('OLLAMA_IMG_MODEL') ?: 'qwen3.5:32b';
        self::$imageFormat = getenv('IMAGE_FORMAT') ?: 'png';
        self::$imageDpi = getenv('IMAGE_DPI') ?: 300;
    }
}

Config::Init();
