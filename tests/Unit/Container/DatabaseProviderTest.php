<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Tests\Unit\Container;

use Akeeba\CoreSums\Container\DatabaseProvider;
use Joomla\Database\Sqlite\SqliteDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

#[CoversClass(DatabaseProvider::class)]
class DatabaseProviderTest extends TestCase
{
	public function testRegistersDbServiceAsSqliteDriver(): void
	{
		$c = new Container();
		$c->register(new DatabaseProvider());

		$this->assertContains('db', $c->keys());
		$this->assertInstanceOf(SqliteDriver::class, $c['db']);
	}
}
