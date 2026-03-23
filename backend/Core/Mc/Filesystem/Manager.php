<?php

namespace Core\Mc\Filesystem;

class Manager {

    public const US = "/";
    public const WS = "\\";

    public static function Normalize(string $path, string $separator = DIRECTORY_SEPARATOR): string
    {
        $path = str_replace([self::US, self::WS], $separator, $path);
        while(strpos($path, $separator . $separator) !== false) {
            $path = str_replace($separator . $separator, $separator, $path);
        }
        return $path;
    }

    public static function ToUnix(string $path): string
    {
        return self::Normalize($path, self::US);
    }

    public static function ToWindows(string $path): string
    {
        return self::Normalize($path, self::WS);
    }

    public static function Root(string $path, string $separator = DIRECTORY_SEPARATOR): string
    {
        $path = self::Normalize($path, $separator);
        $chunks = explode($separator, $path);
        $last = array_pop($chunks);
        return implode($separator, $chunks);
    }

    public static function Children(string $path, string $separator = DIRECTORY_SEPARATOR): string
    {
        $path = self::Normalize($path, $separator);
        $chunks = explode($separator, $path);
        $last = array_pop($chunks);
        return $last;
    }

    public static function FileName(string $path, string $separator = DIRECTORY_SEPARATOR): string
    {
        $path = self::Normalize($path, $separator);
        $chunks = explode($separator, $path);
        return array_pop($chunks);
    }

    public static function Extension(string $path, string $separator = DIRECTORY_SEPARATOR): string
    {
        $path = self::Normalize($path, $separator);
        $chunks = explode($separator, $path);
        $last = array_pop($chunks);
        return pathinfo($last, PATHINFO_EXTENSION);
    }

    public static function Implode(array $chunks, string $separator = DIRECTORY_SEPARATOR): string
    {
        $result = [];
        
        foreach ($chunks as $chunk) {
            $chunk = self::Normalize($chunk, $separator);
            $result = array_merge($result, explode($separator, $chunk));
        }
        return implode($separator, $result);
    }

    public static function Unlink(string $path): void {
        if (file_exists($path)) {
            unlink($path);
        }
        if(is_dir($path)) {
            $files = array_diff(scandir($path), ['.', '..']);
            foreach ($files as $file) {
                self::Unlink($path . DIRECTORY_SEPARATOR . $file);
            }
            rmdir($path);
        }
    }

    public static function MakeDir(string $path, int $permissions = 0777) {
        if (!file_exists($path)) {
            mkdir($path, $permissions, true);
        }
    }
}