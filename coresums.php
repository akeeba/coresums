#!/usr/bin/env php
<?php

use Akeeba\CoreSums\Container\Container as CoreSumsContainer;
use Pimple\Psr11\Container as Psr11ContainerWrapper;
use Silly\Application;

if (!file_exists(__DIR__ . '/vendor/autoload.php'))
{
	echo "You need to run  composer install  first.";

	exit(255);
}

require_once __DIR__ . '/vendor/autoload.php';

$container = new CoreSumsContainer();
$container->dotenv->safeLoad();

$app = new Application();
$app->useContainer(new Psr11ContainerWrapper($container));

$app->command('sources [--latest]', 'command.sources')
	->descriptions(
		'Collect source URLs for new Joomla! releases',
		[
				'--latest' => 'Only go through the latest 30 releases'
		]
	);

$app->setDefaultCommand('sources');

try
{
	$app->run();
}
catch (Throwable $e)
{
	echo <<< TEXT

********************************************************************************
***                                  ERROR                                   ***
********************************************************************************

{$e->getMessage()}

{$e->getFile()}:{$e->getLine()}

{$e->getTraceAsString()}

TEXT;

	exit(255);
}