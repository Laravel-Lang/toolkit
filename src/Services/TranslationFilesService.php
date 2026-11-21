<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
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
                default => throw new RuntimeException('Only JSON translation files are merged.'),
            };

            if (! is_array($values)) {
                throw new RuntimeException('The translation file must contain an array or object.');
            }

            return $values;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Unable to read translation file [$path]: {$exception->getMessage()}",
                0,
                $exception
            );
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
                default => throw new RuntimeException('Only JSON translation files are merged.'),
            };

            if ($this->filesystem->put($path, $content) === false) {
                throw new RuntimeException('The translation file could not be written.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Unable to write translation file [$path]: {$exception->getMessage()}",
                0,
                $exception
            );
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
                throw new RuntimeException(
                    'Translations must contain arrays, strings, finite numbers, booleans or null.'
                );
            }
        }

        if (! array_is_list($values)) {
            ksort($values, SORT_STRING);
        }

        return $values;
    }
}
