<?php

namespace Akeeba\CoreSums\Container;

use Joomla\Database\Sqlite\SqliteDriver;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class DatabaseProvider implements ServiceProviderInterface
{
	/**
	 * @inheritDoc
	 */
	public function register(Container $pimple)
	{
		$pimple['db'] = fn(Container $c) => new SqliteDriver(
			[
				'version'  => 3,
				'driver'   => 'sqlite',
				'database' => __DIR__ . '/../../sums.sqlite',
			]
		);
	}
}