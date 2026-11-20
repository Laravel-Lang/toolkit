<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;

#[AsCommand(name: 'status')]
final class StatusCommand
{
    protected int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
    ) {}

    public function __invoke(
        OutputInterface $output,
        #[Argument('Project directory')]
        string $directory,
    ): int {
        if (! $path = realpath($directory . '/locales')) {
            $output->writeln('<error>The "locales" directory doesn\'t exists!</error>');

            return Command::FAILURE;
        }

        $settings = $this->read($path . '/_settings.json');

        foreach ($this->filesystem->directories($path) as $directory) {
            $local    = $this->read($directory . '/_settings.json');
            $excludes = array_merge($settings['excludes'] ?? [], $local['excludes'] ?? []);
            $result   = $this->process($directory, $excludes);

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

            $result[$key] = (new Collection($content))
                ->filter(static function (string $value, string $key) use ($excludes): bool {
                    if (in_array($key, $excludes, true)) {
                        return false;
                    }

                    return $key === $value;
                })
                ->sort(static function (string $a, string $b): int {
                    $a = Str::lower($a);
                    $b = Str::lower($b);

                    return $a <=> $b;
                })
                ->all();
        }

        return $result;
    }

    protected function store(string $directory, array $content): void
    {
        $path = $directory . '/_status.json';

        $result = array_filter($content);

        if ($result !== []) {
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
