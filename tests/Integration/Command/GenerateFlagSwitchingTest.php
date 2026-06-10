<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Generate;
use Akeeba\CoreSums\Container\Container;
use Akeeba\CoreSums\HttpFactory\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(Generate::class)]
final class GenerateFlagSwitchingTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	public function testInvocationWithoutVersionAndWithoutAllFlagReturnsError(): void
	{
		$db       = $this->makeDatabase();
		$this->prepareSchema($db);

		$generate = new Generate($db, new HttpFactory(new Container()));
		$exit     = $generate(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'',
			false,
			false
		);

		// Documented behavior in __invoke(): missing version + missing --all => error code 1.
		$this->assertSame(1, $exit);
	}

	public function testNewFlagSkipsVersionsThatAlreadyHaveChecksums(): void
	{
		$db = $this->makeDatabase();
		$this->prepareSchema($db);

		// Insert a source row with a URL that, if dereferenced, would blow up the test by trying
		// to fetch over the network. The --new flag must short-circuit before touching the URL.
		$this->insertSource($db, 'joomla', '5.0.0', 'https://example.invalid/never-fetched.zip');
		$this->insertChecksum($db, 'joomla', '5.0.0', 'index.php', 'PRE_EXISTING_MD5');

		$generate = new Generate($db, new HttpFactory(new Container()));
		$exit     = $generate(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'5.0.0',
			false,
			true // --new
		);

		$this->assertSame(0, $exit);

		// The row we pre-inserted must still be there and untouched.
		$row = $db->setQuery(
			'SELECT * FROM checksums WHERE cms = "joomla" AND version = "5.0.0" AND filename = "index.php"'
		)->loadAssoc();

		$this->assertNotNull($row);
		$this->assertSame('PRE_EXISTING_MD5', $row['md5']);
		$this->assertSame(
			1,
			(int) $db->setQuery(
				'SELECT COUNT(*) FROM checksums WHERE cms = "joomla" AND version = "5.0.0"'
			)->loadResult()
		);
	}

	public function testDefaultFlagsWarnWhenSourceHasNoDownloadUrl(): void
	{
		$db = $this->makeDatabase();
		$this->prepareSchema($db);

		// An "empty URL" source. Generate's getDownloadURL coalesces '' to null and bails with
		// a warning — no rows inserted, exit code is still 0.
		$this->insertSource($db, 'joomla', '5.0.0', '');

		$generate = new Generate($db, new HttpFactory(new Container()));
		$exit     = $generate(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'5.0.0',
			false,
			false
		);

		$this->assertSame(0, $exit);
		$this->assertSame(
			0,
			(int) $db->setQuery('SELECT COUNT(*) FROM checksums')->loadResult()
		);
	}

	public function testAllFlagIteratesEveryConfiguredVersion(): void
	{
		$db = $this->makeDatabase();
		$this->prepareSchema($db);

		// Three sources for joomla, one for wordpress (should not be touched).
		$this->insertSource($db, 'joomla', '4.0.0', '');
		$this->insertSource($db, 'joomla', '5.0.0', '');
		$this->insertSource($db, 'joomla', '5.1.0', '');
		$this->insertSource($db, 'wordpress', '6.4.0', '');

		// Pre-existing checksums for one joomla version. Without --new, Generate should NOT
		// skip them; it would attempt to re-download. Because all URLs are empty, it hits the
		// "no URL" warning branch for each and leaves the table alone.
		$this->insertChecksum($db, 'joomla', '5.0.0', 'foo.php', 'KEEP_ME');

		$generate = new Generate($db, new HttpFactory(new Container()));
		$exit     = $generate(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'',
			true,  // --all
			false
		);

		$this->assertSame(0, $exit);

		// NOTE: Generate::processVersion DELETES existing checksums for (cms, version) only
		// *after* it has successfully resolved a download URL. Because every URL here is empty,
		// the early-return preserves the row.
		$row = $db->setQuery(
			'SELECT md5 FROM checksums WHERE cms = "joomla" AND version = "5.0.0" AND filename = "foo.php"'
		)->loadResult();
		$this->assertSame('KEEP_ME', $row);

		// The wordpress row should be untouched regardless: --all only iterates the CMS passed
		// to __invoke (joomla here).
		$this->assertSame(
			0,
			(int) $db->setQuery('SELECT COUNT(*) FROM checksums WHERE cms = "wordpress"')->loadResult()
		);
	}

	public function testExplicitCmsVersionTargetsOnlyThatRow(): void
	{
		$db = $this->makeDatabase();
		$this->prepareSchema($db);

		// Two joomla versions; both have prior checksums.
		$this->insertSource($db, 'joomla', '5.0.0', '');
		$this->insertSource($db, 'joomla', '5.1.0', '');
		$this->insertChecksum($db, 'joomla', '5.0.0', 'a.php', 'A');
		$this->insertChecksum($db, 'joomla', '5.1.0', 'b.php', 'B');

		$generate = new Generate($db, new HttpFactory(new Container()));
		$exit     = $generate(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'5.0.0',
			false,
			true // --new => skip because checksums already exist for 5.0.0
		);

		$this->assertSame(0, $exit);

		// Neither version's checksums should have been touched (5.0.0 because --new skipped;
		// 5.1.0 because it wasn't requested).
		$this->assertSame(
			'A',
			$db->setQuery(
				'SELECT md5 FROM checksums WHERE cms = "joomla" AND version = "5.0.0" AND filename = "a.php"'
			)->loadResult()
		);
		$this->assertSame(
			'B',
			$db->setQuery(
				'SELECT md5 FROM checksums WHERE cms = "joomla" AND version = "5.1.0" AND filename = "b.php"'
			)->loadResult()
		);
	}

	private function prepareSchema(\Joomla\Database\DatabaseDriver $db): void
	{
		$schemaDir = realpath(__DIR__ . '/../../../assets');

		$db->setQuery(file_get_contents($schemaDir . '/sources.sql'))->execute();
		$db->setQuery(file_get_contents($schemaDir . '/checksums.sql'))->execute();
	}

	private function insertSource(\Joomla\Database\DatabaseDriver $db, string $cms, string $version, string $url): void
	{
		$row = (object) [
			'cms'     => $cms,
			'version' => $version,
			'url'     => $url,
		];

		$db->insertObject('sources', $row);
	}

	private function insertChecksum(
		\Joomla\Database\DatabaseDriver $db,
		string $cms,
		string $version,
		string $filename,
		string $md5
	): void
	{
		$row = (object) [
			'cms'           => $cms,
			'version'       => $version,
			'filename'      => $filename,
			'md5'           => $md5,
			'sha1'          => 'sha1_value',
			'sha256'        => 'sha256_value',
			'sha512'        => 'sha512_value',
			'md5_squash'    => 'md5sq_value',
			'sha1_squash'   => 'sha1sq_value',
			'sha256_squash' => 'sha256sq_value',
			'sha512_squash' => 'sha512sq_value',
		];

		$db->insertObject('checksums', $row);
	}
}
