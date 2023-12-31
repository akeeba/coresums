<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

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