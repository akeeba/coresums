<?php

namespace Akeeba\CoreSums\Container;

use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Command\Sources;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class CommandsProvider implements ServiceProviderInterface
{

	public function register(Container $pimple)
	{
		$pimple['command.sources'] = fn(Container $c) => new Sources(
			$c->db, $c->gitHub
		);
		$pimple['command.generate'] = fn(Container $c) => new Generate(
			$c->db, $c->httpFactory
		);
	}
}