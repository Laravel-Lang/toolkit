<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use LaravelLang\Filesystem\Filesystem as LangFs;
use LaravelLang\Filesystem\Processors\JsonProcessor;
use LaravelLang\Filesystem\Processors\PhpProcessor;
use LaravelLang\LocaleList\Locale;
use RuntimeException;
use Throwable;

abstract class SyncCommand
{
    protected int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    protected LangFs $langFs;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem(),
    ) {
        $this->langFs = new LangFs(
            filesystem   : $this->filesystem,
            jsonProcessor: new JsonProcessor($this->filesystem),
            phpProcessor : new PhpProcessor($this->filesystem),
        );
    }

    protected function loadSource(string $path): array
    {
        $result = [];

        foreach ($this->filesystem->allFiles($path . '/source') as $file) {
            if (str_starts_with($file->getFilename(), '_')) {
                continue;
            }

            $source = $file->getPathname();

            try {
                $content = Arr::dot(
                    $this->langFs->read($source)
                );
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Unable to read translation source [$source]: " . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            $content = array_filter($content, static fn (mixed $key): bool => $key !== '');

            if (! $content) {
                throw new RuntimeException("The translation source [$source] contains no keys.");
            }

            $result = array_merge($result, $content);
        }

        if (! $result) {
            throw new RuntimeException("No translation sources found in [$path].");
        }

        return array_values(array_unique($result));
    }

    protected function localeDirectories(string $path): array
    {
        return array_filter(
            $this->filesystem->directories($path),
            static fn (string $directory): bool => Locale::tryFrom(basename($directory)) !== null,
        );
    }

    protected function store(string $path, array $content): void
    {
        $this->langFs->storeArray($path, $content);
    }
}
