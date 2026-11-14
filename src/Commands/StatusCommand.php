<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;

#[AsCommand(name: 'status')]
class StatusCommand
{
    protected int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem(),
    ) {}

    public function __invoke(
        OutputInterface $output,
        #[Option('Project directory')]
        ?string $directory = null,
    ): int {
        if ($directory === null) {
            $output->writeln('<error>No translation directory set</error>');

            return Command::FAILURE;
        }

        if (! $path = realpath($directory . '/locales')) {
            $output->writeln('<error>The "locales" directory doesn\'t exists!</error>');
            $output->writeln("<error>$directory/locales</error>");

            return Command::FAILURE;
        }

        foreach ($this->filesystem->directories($path) as $directory) {
            $settings = $this->read($path . '/_settings.json');

            $result = $this->process($directory, $settings['excludes'] ?? []);

            $this->store($directory, $result);
        }

        return Command::SUCCESS;
    }

    protected function process(string $directory, array $excludes): array
    {
        $result = [];

        foreach ($this->filesystem->files($directory) as $file) {
            if (str_starts_with($file->getFilename(), '_')) {
                continue;
            }

            $content = $this->read($file->getRealPath());
            $key     = $file->getFilenameWithoutExtension();

            $result[$key] = array_filter(
                array   : $content,
                callback: static function (string $value, string $key) use ($excludes): bool {
                    if (in_array($key, $excludes, true)) {
                        return false;
                    }

                    return $key === $value;
                },
                mode    : ARRAY_FILTER_USE_BOTH
            );
        }

        return $result;
    }

    protected function store(string $directory, array $content): void
    {
        $path = $directory . '/_status.json';

        $result = array_filter($content);

        if ($result) {
            $this->filesystem->put($path, json_encode($result, $this->flags));

            return;
        }

        if ($this->filesystem->exists($path)) {
            $this->filesystem->delete($path);
        }
    }

    protected function read(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            return [];
        }

        return $this->filesystem->json($path);
    }
}
