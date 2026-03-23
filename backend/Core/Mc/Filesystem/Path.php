<?php

namespace Core\Mc\Filesystem;

use Core\Mc\Filesystem\Manager;

class Path {

    private $path;

    public function __construct(string | array $path)
    {
        $p = $path;
        if (is_array($path)) {
            $p = Manager::Implode($path);
        }
        $this->path = Manager::Normalize($p);
    }

    public function Filename(): string {
        return Manager::Children($this->path);
    }

    public function Extension(): string {
        $filename = $this->Filename();
        $chunks = explode(".", $filename);
        return end($chunks);
    }

    public function Parent(): string {
        return Manager::Root($this->path);
    }

    public function __toString(): string
    {
        return $this->path;
    }

    public function Exists() : bool {
        return file_exists($this->path);
    }

    public function IsFile() : bool {
        return is_file($this->path);
    }

    public function IsDir() : bool {
        return is_dir($this->path);
    }

    public function IsLink() : bool {
        return is_link($this->path);
    }

    public function IsReadable() : bool {
        return is_readable($this->path);
    }

    public function IsWritable() : bool {
        return is_writable($this->path);
    }

    public function IsExecutable() : bool {
        return is_executable($this->path);
    }

    public function Size() : int {
        return filesize($this->path);
    }

    public function Join(string | array $path) : Path {
        $p = $path;
        if (is_array($path)) {
            $p = Manager::Implode($path);
        }

        return new Path([$this->path, $p]);
    }
}