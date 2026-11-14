<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'download')]
class DownloadCommand
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem(),
    ) {}

    public function __invoke(
        OutputInterface $output,
        #[Option('Source URL')]
        ?string $url = null,
        #[Option('Project directory')]
        ?string $directory = null,
        #[Option('Project Version')]
        ?string $version = null,
        #[Option('Copy paths')]
        array $paths = [],
    ): int {
        if ($this->validate($output, $url, $directory, $version)) {
            return Command::FAILURE;
        }

        // TODO

        return Command::SUCCESS;
    }

    protected function validate(OutputInterface $output, ?string $url, ?string $directory, ?string $version): int
    {
        foreach (['URL' => $url, 'Directory' => $directory, 'Version' => $version] as $key => $value) {
            if (! $value) {
                $output->writeln("<error>$key option cannot be empty!</error>");

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
