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

$app->command('sources [--latest] [--process]', 'command.sources')
	->descriptions(
		'Collect source URLs for new Joomla! releases',
		[
			'--latest'  => 'Only go through the 30 latest releases',
			'--process' => 'Create checksums for the sources found',
		]
	);

$app->command('generate [cms] [cmsVersion] [--all] [--new]', 'command.generate')
	->defaults(
		[
			'cms' => 'joomla',
		]
	)
	->descriptions(
		'Generate the file checksums',
		[
			'cms'        => 'The CMS to process.',
			'cmsVersion' => 'The CMS version to process.',
			'--all'      => 'Process all versions of the CMS (SLOW!)',
			'--new'      => 'Only process versions without existing checksums',
		]
	);

$app->command('dump [outdir] [--sources] [--no-sums] [--gzip]', 'command.dump')
	->descriptions(
		'Dump sums and/or sources as JSON data files',
		[
			'outdir'    => 'The root folder where the structure of JSON files will be created in',
			'--sources' => 'Create a Joomla! download source JSON file',
			'--no-sums' => 'Do not create the checksum JSON files',
			'--gzip'    => 'Generate GZip–compressed JSON files instead of plain text ones',
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