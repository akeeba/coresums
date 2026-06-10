<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Tests\Unit\Container;

use Akeeba\CoreSums\Container\Container as CoreSumsContainer;
use Akeeba\CoreSums\Container\HttpFactoryProvider;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpFactoryProvider::class)]
class HttpFactoryProviderTest extends TestCase
{
	public function testHttpFactoryServiceResolves(): void
	{
		// HttpFactory's constructor is type-hinted with the project's Container
		// (not Pimple's), so we use the project Container which auto-registers
		// HttpFactoryProvider in its constructor.
		$c = new CoreSumsContainer();

		$this->assertContains('httpFactory', $c->keys());
		$this->assertInstanceOf(HttpFactory::class, $c['httpFactory']);
	}
}
