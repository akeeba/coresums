<?php

namespace Akeeba\CoreSums\Container;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CachePoolProvider implements ServiceProviderInterface
{
	public function register(Container $pimple)
	{
		$pimple['cachePool'] = function (Container $c)
		{
			$cachePath = __DIR__ . '/../../tmp';

			if (!is_dir($cachePath))
			{
				mkdir($cachePath, 0755);
			}

			return new FilesystemAdapter(defaultLifetime: 3600, directory: $cachePath);
		};
	}
}