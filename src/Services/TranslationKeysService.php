<?php

declare(strict_types=1);

namespace LaravelLang\Toolkit\Services;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

class TranslationKeysService
{
    /** @return array<array-key, string> */
    public function find(string $directory): array
    {
        // shortcut: scans source text, use a language parser if comments or embedded calls need excluding.
        $pattern = <<<'REGEX'
            ~(?<![\w$])(?:
                __|\$t|fail|lang|Lang\s*::\s*(?:choice|get)|tChoice|trans_choice|trans|wTransChoice|wTrans|
                getTranslator\s*\(\s*\)\s*->\s*(?:get|choice)
            )\s*\(\s*(?<literal>'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")\s*(?=[,)])~sx
            REGEX;

        $files = Finder::create()->in($directory)->files()
            ->name(['*.php', '*.json', '*.js', '*.ts', '*.vue', '*.stub']);

        $keys = [];

        foreach ($files as $file) {
            preg_match_all($pattern, $file->getContents(), $matches);

            foreach ($matches['literal'] as $literal) {
                $key = trim(stripslashes(substr($literal, 1, -1)), "\t\n\r\0\x0B");

                if ($this->isTranslatable($key)) {
                    $keys[$key] = $key;
                }
            }
        }

        ksort($keys);

        return $keys;
    }

    protected function isTranslatable(string $key): bool
    {
        if (
            $key === ''
            || Str::contains($key, [
                '$', '::', 'auth.', 'pagination.', 'passwords.', 'validation.', 'prototype', 'constructor', 'object',
            ])
        ) {
            return false;
        }

        $lower = Str::lower($key);

        if (
            Str::wordCount($key) > 1
            && (
                $key === str_replace(' ', '_', Str::upper($key)) || $key === str_replace(' ', '_', $lower)
            )
        ) {
            return false;
        }

        if (
            ! str_contains($key, ' ')
            && (
                (Str::contains($key, ['.', '-', '_', ':']) && $key === $lower)
                || (str_contains($key, '-') && $key === Str::title($key))
            )
        ) {
            return false;
        }

        return ! preg_match('/^[\W_]+$/', $key) && ! preg_match('/.+\?.+:.+/', $key);
    }
}
