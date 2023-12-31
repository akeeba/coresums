<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Container;

use Pimple\Container;
use Pimple\ServiceProviderInterface;

class DotenvProvider implements ServiceProviderInterface
{
	public function register(Container $pimple)
	{
		$pimple['dotenv'] = fn(Container $c) => \Dotenv\Dotenv::createImmutable(__DIR__);
	}
}