<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Support\Arr;
use LaravelLang\LocaleList\Locale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

use function array_intersect_key;

#[AsCommand(name: 'sync:library')]
class SyncLibraryCommand extends SyncCommand
{
    protected string $basePath = __DIR__ . '/../../../';

    public function __invoke(): int
    {
        $source = $this->loadSources($this->basePath);

        foreach (Locale::values() as $locale) {
            foreach ($this->filesystem->files($this->basePath . 'Library/locales/' . $locale) as $file) {
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

    protected function loadSources(string $path): array
    {
        $result = [];

        foreach ($this->filesystem->glob($path . '*/laravel_lang.json') as $settings) {
            $result = array_merge($result, $this->loadSource(dirname($settings)));
        }

        return $result;
    }
}
