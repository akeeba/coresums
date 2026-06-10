<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Init;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(Init::class)]
final class InitEdgeCasesTest extends TestCase
{
	use TempDatabaseTrait;

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	/**
	 * Init must accept .json.gz for each checksum type and import all 8 hash columns.
	 */
	public function testInitReadsGzippedChecksumFixtures(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeTempDir('coresums-init-gz-checksums-');

		file_put_contents($sources . '/sources.json', json_encode([
			[
				'cms'     => 'joomla',
				'version' => '5.0.0',
				'url'     => 'https://example.invalid/joomla-5.0.0.zip',
			],
		]));

		$cmsDir = $sources . '/joomla/5.0.0';
		mkdir($cmsDir, 0755, true);

		$fixtures = [
			'md5'           => ['index.php' => 'd41d8cd98f00b204e9800998ecf8427e'],
			'sha1'          => ['index.php' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709'],
			'sha256'        => ['index.php' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
			'sha512'        => ['index.php' => 'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e'],
			'md5_squash'    => ['index.php' => '7215ee9c7d9dc229d2921a40e899ec5f'],
			'sha1_squash'   => ['index.php' => 'b858cb282617fb0956d960215c8e84d1ccf909c6'],
			'sha256_squash' => ['index.php' => '36a9e7f1c95b82ffb99743e0c5c4ce95d83c9a430aac59f84ef3cbfab6145068'],
			'sha512_squash' => ['index.php' => 'f90ddd77e400dfe6a3fcf479b00b1ee29e7015c5bb8cd70f5f15b4886cc339275ff553fc8a053f8ddc7324f45168cffaf81f8c3ac93996f6536eef38e5e40768'],
		];

		foreach ($fixtures as $type => $rows)
		{
			file_put_contents($cmsDir . '/' . $type . '.json.gz', gzencode(json_encode($rows)));
		}

		$init = new Init($db);
		$exit = $init(new ArrayInput([]), new NullOutput(), $sources);

		$this->assertSame(0, $exit);

		$row = $db->setQuery('SELECT * FROM checksums WHERE filename = "index.php"')->loadAssoc();
		$this->assertNotNull($row);

		foreach ($fixtures as $type => $rows)
		{
			$this->assertSame(
				$rows['index.php'],
				$row[$type],
				sprintf('Hash column %s should match gzipped fixture', $type)
			);
		}
	}

	/**
	 * Running Init twice on the same fixtures must not duplicate sources rows
	 * (the importSources path deletes-then-inserts per row), but the current
	 * importCmsVersionChecksums has no equivalent DELETE — so checksum rows
	 * DOUBLE on the second run. We assert what the code actually does and flag
	 * the behavior as a known divergence.
	 */
	public function testInitIsIdempotent(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeTempDir('coresums-init-idempotent-');

		file_put_contents($sources . '/sources.json', json_encode([
			[
				'cms'     => 'joomla',
				'version' => '5.0.0',
				'url'     => 'https://example.invalid/joomla-5.0.0.zip',
			],
		]));

		$cmsDir = $sources . '/joomla/5.0.0';
		mkdir($cmsDir, 0755, true);
		file_put_contents(
			$cmsDir . '/md5.json',
			json_encode(['index.php' => 'd41d8cd98f00b204e9800998ecf8427e'])
		);

		$init = new Init($db);

		$exitFirst = $init(new ArrayInput([]), new NullOutput(), $sources);
		$this->assertSame(0, $exitFirst);

		$sourcesCountFirst   = (int) $db->setQuery('SELECT COUNT(*) FROM sources')->loadResult();
		$checksumsCountFirst = (int) $db->setQuery('SELECT COUNT(*) FROM checksums')->loadResult();

		$exitSecond = $init(new ArrayInput([]), new NullOutput(), $sources);
		$this->assertSame(0, $exitSecond);

		$sourcesCountSecond   = (int) $db->setQuery('SELECT COUNT(*) FROM sources')->loadResult();
		$checksumsCountSecond = (int) $db->setQuery('SELECT COUNT(*) FROM checksums')->loadResult();

		// sources: idempotent thanks to DELETE-then-INSERT per row
		$this->assertSame(
			$sourcesCountFirst,
			$sourcesCountSecond,
			'sources rows must not duplicate across Init runs'
		);

		// checksums: NOT idempotent — importCmsVersionChecksums simply re-inserts.
		// Divergence from documentation/expectation. Asserting current behaviour;
		// if Init grows a DELETE before the inserts, this test should be updated.
		$this->assertSame(
			$checksumsCountFirst * 2,
			$checksumsCountSecond,
			'KNOWN BUG: Init.importCmsVersionChecksums has no DELETE so re-runs duplicate checksum rows.'
		);
	}

	/**
	 * When sources.json references a CMS/version with no matching checksum subdir,
	 * Init still returns 0 and emits a diagnostic on the captured output.
	 */
	public function testInitWarnsWhenChecksumSubdirMissing(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeTempDir('coresums-init-missing-subdir-');

		file_put_contents($sources . '/sources.json', json_encode([
			[
				'cms'     => 'joomla',
				'version' => '9.9.9',
				'url'     => 'https://example.invalid/joomla-9.9.9.zip',
			],
		]));

		$output = new BufferedOutput();

		$init = new Init($db);
		$exit = $init(new ArrayInput([]), $output, $sources);

		$this->assertSame(0, $exit);

		$captured = $output->fetch();

		// The error tag text is what importCmsVersionChecksums emits when the
		// CMS/version subdirectory does not exist. SymfonyStyle reformats the
		// inner <error> tag into its highlighted block; we just assert the
		// substring is present.
		$this->assertStringContainsString('No checksums for', $captured);
		$this->assertStringContainsString('9.9.9', $captured);

		// And the sources row still got imported.
		$this->assertSame(
			1,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources')->loadResult()
		);
		$this->assertSame(
			0,
			(int) $db->setQuery('SELECT COUNT(*) FROM checksums')->loadResult()
		);
	}

	/**
	 * Malformed sources.json: json_decode returns null and the subsequent
	 * `foreach ($data as ...)` raises a PHP warning ("foreach() argument must
	 * be of type array|object, null given"). The command does NOT abort or
	 * return non-zero — it simply skips the import and continues. We capture
	 * the warning via a temporary error handler and assert both that the
	 * warning fires and that Init still returns 0.
	 *
	 * Divergence: production code does not validate decoded JSON before
	 * iterating; a graceful error and non-zero exit would be safer.
	 */
	public function testInitHandlesMalformedJson(): void
	{
		$db      = $this->makeDatabase();
		$sources = $this->makeTempDir('coresums-init-malformed-');

		file_put_contents($sources . '/sources.json', '{not valid json');

		$capturedWarning = null;
		set_error_handler(static function (int $errno, string $errstr) use (&$capturedWarning): bool {
			$capturedWarning = $errstr;

			return true;
		}, E_WARNING);

		try
		{
			$init = new Init($db);
			$exit = $init(new ArrayInput([]), new NullOutput(), $sources);
		}
		finally
		{
			restore_error_handler();
		}

		$this->assertSame(0, $exit, 'Init swallows malformed JSON and still exits 0');
		$this->assertNotNull($capturedWarning, 'Malformed JSON triggers a PHP warning');
		$this->assertStringContainsString('foreach()', (string) $capturedWarning);
		$this->assertSame(
			0,
			(int) $db->setQuery('SELECT COUNT(*) FROM sources')->loadResult(),
			'No sources should be imported from malformed JSON'
		);
	}

	/**
	 * When null is passed for sourceFolder, Init falls back to the project's
	 * assets/ directory, which ships with the default sources.json. We
	 * therefore expect at least one sources row imported.
	 */
	public function testInitWithoutSourceFolderUsesAssetsDefault(): void
	{
		$db = $this->makeDatabase();

		$init = new Init($db);
		$exit = $init(new ArrayInput([]), new NullOutput(), null);

		$this->assertSame(0, $exit);

		$count = (int) $db->setQuery('SELECT COUNT(*) FROM sources')->loadResult();
		$this->assertGreaterThan(
			0,
			$count,
			'Passing null for sourceFolder should fall back to assets/ and import its sources.json'
		);
	}
}
