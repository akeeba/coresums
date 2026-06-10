<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Unit\Command;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Command\Sources;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Akeeba\CoreSums\Tests\Integration\Command\TempDatabaseTrait;
use Github\Client as GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(Sources::class)]
final class SourcesUpdateSourceTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	private function makeSources(\Joomla\Database\DatabaseDriver $db): Sources
	{
		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/sources.sql'))->execute();

		return new Sources(
			$db,
			$this->createMock(HttpFactory::class),
			$this->createMock(GitHubClient::class),
			$this->createMock(Generate::class),
			$this->createMock(Dump::class)
		);
	}

	private function invokeUpdateSource(Sources $sources, string $cms, string $version, string $url): bool
	{
		$method = new ReflectionMethod($sources, 'updateSource');

		return $method->invoke($sources, $cms, $version, $url);
	}

	public function testInsertWhenCmsVersionIsNew(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeSources($db);

		$changed = $this->invokeUpdateSource($sources, 'joomla', '5.0.0', 'https://example.invalid/a.zip');

		$this->assertTrue($changed);
		$this->assertSame(
			'https://example.invalid/a.zip',
			$db->setQuery('SELECT url FROM sources WHERE cms="joomla" AND version="5.0.0"')->loadResult()
		);
	}

	public function testNoChangeWhenSameUrlAlreadyStored(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeSources($db);

		$this->invokeUpdateSource($sources, 'joomla', '5.0.0', 'https://example.invalid/a.zip');
		$changed = $this->invokeUpdateSource($sources, 'joomla', '5.0.0', 'https://example.invalid/a.zip');

		$this->assertFalse($changed);
		$this->assertSame(
			1,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources WHERE cms="joomla" AND version="5.0.0"')->loadResult()
		);
	}

	public function testUpdateReplacesUrlWhenDifferent(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeSources($db);

		$this->invokeUpdateSource($sources, 'joomla', '5.0.0', 'https://example.invalid/a.zip');
		$changed = $this->invokeUpdateSource($sources, 'joomla', '5.0.0', 'https://example.invalid/b.zip');

		$this->assertTrue($changed);
		$this->assertSame(
			'https://example.invalid/b.zip',
			$db->setQuery('SELECT url FROM sources WHERE cms="joomla" AND version="5.0.0"')->loadResult()
		);
		$this->assertSame(
			1,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources WHERE cms="joomla" AND version="5.0.0"')->loadResult()
		);
	}

	public function testDifferentCmsKeepsSeparateRow(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeSources($db);

		$this->invokeUpdateSource($sources, 'joomla', '5.0.0', 'https://example.invalid/j.zip');
		$this->invokeUpdateSource($sources, 'wordpress', '5.0.0', 'https://example.invalid/w.zip');

		$this->assertSame(
			2,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources')->loadResult()
		);
	}
}
