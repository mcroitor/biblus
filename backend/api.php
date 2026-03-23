<?php

require_once __DIR__ . '/config.php';

use Core\Mc\Filesystem\Manager as FSManager;
use Core\Mc\Filesystem\Path as FSPath;
use Core\Mc\Logger;
use Core\Mc\Route;
use Core\Mc\Router;

// CORS for asynchronous requests from frontend
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$logger = Logger::Stderr();

#[Route('/upload', ['POST'])]
function uploadHandler() {
    global $logger;
    try {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $logger->Error('No file uploaded or upload error');
            return Router::json(['error' => 'No file uploaded or upload error'], 400);
        }

        $uploadDir = __DIR__ . '/uploads';
        FSManager::MakeDir($uploadDir);

        $filename = basename($_FILES['file']['name']);
        $uniqueName = uniqid('pdf_', true) . '_' . $filename;
        $targetPath = (new FSPath([$uploadDir, $uniqueName]))->__toString();

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            $logger->Error('Failed to move uploaded file');
            return Router::json(['error' => 'Failed to move uploaded file'], 500);
        }

        $logger->Info('File uploaded: ' . $targetPath);
        return Router::json(['success' => true, 'path' => $uniqueName], 200);
    } catch (\Throwable $e) {
        $logger->Error('Exception: ' . $e->getMessage());
        return Router::json(['error' => 'Internal server error'], 500);
    }
}

Router::init();
echo Router::run();
