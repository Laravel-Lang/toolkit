#!/usr/bin/env php
<?php

declare(strict_types=1);

use LaravelLang\Toolkit\Commands\CreateLocaleCommand;
use LaravelLang\Toolkit\Commands\StatusCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/../../../vendor/autoload.php';

$app = new Application(name: 'Laravel-Lang: Toolkit');

$app->addCommand(new StatusCommand());
$app->addCommand(new CreateLocaleCommand());

$app->run();
