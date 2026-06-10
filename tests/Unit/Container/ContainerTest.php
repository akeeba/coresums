<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Tests\Unit\Container;

use Akeeba\CoreSums\Container\Container;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Dotenv\Dotenv;
use Github\Client as GitHubClient;
use Joomla\Database\Sqlite\SqliteDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

#[CoversClass(Container::class)]
class ContainerTest extends TestCase
{
	public function testFullContainerBoots(): void
	{
		$c = new Container();

		$this->assertInstanceOf(SqliteDriver::class, $c['db']);
		$this->assertInstanceOf(Dotenv::class, $c['dotenv']);
		$this->assertInstanceOf(CacheItemPoolInterface::class, $c['cachePool']);
		$this->assertInstanceOf(GitHubClient::class, $c['gitHub']);
		$this->assertInstanceOf(HttpFactory::class, $c['httpFactory']);
	}

	public function testMagicGetterReturnsSameInstanceAsArrayAccess(): void
	{
		$c = new Container();

		$this->assertSame($c['db'], $c->db);
		$this->assertSame($c['cachePool'], $c->cachePool);
	}

	public function testMagicSetterStoresValue(): void
	{
		$c = new Container();
		$c->customValue = 'hello';

		$this->assertSame('hello', $c['customValue']);
	}
}
