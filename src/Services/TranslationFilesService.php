<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Services;

use Illuminate\Filesystem\Filesystem;
use LaravelLang\Exceptions\TranslationFileException;
use Throwable;

class TranslationFilesService
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
    ) {}

    /** @return array<array-key, scalar|array<array-key, mixed>|null> */
    public function load(string $path): array
    {
        try {
            $content = $this->filesystem->get($path);

            $values = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'json'  => json_decode($content, true, 512, JSON_THROW_ON_ERROR),
                default => throw TranslationFileException::unsupportedMergeFormat(),
            };

            if (! is_array($values)) {
                throw TranslationFileException::invalidRoot();
            }

            return array_filter(
                $values,
                static fn (mixed $value): bool => is_scalar($value) || is_array($value) || $value === null,
            );
        } catch (Throwable $exception) {
            throw TranslationFileException::unableToRead($path, $exception);
        }
    }

    /** @param array<array-key, mixed> $values */
    public function store(string $path, array $values): void
    {
        try {
            $values = $this->sort($values);

            $content = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'json' => json_encode(
                    $values,
                    JSON_THROW_ON_ERROR
                        | JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_PRESERVE_ZERO_FRACTION
                ) . PHP_EOL,
                default => throw TranslationFileException::unsupportedMergeFormat(),
            };

            if ($this->filesystem->put($path, $content) === false) {
                throw TranslationFileException::writeFailed();
            }
        } catch (Throwable $exception) {
            throw TranslationFileException::unableToWrite($path, $exception);
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, scalar|array<array-key, mixed>|null>
     */
    protected function sort(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sort($value);
            } elseif ((! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))) {
                throw TranslationFileException::invalidValues();
            }
        }

        if (! array_is_list($values)) {
            ksort($values, SORT_STRING);
        }

        return $values;
    }
}
