<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Container;

use Github\AuthMethod;
use Github\Client;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class GitHubProvider implements ServiceProviderInterface
{
	public function register(Container $pimple)
	{
		$pimple['gitHub'] = function (Container $container)
		{
			$client = new Client();
			$client->addCache($container['cachePool']);

			$token = $_ENV['GITHUB_TOKEN'] ?? '';

			if (!empty($token))
			{
				$client->authenticate($token, authMethod: AuthMethod::ACCESS_TOKEN);
			}

			return $client;
		};
	}
}