<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use LaravelLang\LocaleList\Locale;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'create-locale')]
final class CreateLocaleCommand
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
    ) {}

    public function __invoke(
        #[Argument('Project directory')]
        string $directory,
    ): int {
        foreach (Locale::values() as $locale) {
            $this->filesystem->ensureDirectoryExists(
                $directory . '/locales/' . $locale,
            );
        }

        return Command::SUCCESS;
    }
}
