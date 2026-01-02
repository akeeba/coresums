<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Container;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Command\Init;
use Akeeba\CoreSums\Command\Sources;
use Akeeba\CoreSums\Command\Versions;
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

		$pimple['command.versions'] = fn(Container $c) => new Versions(
			$c['db']
		);

	}
}