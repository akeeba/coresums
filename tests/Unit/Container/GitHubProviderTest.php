<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Tests\Unit\Container;

use Akeeba\CoreSums\Container\CachePoolProvider;
use Akeeba\CoreSums\Container\GitHubProvider;
use Github\Client as GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

#[CoversClass(GitHubProvider::class)]
class GitHubProviderTest extends TestCase
{
	private ?string $savedToken = null;
	private bool $hadToken = false;

	protected function setUp(): void
	{
		$this->hadToken   = array_key_exists('GITHUB_TOKEN', $_ENV);
		$this->savedToken = $this->hadToken ? (string) $_ENV['GITHUB_TOKEN'] : null;
	}

	protected function tearDown(): void
	{
		if ($this->hadToken)
		{
			$_ENV['GITHUB_TOKEN'] = $this->savedToken;
		}
		else
		{
			unset($_ENV['GITHUB_TOKEN']);
		}
	}

	public function testReturnsGithubClientWithoutToken(): void
	{
		unset($_ENV['GITHUB_TOKEN']);

		$c = new Container();
		$c->register(new CachePoolProvider());
		$c->register(new GitHubProvider());

		$this->assertContains('gitHub', $c->keys());
		$this->assertInstanceOf(GitHubClient::class, $c['gitHub']);
	}

	public function testReturnsGithubClientWhenTokenSet(): void
	{
		$_ENV['GITHUB_TOKEN'] = 'dummy-token-for-tests';

		$c = new Container();
		$c->register(new CachePoolProvider());
		$c->register(new GitHubProvider());

		// We can't easily introspect Github\Client to confirm the auth header was
		// added, but resolving the service must not throw.
		$this->assertInstanceOf(GitHubClient::class, $c['gitHub']);
	}
}
