<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use TypeError;

use function in_array;

#[AsCommand(name: 'status')]
final class StatusCommand
{
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

        $globalExcludes = $this->excludes($path . '/_settings.json');

        foreach ($this->filesystem->directories($path) as $dir) {
            $excludes = array_merge($globalExcludes, $this->excludes($dir . '/_settings.json'));
            $result   = $this->process($dir, $excludes);

            $this->store($dir, $result);
        }

        return Command::SUCCESS;
    }

    /** @return array<array-key, mixed> */
    protected function excludes(string $path): array
    {
        $excludes = $this->read($path)['excludes'] ?? [];

        if (! is_array($excludes)) {
            throw new TypeError("The excludes setting in [$path] must be an array.");
        }

        return $excludes;
    }

    /**
     * @param  array<array-key, mixed>  $excludes
     * @return array<array-key, array<array-key, mixed>>
     */
    protected function process(string $directory, array $excludes): array
    {
        $result = [];

        foreach ($this->filesystem->files($directory) as $file) {
            if (str_starts_with($file->getFilename(), '_')) {
                continue;
            }

            $path    = $file->getRealPath();
            $missing = [];

            foreach ($this->read($path) as $key => $value) {
                if (! is_scalar($value)) {
                    throw new TypeError("Translation values in [$path] must be scalar.");
                }

                $name = (string) $key;

                if ($name === (string) $value && ! in_array($name, $excludes, true)) {
                    $missing[$key] = $value;
                }
            }

            uksort($missing, static fn (int|string $a, int|string $b): int => Str::lower((string) $a) <=> Str::lower((string) $b));

            $result[$file->getFilenameWithoutExtension()] = $missing;
        }

        return $result;
    }

    /** @param array<array-key, array<array-key, mixed>> $content */
    protected function store(string $directory, array $content): void
    {
        $path = $directory . '/_status.json';

        $result = array_filter($content);

        if ($result !== []) {
            $this->filesystem->put($path, json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        if ($this->filesystem->exists($path)) {
            $this->filesystem->delete($path);
        }
    }

    /** @return array<array-key, mixed> */
    protected function read(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            return [];
        }

        return $this->filesystem->json($path);
    }
}
