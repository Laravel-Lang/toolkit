<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Support\Arr;
use LaravelLang\Exceptions\TranslationPackageNotFoundException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'sync:library')]
final class SyncLibraryCommand extends SyncCommand
{
    public function __invoke(
        #[Argument('Directory containing translation packages and Library')]
        string $path = __DIR__ . '/../../..',
    ): int {
        $source = $this->loadSources($path);

        foreach ($this->localeDirectories($path . '/Library/locales') as $directory) {
            foreach ($this->filesystem->files($directory) as $file) {
                if (str_starts_with($file->getFilename(), '_')) {
                    continue;
                }

                $content = $this->filesystem->json($file->getPathname());

                $clean = Arr::only($content, $source);

                $this->store($file->getPathname(), $clean);
            }
        }

        return Command::SUCCESS;
    }

    /** @return non-empty-list<int|string> */
    protected function loadSources(string $path): array
    {
        $result = [];

        foreach ($this->filesystem->glob(rtrim($path, '/\\') . '/*/laravel_lang.json') ?: [] as $settings) {
            $result = array_merge($result, $this->loadSource(dirname($settings)));
        }

        if ($result === []) {
            throw new TranslationPackageNotFoundException($path);
        }

        return $result;
    }
}
