<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Tests\Unit\Container;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Command\Init;
use Akeeba\CoreSums\Command\Sources;
use Akeeba\CoreSums\Command\Versions;
use Akeeba\CoreSums\Container\Container as CoreSumsContainer;
use Akeeba\CoreSums\Container\CommandsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandsProvider::class)]
class CommandsProviderTest extends TestCase
{
	/**
	 * Uses the project Container because individual commands depend on db, httpFactory, gitHub.
	 */
	public static function commandsProvider(): array
	{
		return [
			'init'     => ['command.init', Init::class],
			'generate' => ['command.generate', Generate::class],
			'dump'     => ['command.dump', Dump::class],
			'sources'  => ['command.sources', Sources::class],
			'versions' => ['command.versions', Versions::class],
		];
	}

	#[DataProvider('commandsProvider')]
	public function testCommandIsRegistered(string $key, string $class): void
	{
		$c = new CoreSumsContainer();

		$this->assertContains($key, $c->keys(), "Container should register key {$key}");
		$this->assertInstanceOf($class, $c[$key]);
	}
}
