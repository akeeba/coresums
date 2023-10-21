<?php

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