<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use LaravelLang\Exceptions\TranslationSourceException;
use LaravelLang\Filesystem\Filesystem as LangFs;
use LaravelLang\Filesystem\Processors\JsonProcessor;
use LaravelLang\Filesystem\Processors\PhpProcessor;
use LaravelLang\LocaleList\Locale;
use Throwable;

abstract class SyncCommand
{
    protected int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    protected LangFs $langFs;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
    ) {
        $this->langFs = new LangFs(
            filesystem   : $this->filesystem,
            jsonProcessor: new JsonProcessor($this->filesystem),
            phpProcessor : new PhpProcessor($this->filesystem),
        );
    }

    /** @return non-empty-list<int|string> */
    protected function loadSource(string $path): array
    {
        $result = [];

        foreach ($this->filesystem->allFiles($path . '/source') as $file) {
            if (str_starts_with($file->getFilename(), '_')) {
                continue;
            }

            $source = $file->getPathname();

            try {
                $values = Arr::dot(
                    $this->langFs->read($source)
                );
            } catch (Throwable $exception) {
                throw TranslationSourceException::unableToRead($source, $exception);
            }

            $content = [];

            foreach ($values as $key => $value) {
                if ($value === '' || $value === []) {
                    continue;
                }

                if (! is_int($value) && ! is_string($value)) {
                    throw TranslationSourceException::invalidKey($source);
                }

                $content[$key] = $value;
            }

            if ($content === []) {
                throw TranslationSourceException::missingKeys($source);
            }

            $result = array_merge($result, $content);
        }

        if ($result === []) {
            throw TranslationSourceException::sourcesNotFound($path);
        }

        return array_values(array_unique($result));
    }

    /** @return array<int, string> */
    protected function localeDirectories(string $path): array
    {
        return array_filter(
            $this->filesystem->directories($path),
            static fn (string $directory): bool => Locale::tryFrom(basename($directory)) !== null,
        );
    }

    /** @param array<array-key, mixed> $content */
    protected function store(string $path, array $content): void
    {
        $this->langFs->storeArray($path, $content);
    }
}
