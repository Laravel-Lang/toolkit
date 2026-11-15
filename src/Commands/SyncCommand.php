<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LaravelLang\LocaleList\Locale;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function is_dir;
use function str_starts_with;

#[AsCommand(name: 'sync')]
class SyncCommand
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem(),
    ) {}

    #[AsCommand(name: 'package')]
    public function package(
        #[Argument('Path for package', suggestedValues: ['.'])]
        string $path = '.',
    ): int {
        if (! is_dir($path)) {
            throw new RuntimeException('Directory does not exist.');
        }

        if (! is_dir($path . '/locales')) {
            throw new RuntimeException('Locales directory does not exist.');
        }

        $source = $this->loadSource($path);

        foreach (Locale::values() as $locale) {
            if ($locale !== 'ru') {
                continue;
            }

            foreach ($this->filesystem->files($path . '/locales/' . $locale) as $file) {
                if (str_starts_with($file->getFilename(), '_')) {
                    continue;
                }

                $locales = $this->loadFile($file->getRealPath());

                $content = Arr::only($locales, $source);

                if (! $content) {
                    $this->filesystem->delete(
                        $file->getRealPath()
                    );

                    continue;
                }

                $this->filesystem->put(
                    $file->getRealPath(),
                    json_encode(
                        $content,
                        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    )
                );
            }
        }

        return Command::SUCCESS;
    }

    #[AsCommand(name: 'package')]
    public function library(): int
    {
        return Command::SUCCESS;
    }

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

        return array_values(array_unique($result));
    }

    protected function loadFile(string $path): array
    {
        return mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php'
            ? require $path
            : $this->filesystem->json($path);
    }

    protected function loadPhp(string $path): array
    {
        return (new Collection(require $path))
            ->dot()
            ->values()
            ->all();
    }

    private function loadJson(string $path): array
    {
        return array_keys(
            $this->filesystem->json($path)
        );
    }
}
