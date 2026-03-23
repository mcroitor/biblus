<?php

namespace Core\Mc\Filesystem;

use Core\Mc\Filesystem\Manager;

class Sausage {
    public static function Path(string $path): string {
        $path = str_replace(".", DIRECTORY_SEPARATOR, $path);

        return Manager::Normalize($path);
    }

    public static function From(string $path): string {
        $path = Manager::Normalize($path);
        return str_replace(["/", "\\"], ".", $path);
    }
}