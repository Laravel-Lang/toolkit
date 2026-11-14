#!/usr/bin/env php
<?php

declare(strict_types=1);

use LaravelLang\Toolkit\Commands\TranslationStatusCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/../../../vendor/autoload.php';

$app = new Application(name: 'Laravel-Lang: Toolkit');

$app->addCommand(new TranslationStatusCommand());

$app->run();
