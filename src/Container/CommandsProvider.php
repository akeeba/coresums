<?php

namespace Akeeba\CoreSums\Container;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Command\Init;
use Akeeba\CoreSums\Command\Sources;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class CommandsProvider implements ServiceProviderInterface
{
	public function register(Container $pimple)
	{
		$pimple['command.init'] = fn(Container $c) => new Init(
			$c['db']
		);

		$pimple['command.generate'] = fn(Container $c) => new Generate(
			$c['db'], $c['httpFactory']
		);

		$pimple['command.dump'] = fn(Container $c) => new Dump(
			$c['db']
		);

		$pimple['command.sources'] = fn(Container $c) => new Sources(
			$c['db'], $c['gitHub'], $c['command.generate'], $c['command.dump']
		);
	}
}