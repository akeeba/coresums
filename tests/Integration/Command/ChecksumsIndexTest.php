<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Command\Init;
use Akeeba\CoreSums\Command\Sources;
use Akeeba\CoreSums\Container\Container;
use Akeeba\CoreSums\Database\Schema;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use Github\Api\Repo;
use Github\Api\Repository\Releases;
use Github\Client as GitHubClient;
use Joomla\Database\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The production database is 2.8 GB. Without an index on (cms, version), every per-version query is a
 * full table scan; on the production host each dump file took ~4.4 minutes, which is what made the nightly run too
 * long to finish (GitHub issue #2).
 */
#[CoversClass(Schema::class)]
#[CoversClass(Init::class)]
#[CoversClass(Dump::class)]
#[CoversClass(Generate::class)]
#[CoversClass(Sources::class)]
final class ChecksumsIndexTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	public function testEnsureIndexesMakesPerVersionQueriesUseTheIndex(): void
	{
		$db = $this->makeLegacyDatabase();

		Schema::ensureIndexes($db);

		$plan = $this->queryPlan($db);

		$this->assertStringContainsString('USING INDEX', $plan);
		$this->assertStringNotContainsString('SCAN checksums', $plan);
		$this->assertStringNotContainsString('TEMP B-TREE', $plan, 'The index must also cover ORDER BY filename');
	}

	public function testEnsureIndexesIsIdempotent(): void
	{
		$db = $this->makeLegacyDatabase();

		Schema::ensureIndexes($db);
		Schema::ensureIndexes($db);

		$this->assertTrue($this->hasIndex($db));
	}

	public function testEnsureIndexesDoesNothingWithoutChecksumsTable(): void
	{
		$db = $this->makeDatabase();

		Schema::ensureIndexes($db);

		$this->assertFalse($this->hasIndex($db));
	}

	public function testInitCreatesTheIndex(): void
	{
		$db         = $this->makeDatabase();
		$emptyInput = $this->makeTempDir('coresums-index-init-');

		(new Init($db))(new ArrayInput([]), new NullOutput(), $emptyInput);

		$this->assertTrue($this->hasIndex($db));
	}

	public function testDumpAddsTheIndexToAnExistingDatabase(): void
	{
		$db     = $this->makeLegacyDatabase();
		$outDir = $this->makeTempDir('coresums-index-dump-');

		(new Dump($db))(new ArrayInput([]), new NullOutput(), $outDir, true, false, false);

		$this->assertTrue($this->hasIndex($db));
	}

	public function testGenerateAddsTheIndexToAnExistingDatabase(): void
	{
		$db = $this->makeLegacyDatabase();

		// Version without a source row: Generate warns and returns without any download.
		(new Generate($db, new HttpFactory(new Container())))(
			new ArrayInput([]), new NullOutput(), 'joomla', '9.9.9', false, true
		);

		$this->assertTrue($this->hasIndex($db));
	}

	public function testSourcesAddsTheIndexBeforeLookingForChecksums(): void
	{
		$db = $this->makeLegacyDatabase();

		$releases = $this->createMock(Releases::class);
		$releases->method('all')->willReturn([['tag_name' => '5.0.0', 'assets' => []]]);
		$repoApi = $this->createMock(Repo::class);
		$repoApi->method('releases')->willReturn($releases);
		$gitHub = $this->createMock(GitHubClient::class);
		$gitHub->method('api')->willReturn($repoApi);

		$sources = new Sources(
			$db, $this->createMock(HttpFactory::class), $gitHub, $this->createMock(Generate::class),
			$this->createMock(Dump::class)
		);
		$sources(new ArrayInput([]), new NullOutput(), latest: true, process: true, wordpress: false);

		$this->assertTrue($this->hasIndex($db));
	}

	/**
	 * A database created by the pre-index schema, as on the production server.
	 */
	private function makeLegacyDatabase(): DatabaseDriver
	{
		$db = $this->makeDatabase();

		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/sources.sql'))->execute();
		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/checksums.sql'))->execute();

		return $db;
	}

	private function hasIndex(DatabaseDriver $db): bool
	{
		$count = $db->setQuery(
			"SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = 'checksums'"
		)->loadResult();

		return (int) $count > 0;
	}

	private function queryPlan(DatabaseDriver $db): string
	{
		$rows = $db->setQuery(
			"EXPLAIN QUERY PLAN SELECT filename, sha256 FROM checksums"
			. " WHERE cms = 'joomla' AND version = '5.0.0' ORDER BY filename ASC"
		)->loadAssocList();

		return implode("\n", array_column($rows, 'detail'));
	}
}
