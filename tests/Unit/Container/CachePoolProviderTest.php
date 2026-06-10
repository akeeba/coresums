<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Tests\Unit\Container;

use Akeeba\CoreSums\Container\CachePoolProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pimple\Container;
use Psr\Cache\CacheItemPoolInterface;

#[CoversClass(CachePoolProvider::class)]
class CachePoolProviderTest extends TestCase
{
	public function testRegistersCachePoolService(): void
	{
		$c = new Container();
		$c->register(new CachePoolProvider());

		$this->assertContains('cachePool', $c->keys());
		$this->assertInstanceOf(CacheItemPoolInterface::class, $c['cachePool']);
	}
}
