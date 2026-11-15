<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Support\Arr;
use LaravelLang\LocaleList\Locale;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

use function is_dir;
use function str_starts_with;

#[AsCommand(name: 'sync:package')]
class SyncPackageCommand extends SyncCommand
{
    public function __invoke(
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
            foreach ($this->filesystem->files($path . '/locales/' . $locale) as $file) {
                if (str_starts_with($file->getFilename(), '_')) {
                    continue;
                }

                $locales = $this->loadFile($file->getRealPath(), $file->getExtension());

                $content = Arr::only($locales, $source);

                if (! $content) {
                    $this->filesystem->delete(
                        $file->getRealPath()
                    );

                    continue;
                }

                $this->store($file->getPathname(), $content);
            }
        }

        return Command::SUCCESS;
    }
}
