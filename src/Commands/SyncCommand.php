<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

use function array_filter;

abstract class SyncCommand
{
    protected int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem(),
    ) {}

    protected function loadSource(string $path): array
    {
        $result = [];

        foreach ($this->filesystem->allFiles($path . '/source') as $file) {
            if (str_starts_with($file->getFilename(), '_')) {
                continue;
            }

            $content = mb_strtolower($file->getExtension()) === 'php'
                ? $this->loadPhp($file->getRealPath())
                : $this->loadJson($file->getRealPath());

            $result = array_merge($result, $content);
        }

        return array_values(array_unique(array_filter($result)));
    }

    protected function loadFile(string $path, string $extension): array
    {
        if (mb_strtolower($extension) === 'php') {
            return require $path;
        }

        return $this->filesystem->json($path);
    }

    protected function loadPhp(string $path): array
    {
        return (new Collection(require $path))
            ->dot()
            ->values()
            ->all();
    }

    protected function loadJson(string $path): array
    {
        return array_keys(
            $this->filesystem->json($path)
        );
    }

    protected function store(string $path, array $content): void
    {
        $this->filesystem->put($path, json_encode($content, $this->flags));
    }
}
