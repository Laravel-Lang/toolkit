<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use LaravelLang\LocaleList\Locale;
use RuntimeException;
use Throwable;

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

            $source = $file->getPathname();

            try {
                $content = mb_strtolower($file->getExtension()) === 'php'
                    ? $this->loadPhp($source)
                    : $this->loadJson($source);
            } catch (Throwable $exception) {
                throw new RuntimeException("Unable to read translation source [$source]: " . $exception->getMessage(), 0, $exception);
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

    protected function loadFile(string $path, string $extension): array
    {
        if (mb_strtolower($extension) === 'php') {
            return require $path;
        }

        return $this->filesystem->json($path);
    }

    protected function loadPhp(string $path): array
    {
        $content = require $path;

        if (! is_array($content)) {
            throw new RuntimeException('Expected a PHP translation array.');
        }

        $keys = [];

        foreach (Arr::dot($content) as $value) {
            if ($value === []) {
                continue;
            }

            if (! is_string($value) && ! is_int($value)) {
                throw new RuntimeException('Expected PHP translation values to be strings or integers.');
            }

            $keys[] = $value;
        }

        return $keys;
    }

    protected function loadJson(string $path): array
    {
        $content = json_decode($this->filesystem->get($path), flags: JSON_THROW_ON_ERROR);

        if (! is_object($content)) {
            throw new RuntimeException('Expected a JSON translation object.');
        }

        return array_keys(get_object_vars($content));
    }

    protected function store(string $path, array $content): void
    {
        $encoded = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php'
            ? '<?php return ' . var_export($content, true) . ';' . PHP_EOL
            : json_encode((object) $content, $this->flags);

        $this->filesystem->put($path, $encoded);
    }
}
