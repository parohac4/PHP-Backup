<?php

class BackupEngine {
    protected $config;
    protected $sourceDir;

    public function __construct(array $config, string $sourceDir) {
        $this->config = $config;
        $this->sourceDir = realpath($sourceDir);
    }

    public function getAllFiles(): array {
        $logFile = __DIR__ . '/debug.log';
        file_put_contents($logFile, "[" . date('c') . "] Skenuji: {$this->sourceDir}\n", FILE_APPEND);

        if (!is_dir($this->sourceDir)) {
            file_put_contents($logFile, "[" . date('c') . "] Zdrojová složka neexistuje nebo není čitelná\n", FILE_APPEND);
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceDir, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile()) {
                if (!$this->isExcluded($path)) {
                    $files[] = $path;
                    if (count($files) <= 10) {
                        file_put_contents($logFile, "[" . date('c') . "] + " . $path . "\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents($logFile, "[" . date('c') . "] - VYLOUČENO: $path\n", FILE_APPEND);
                }
            }
        }

        file_put_contents($logFile, "[" . date('c') . "] Celkem nalezeno: " . count($files) . " souborů\n", FILE_APPEND);
        return $files;
    }

    protected function isExcluded(string $path): bool {
        foreach ($this->config['excludePatterns'] as $pattern) {
            if (fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }
}
