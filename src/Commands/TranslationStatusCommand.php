<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;

#[AsCommand(name: 'translation:status')]
class TranslationStatusCommand
{
    public function __construct(
        protected Filesystem $filesystem = new Filesystem(),
    ) {}
    // todo: идея такая - у нас уже новые файлы содержат ключ и значение. Просто фильтруем по следующим правилам:
    // если КЛЮЧ найден в excludes - удаляем
    // если КЛЮЧ и ЗНАЧЕНИЕ различаются - удаляем
    // на выходе останутся непереведённые строки
    // сохраняем их в файл `_status.json`
    //
    // структура внутри файла:
    //   - ключ это имя исходного файла
    //   - в массиве - значения для файла 

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
            $result = [];

            $settings = $this->read($path . '/_settings.json');

            foreach ($this->filesystem->files($directory) as $file) {
                if (str_starts_with($file->getFilename(), '_')) {
                    continue;
                }

                $content = $this->read($file->getRealPath());
                $key     = $file->getFilenameWithoutExtension();

                $result[$key] = array_filter(
                    array   : $content,
                    callback: static function (string $value, string $key) use ($settings): bool {
                        if (in_array($key, $settings['excludes'] ?? [], true)) {
                            return false;
                        }

                        return $key === $value;
                    },
                    mode    : ARRAY_FILTER_USE_BOTH
                );
            }

            $result = array_filter($result);

            if (! $result) {
                if ($this->filesystem->exists($directory . '/_status.json')) {
                    $this->filesystem->delete($directory . '/_status.json');
                }

                return Command::SUCCESS;
            }

            $this->filesystem->put(
                $directory . '/_status.json',
                json_encode(
                    array_filter($result),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        }

        return Command::SUCCESS;
    }

    protected function read(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            return [];
        }

        return $this->filesystem->json($path);
    }
}
