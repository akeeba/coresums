<?php

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