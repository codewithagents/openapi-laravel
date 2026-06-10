<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Writes generated files to a target directory, creating it if necessary.
 * Optionally prunes previously generated *.php files first so a removed schema
 * does not leave a stale class behind.
 */
final class FileWriter
{
    public function __construct(
        private readonly string $directory,
    ) {}

    /**
     * @param  array<string, GeneratedFile>  $files
     * @return list<string> absolute paths written, sorted
     */
    public function write(array $files, bool $prune = false): array
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        if ($prune) {
            $this->prune();
        }

        $target = rtrim($this->directory, '/');
        $written = [];

        foreach ($files as $file) {
            $path = $target.'/'.$file->filename();
            file_put_contents($path, $file->code);
            $written[] = $path;
        }

        sort($written);

        return $written;
    }

    private function prune(): void
    {
        $existing = glob(rtrim($this->directory, '/').'/*.php') ?: [];

        foreach ($existing as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
