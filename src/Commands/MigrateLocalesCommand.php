<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use JsonException;
use LaravelLang\Exceptions\TranslationDirectoryNotFoundException;
use LaravelLang\Exceptions\TranslationFileException;
use LaravelLang\Exceptions\TranslationSourceException;
use LaravelLang\Filesystem\Processors\PhpProcessor;
use LaravelLang\Toolkit\Services\TranslationFilesService;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Throwable;

#[AsCommand(
    name: 'migrate-locales',
    description: 'Convert a legacy Laravel-Lang project to Mona locale files',
    help: <<<'HELP'
        Reads source/ and locales/ from one legacy project and keeps the original files.
        Writes default.json, inline.json, and _settings.json into locale subdirectories of the target.
        When a phrase already exists in the target dictionary, the migrated translation takes precedence.
        HELP,
    usages: ['path/to/project --target=path/to/new/locales'],
)]
final class MigrateLocalesCommand
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
        protected TranslationFilesService $files = new TranslationFilesService,
    ) {}

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Legacy project directory with source/ and locales/')]
        string $project,
        #[Option('Destination for locale directories (prompted when omitted)')]
        ?string $target = null,
    ): int {
        $source  = $project . '/source';
        $locales = $project . '/locales';
        if (! $this->filesystem->isDirectory($project) || ! $this->filesystem->isDirectory($source)) {
            throw TranslationDirectoryNotFoundException::forRequestedPath($source);
        }
        if (! $this->filesystem->isDirectory($locales)) {
            throw TranslationDirectoryNotFoundException::forRequestedPath($locales);
        }

        if ($target === null && $input->isInteractive()) {
            $target = (new QuestionHelper)->ask($input, $output, new Question('Destination for locale directories: '));
        }
        if (! is_string($target) || trim($target) === '') {
            throw new RuntimeException('Provide a destination for locale directories with --target.');
        }

        $files = $this->prepare($locales, rtrim($target, '/\\'), $this->sourceKeys($source));
        if ($files === []) {
            throw new RuntimeException("No legacy locale files found in [$locales].");
        }

        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }

        $output->writeln('Locale files migrated.');

        return Command::SUCCESS;
    }

    /** @return array<string, array<array-key, array<array-key, true>>> */
    protected function sourceKeys(string $directory): array
    {
        $keys = ['json' => [], 'php' => []];
        $php  = new PhpProcessor($this->filesystem);

        foreach ($this->filesystem->allFiles($directory) as $file) {
            $format = strtolower($file->getExtension());
            if (! isset($keys[$format]) || str_starts_with($file->getFilename(), '_')) {
                continue;
            }

            $path = $file->getPathname();
            try {
                $source = $format === 'php' ? Arr::dot($php->read($path)) : $this->files->load($path);
            } catch (Throwable $exception) {
                throw TranslationSourceException::unableToRead($path, $exception);
            }

            foreach ($source as $legacyKey => $phrase) {
                if ($phrase === '' || $phrase === [] || $phrase === null) {
                    continue;
                }
                if (! is_int($phrase) && ! is_string($phrase)) {
                    throw TranslationSourceException::invalidKey($path);
                }
                $keys[$format][$legacyKey][$phrase] = true;
            }
        }

        if ($keys['json'] === [] && $keys['php'] === []) {
            throw TranslationSourceException::sourcesNotFound($directory);
        }

        return $keys;
    }

    /**
     * @param  array<string, array<array-key, array<array-key, true>>>  $keys
     * @return array<string, string>
     */
    protected function prepare(string $locales, string $target, array $keys): array
    {
        $files = [];

        foreach ($this->filesystem->directories($locales) as $directory) {
            $destination = $target . '/' . basename($directory);

            foreach (['default' => false, 'inline' => true] as $style => $inline) {
                $translations = $this->translations($directory, $keys, $inline);
                if ($translations !== null) {
                    $path         = $destination . '/' . $style . '.json';
                    $files[$path] = $this->encode($path, array_replace($this->read($path), $translations));
                }
            }

            $excludes = $directory . '/_excludes.json';
            if ($this->filesystem->exists($excludes)) {
                $path                 = $destination . '/_settings.json';
                $settings             = $this->read($path);
                $settings['excludes'] = $this->mergeExcludes($excludes, $settings['excludes'] ?? []);
                $files[$path]         = $this->encode($path, $settings);
            }
        }

        return $files;
    }

    /**
     * @param  array<string, array<array-key, array<array-key, true>>>  $keys
     * @return array<array-key, scalar>|null
     */
    protected function translations(string $directory, array $keys, bool $inline): ?array
    {
        $result = null;
        $suffix = $inline ? '-inline.json' : '.json';

        foreach ($keys as $format => $source) {
            $path = $directory . '/' . $format . $suffix;
            if (! $this->filesystem->exists($path)) {
                continue;
            }

            $result ??= [];
            foreach ($this->files->load($path) as $legacyKey => $translation) {
                if (! isset($source[$legacyKey]) || $translation === '' || $translation === null) {
                    continue;
                }
                if (! is_scalar($translation)) {
                    throw new RuntimeException("Expected a scalar translation for [$legacyKey] in [$path].");
                }
                foreach (array_keys($source[$legacyKey]) as $phrase) {
                    if (! $inline || stripos((string) $phrase, ':attribute') !== false) {
                        $result[$phrase] = $translation;
                    }
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    protected function mergeExcludes(string $path, mixed $existing): array
    {
        $legacy = $this->files->load($path);
        if (! is_array($existing) || ! array_is_list($existing) || ! array_is_list($legacy)) {
            throw new RuntimeException("Expected a list of exclusions while migrating [$path].");
        }

        $excludes = [...$existing, ...$legacy];
        foreach ($excludes as $value) {
            if (! is_string($value)) {
                throw new RuntimeException("Expected string exclusions while migrating [$path].");
            }
        }

        $excludes = array_unique($excludes);
        sort($excludes, SORT_STRING);

        return $excludes;
    }

    /** @return array<array-key, mixed> */
    protected function read(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            return [];
        }

        try {
            $value = json_decode($this->filesystem->get($path), flags: JSON_THROW_ON_ERROR);

            if (! is_object($value) && ! is_array($value)) {
                throw TranslationFileException::invalidRoot();
            }

            return (array) $value;
        } catch (Throwable $exception) {
            throw TranslationFileException::unableToRead($path, $exception);
        }
    }

    /** @param array<array-key, mixed> $values */
    protected function encode(string $path, array $values): string
    {
        ksort($values, SORT_STRING);

        try {
            return json_encode(
                (object) $values,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                                    | JSON_PRESERVE_ZERO_FRACTION,
            ) . PHP_EOL;
        } catch (JsonException $exception) {
            throw TranslationFileException::unableToWrite($path, $exception);
        }
    }

    protected function write(string $path, string $content): void
    {
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            if ($this->filesystem->put($temporary, $content) !== strlen($content) || ! rename($temporary, $path)) {
                throw TranslationFileException::unableToWrite($path, TranslationFileException::writeFailed());
            }
        } finally {
            if ($this->filesystem->exists($temporary)) {
                $this->filesystem->delete($temporary);
            }
        }
    }
}
