<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Container;

use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class HttpFactoryProvider implements ServiceProviderInterface
{
	public function register(Container $pimple)
	{
		$pimple['httpFactory'] = fn(Container $c) => new HttpFactory($c);
	}
}