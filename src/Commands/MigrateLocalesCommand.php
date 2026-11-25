<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Commands;

use Illuminate\Filesystem\Filesystem as BaseFilesystem;
use Illuminate\Support\Collection;
use LaravelLang\Filesystem\Filesystem;
use LaravelLang\Filesystem\Processors\JsonProcessor;
use LaravelLang\Filesystem\Processors\PhpProcessor;
use LaravelLang\LocaleList\Locale;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function array_replace;
use function array_values;
use function is_int;
use function is_string;
use function json_encode;
use function ksort;
use function rtrim;

#[AsCommand(
    name       : 'migrate-locales',
    description: 'Convert a legacy Laravel-Lang project to Mona locale files',
    help       : <<<'HELP'
        Reads source/ and locales/ from one legacy project and keeps files in source/.
        Writes default.json, inline.json, and _settings.json into locale subdirectories of the target.
        When a phrase already exists in the target dictionary, the migrated translation takes precedence.
        Deletes migrated legacy dictionaries and exclusion files, including when a separate target is used.
        HELP,
    usages     : ['path/to/project --target=path/to/new/locales'],
)]
final class MigrateLocalesCommand
{
    protected Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem(
            filesystem   : $filesystem = new BaseFilesystem,
            jsonProcessor: new JsonProcessor($filesystem),
            phpProcessor : new PhpProcessor($filesystem),
        );
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Legacy project directory with `source/` and `locales/`')]
        string $project,
        #[Option('Destination for locale directories. By default, project `locales/` directory')]
        ?string $target = null,
    ): int {
        $target ??= rtrim($project, '/\\') . '/locales';

        $this->excludes($project . '/locales', $target);

        $this->migrateJson($project . '/locales', $target);
        $this->migratePhp($project, $target);

        return Command::SUCCESS;
    }

    protected function excludes(string $source, string $target): void
    {
        foreach (Locale::values() as $locale) {
            $sourcePath = $source . '/' . $locale . '/_excludes.json';
            $targetPath = $target . '/' . $locale . '/_settings.json';

            $sourceData = $this->filesystem->read($sourcePath);

            if ($sourceData === []) {
                continue;
            }

            $targetData = $this->filesystem->read($targetPath, 'excludes');
            $data       = array_values(array_merge($targetData, $sourceData));

            ksort($data);

            $this->filesystem->store($targetPath, $this->encode(['excludes' => $data]));
            $this->filesystem->ensureDelete($sourcePath);
        }
    }

    protected function migrateJson(string $source, string $target): void
    {
        foreach (Locale::values() as $locale) {
            $sourceDefaultPath = $source . '/' . $locale . '/json.json';
            $sourceInlinePath  = $source . '/' . $locale . '/json-inline.json';

            $targetDefaultPath = $target . '/' . $locale . '/default.json';
            $targetInlinePath  = $target . '/' . $locale . '/inline.json';

            $sourceDataDefault = $this->filesystem->read($sourceDefaultPath);
            $sourceDataInline  = $this->filesystem->read($sourceInlinePath);

            $targetDataDefault = $this->filesystem->read($targetDefaultPath);
            $targetDataInline  = $this->filesystem->read($targetInlinePath);

            $dataDefault = array_replace($targetDataDefault, $sourceDataDefault);
            $dataInline  = array_replace($targetDataInline, $sourceDataInline);

            ksort($dataDefault);
            ksort($dataInline);

            if ($dataDefault !== []) {
                $this->filesystem->store($targetDefaultPath, $this->encode($dataDefault));
            }

            if ($dataInline !== []) {
                $this->filesystem->store($targetInlinePath, $this->encode($dataInline));
            }

            $this->filesystem->ensureDelete($sourceDefaultPath);
            $this->filesystem->ensureDelete($sourceInlinePath);
        }
    }

    protected function migratePhp(string $source, string $target): void
    {
        $sourceData = [];

        foreach ($this->filesystem->files($source . '/source') as $file) {
            if ($this->filesystem->isJson($file->getFilename())) {
                continue;
            }

            $data = $this->filesystem->read($file->getPathname());

            $normalized = (new Collection($data))
                ->dot()
                ->filter()
                ->forget('custom.attribute-name.rule-name')
                ->all();

            $sourceData = array_replace($sourceData, $normalized);
        }

        foreach (Locale::values() as $locale) {
            $sourceDefaultPath = $source . '/locales/' . $locale . '/php.json';
            $sourceInlinePath  = $source . '/locales/' . $locale . '/php-inline.json';

            $targetDefaultPath = $target . '/' . $locale . '/default.json';
            $targetInlinePath  = $target . '/' . $locale . '/inline.json';

            $this->processPhp($sourceDefaultPath, $targetDefaultPath, $sourceData);
            $this->processPhp($sourceInlinePath, $targetInlinePath, $sourceData);
        }
    }

    /** @param array<array-key, mixed> $keys */
    protected function processPhp(string $sourcePath, string $targetPath, array $keys): void
    {
        $sourceData = $this->filesystem->read($sourcePath);
        $targetData = $this->filesystem->read($targetPath);

        $combinedData = $this->combine($keys, $sourceData);

        if ($combinedData === []) {
            return;
        }

        $data = array_replace($targetData, $combinedData);

        ksort($data);

        $this->filesystem->store($targetPath, $this->encode($data));
        $this->filesystem->ensureDelete($sourcePath);
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @param  array<array-key, mixed>  $target
     * @return array<array-key, mixed>
     */
    protected function combine(array $source, array $target): array
    {
        $result = [];

        foreach ($target as $key => $value) {
            $newKey = $source[$key] ?? null;

            if (! $newKey || (! is_int($newKey) && ! is_string($newKey))) {
                continue;
            }

            $result[$newKey] = $value;
        }

        return $result;
    }

    /** @param array<array-key, mixed> $data */
    protected function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
