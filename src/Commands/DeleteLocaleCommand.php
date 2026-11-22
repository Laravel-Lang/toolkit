<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LaravelLang\Exceptions\TranslationDirectoryNotFoundException;
use LaravelLang\LocaleList\Locale;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

use function in_array;

#[AsCommand(name: 'delete-locale')]
final class DeleteLocaleCommand
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
    ) {}

    public function __invoke(
        #[Argument('Project directory')]
        string $directory,
    ): int {
        if (($path = realpath($directory . '/locales')) === false) {
            throw TranslationDirectoryNotFoundException::forProjectLocales();
        }

        $locales = Locale::values();

        foreach ($this->filesystem->directories($path) as $directory) {
            $suffix = Str::of($directory)
                ->replace('\\', '/')
                ->afterLast('/')
                ->toString();

            if (in_array($suffix, $locales, true)) {
                continue;
            }

            $this->filesystem->deleteDirectory($directory);
        }

        return Command::SUCCESS;
    }
}
