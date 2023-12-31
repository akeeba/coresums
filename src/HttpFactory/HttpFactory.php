<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\HttpFactory;

use Akeeba\CoreSums\Container\Container;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\KeyValueHttpHeader;
use Kevinrob\GuzzleCache\Storage\Psr6CacheStorage;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;
use Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;

class HttpFactory
{
	private static $instances = [];

	public function __construct(private Container $container) {}

	public function makeClient(
		?HandlerStack $stack = null,
		array         $clientOptions = [],
		bool          $cache = true,
		?int          $cacheTTL = null,
		bool          $singleton = true
	): Client
	{
		$signature = md5(
			($stack ? serialize($stack) : '*NULL*')
			. '#' .
			(!empty($clientOptions) ? serialize($clientOptions) : '*NULL*')
			. '#' .
			($cache ? 'cache' : 'no-cache')
		);

		if ($singleton && isset(self::$instances[$signature]))
		{
			return self::$instances[$signature];
		}

		$stack ??= HandlerStack::create();

		if ($cache)
		{
			$cachePool = new Psr6CacheStorage(
				$this->container->cachePool
			);

			if ($cacheTTL !== null && $cacheTTL > 0)
			{
				$greedyCacheStrategy = new GreedyCacheStrategy(
					$cachePool,
					$cacheTTL,
				);

				$stack->push(new CacheMiddleware($greedyCacheStrategy), 'greedy-cache');
			}
			else
			{
				$cacheStrategy = new PrivateCacheStrategy($cachePool);
				$stack->push(new CacheMiddleware($cacheStrategy), 'cache');
			}
		}

		$clientOptions = array_merge($clientOptions + ['handler' => $stack]);

		$client = new Client(
			$clientOptions
		);

		if ($singleton)
		{
			self::$instances[$signature] = $client;
		}

		return $client;
	}
}