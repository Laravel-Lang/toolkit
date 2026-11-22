<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use LaravelLang\Exceptions\DownloadArchiveException;
use LaravelLang\Exceptions\SourceDownloadException;
use LaravelLang\Exceptions\SourcePathException;
use LaravelLang\Exceptions\SourcePublicationException;
use LaravelLang\Exceptions\TranslationDirectoryNotFoundException;
use LaravelLang\Exceptions\TranslationFileException;
use LaravelLang\Toolkit\Services\TranslationFilesService;
use LaravelLang\Toolkit\Services\TranslationKeysService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use ZipArchive;

#[AsCommand(name: 'download')]
final class DownloadCommand
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
        protected Factory $http = new Factory,
        protected TranslationKeysService $keys = new TranslationKeysService,
        protected TranslationFilesService $translations = new TranslationFilesService,
    ) {}

    /** @param string[] $paths */
    public function __invoke(
        OutputInterface $output,
        #[Option('Source URL')]
        ?string $url = null,
        #[Option('Final destination directory for the source files')]
        ?string $directory = null,
        #[Option('Translation directories within the repository (repeatable)')]
        array $paths = [],
        #[Option('Copy translations without discovering keys')]
        bool $onlyCopy = false,
    ): int {
        if ($url === null || trim($url) === '') {
            $output->writeln('<error>URL option cannot be empty!</error>');

            return Command::FAILURE;
        }

        if ($directory === null || trim($directory) === '') {
            $output->writeln('<error>Directory option cannot be empty!</error>');

            return Command::FAILURE;
        }

        $temporary = null;
        $completed = false;

        try {
            if (
                ! filter_var($url, FILTER_VALIDATE_URL)
                || ! is_string($scheme = parse_url($url, PHP_URL_SCHEME))
                || ! in_array(strtolower($scheme), ['http', 'https'], true)
            ) {
                throw SourceDownloadException::invalidUrl();
            }

            if (! class_exists(ZipArchive::class)) {
                throw DownloadArchiveException::zipExtensionRequired();
            }

            $paths  = array_map($this->relativePath(...), $paths);
            $target = $this->targetPath($directory);
            $temporary
                = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/laravel-lang-toolkit-' . bin2hex(
                    random_bytes(8)
                );
            $archive  = $temporary . '/repository.zip';
            $unpacked = $temporary . '/repository';
            $staged   = $temporary . '/result';

            $this->filesystem->ensureDirectoryExists($unpacked);

            $output->writeln('Downloading repository...');

            $response = $this->http->connectTimeout(15)->timeout(120)
                ->withOptions(['allow_redirects' => ['protocols' => ['http', 'https']]])
                ->sink($archive)->get($url);

            if ($exception = $response->toException()) {
                throw SourceDownloadException::requestFailed($exception);
            }

            $repository = $this->unpack($archive, $unpacked);

            if ($this->filesystem->isDirectory($target)) {
                $this->checkLinks($target);

                if (! $this->filesystem->copyDirectory($target, $staged)) {
                    throw SourcePublicationException::preparationFailed();
                }
            } else {
                $this->filesystem->ensureDirectoryExists($staged);
            }

            if (! $onlyCopy && ($values = $this->keys->find($repository))) {
                $this->translations->store($staged . '/' . basename(dirname($target)) . '.json', $values);
            }

            foreach ($paths as $copy) {
                $selected = $this->directoryPath($repository, $copy);

                if (! $this->filesystem->isDirectory($selected)) {
                    throw TranslationDirectoryNotFoundException::forRequestedPath($copy);
                }

                foreach ($this->filesystem->allFiles($selected, true) as $file) {
                    if (! in_array(strtolower($file->getExtension()), ['php', 'json'], true)) {
                        continue;
                    }

                    $destination = $staged . '/' . $file->getFilename();

                    if (strtolower($file->getExtension()) === 'php') {
                        if (! $this->filesystem->copy($file->getPathname(), $destination)) {
                            throw TranslationFileException::unableToCopy($file->getPathname());
                        }

                        continue;
                    }

                    $existing = $this->filesystem->exists($destination) ? $this->translations->load($destination) : [];
                    $incoming = $this->translations->load($file->getPathname());

                    $this->translations->store($destination, array_replace_recursive($existing, $incoming));
                }
            }

            $this->filesystem->ensureDirectoryExists(dirname($target));
            $this->publish($staged, $target, $temporary . '/previous');

            $completed = true;

            $output->writeln('<info>Source files updated: ' . OutputFormatter::escape($target) . '</info>');
        } catch (Throwable $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

            return Command::FAILURE;
        } finally {
            // Keep the last good snapshot if rollback itself could not restore it.
            if ($temporary !== null && ($completed || ! $this->filesystem->exists($temporary . '/previous'))) {
                $this->filesystem->deleteDirectory($temporary);
            }
        }

        return Command::SUCCESS;
    }

    protected function targetPath(string $directory): string
    {
        $directory = str_replace('\\', '/', $directory);

        if (preg_match('~^[A-Za-z]:/~', $directory)) {
            $root      = substr($directory, 0, 3);
            $directory = substr($directory, 3);
        } elseif (str_starts_with($directory, '/')) {
            $root      = '/';
            $directory = ltrim($directory, '/');
        } else {
            if (($root = getcwd()) === false) {
                throw SourcePathException::workingDirectoryUnavailable();
            }

            $directory = preg_replace('~^(?:\./)+~', '', $directory)
                ?? throw SourcePathException::destinationNormalizationFailed();
        }

        return $this->directoryPath($root, $this->relativePath($directory));
    }

    protected function relativePath(string $value): string
    {
        $value = str_replace('\\', '/', $value);

        if (preg_match('/[\x00-\x1f<>:"|?*]/', $value)) {
            throw SourcePathException::unsafeRelativePath($value);
        }

        foreach (explode('/', rtrim($value, '/')) as $segment) {
            if (
                in_array($segment, ['', '.', '..'], true)
                || preg_match('/[. ]$/', $segment)
                || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment)
            ) {
                throw SourcePathException::unsafeRelativePath($value);
            }
        }

        return rtrim($value, '/');
    }

    protected function directoryPath(string $root, string $relative): string
    {
        $path = rtrim(str_replace('\\', '/', $root), '/');

        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;

            if (is_link($path) || ($this->filesystem->exists($path) && ! $this->filesystem->isDirectory($path))) {
                throw SourcePathException::unexpectedDirectory($path);
            }

            if (($resolved = realpath($path)) !== false) {
                $base = rtrim(str_replace('\\', '/', $root), '/') . '/';
                $real = str_replace('\\', '/', $resolved) . '/';

                if (PHP_OS_FAMILY === 'Windows') {
                    $base = strtolower($base);
                    $real = strtolower($real);
                }

                if (! str_starts_with($real, $base)) {
                    throw SourcePathException::outsideProjectRoot($path);
                }
            }
        }

        return $path;
    }

    protected function unpack(string $archive, string $destination): string
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw DownloadArchiveException::unreadable();
        }

        try {
            if ($zip->numFiles === 0) {
                throw DownloadArchiveException::emptyArchive();
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if ($name === false || str_contains($name, '\\')) {
                    throw DownloadArchiveException::invalidEntryPath();
                }

                $this->relativePath($name);

                if ($zip->getExternalAttributesIndex($index, $system, $attributes)) {
                    if (! is_int($attributes)) {
                        throw DownloadArchiveException::entryAttributesUnavailable($name);
                    }

                    if (($attributes >> 16 & 0o170000) === 0o120000) {
                        throw DownloadArchiveException::symbolicLink($name);
                    }
                }
            }

            if (! $zip->extractTo($destination)) {
                throw DownloadArchiveException::extractionFailed();
            }
        } finally {
            $zip->close();
        }

        $directories = $this->filesystem->directories($destination);

        return count($directories) === 1 && $this->filesystem->files($destination, true) === []
            ? $directories[0]
            : $destination;
    }

    protected function checkLinks(string $directory): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo) {
                throw SourcePathException::inspectionFailed($directory);
            }

            if ($file->isLink()) {
                throw SourcePathException::symbolicLink($file->getPathname());
            }
        }
    }

    protected function publish(string $staged, string $target, string $backup): void
    {
        $exists = $this->filesystem->isDirectory($target);

        if ($exists) {
            try {
                if (! $this->filesystem->copyDirectory($target, $backup)) {
                    throw SourcePublicationException::backupFailed();
                }
            } catch (Throwable $exception) {
                $this->filesystem->deleteDirectory($backup);

                throw SourcePublicationException::fromBackupFailure($exception);
            }
        }

        $created = [];

        foreach ($this->filesystem->allFiles($staged, true) as $file) {
            $destination = $target . '/' . $file->getRelativePathname();

            if (! $this->filesystem->exists($destination)) {
                $created[] = $destination;
            }
        }

        try {
            if (! $this->filesystem->copyDirectory($staged, $target)) {
                throw SourcePublicationException::publicationFailed();
            }
        } catch (Throwable $exception) {
            try {
                if ($exists && ! $this->filesystem->copyDirectory($backup, $target)) {
                    throw SourcePublicationException::backupRestoreFailed($exception);
                }

                foreach ($created as $file) {
                    if ($this->filesystem->exists($file) && ! $this->filesystem->delete($file)) {
                        throw SourcePublicationException::incompleteFileRemovalFailed($file, $exception);
                    }
                }

                if (
                    ! $exists && $this->filesystem->isDirectory($target)
                              && $this->filesystem->isEmptyDirectory($target)
                ) {
                    rmdir($target);
                }
            } catch (Throwable $restoreException) {
                throw SourcePublicationException::restoreFailed($target, $exists ? $backup : null, $restoreException);
            }

            $this->filesystem->deleteDirectory($backup);

            throw SourcePublicationException::publicationFailedAfterRollback($exception);
        }
    }
}
