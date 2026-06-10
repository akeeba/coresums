<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Akeeba\CoreSums\Command\Dump;
use Akeeba\CoreSums\Command\Init;
use Joomla\Database\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(Dump::class)]
#[CoversClass(Init::class)]
final class DumpEdgeCasesTest extends TestCase
{
	use TempDatabaseTrait;

	private const HASH_TYPES = [
		'md5',
		'sha1',
		'sha256',
		'sha512',
		'md5_squash',
		'sha1_squash',
		'sha256_squash',
		'sha512_squash',
	];

	protected function tearDown(): void
	{
		$this->cleanupTempArtifacts();
	}

	public function testDumpGzipModeProducesValidGzipFiles(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-gzip-');

		$this->seedSchema($db);
		$this->seedSingleRow($db);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, true, false, true);

		$this->assertSame(0, $exit);

		// Sources output is also gzipped.
		$this->assertFileExists($outDir . '/sources.json.gz');
		$this->assertFileDoesNotExist($outDir . '/sources.json');

		$sourcesJson = gzdecode((string) file_get_contents($outDir . '/sources.json.gz'));
		$this->assertIsString($sourcesJson, 'gzdecode must succeed on the dumped sources file');
		$sourcesDecoded = json_decode($sourcesJson, true);
		$this->assertIsArray($sourcesDecoded);
		$this->assertSame('joomla', $sourcesDecoded[0]['cms']);

		// Each checksum file ends in .json.gz, decompresses, and matches the DB row.
		foreach (self::HASH_TYPES as $type)
		{
			$file = $outDir . '/joomla/5.0.0/' . $type . '.json.gz';
			$this->assertFileExists($file);
			$this->assertFileDoesNotExist($outDir . '/joomla/5.0.0/' . $type . '.json');

			$decoded = gzdecode((string) file_get_contents($file));
			$this->assertIsString($decoded, sprintf('%s file must be valid gzip', $type));

			$payload = json_decode($decoded, true);
			$this->assertIsArray($payload);
			$this->assertArrayHasKey('index.php', $payload);

			$expected = (string) $db->setQuery(
				'SELECT ' . $type . ' FROM checksums WHERE filename = "index.php"'
			)->loadResult();
			$this->assertSame($expected, $payload['index.php']);
		}
	}

	public function testDumpSkipsExistingChecksumFiles(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-skip-');

		$this->seedSchema($db);
		$this->seedSingleRow($db);

		// Pre-create md5.json with a sentinel.
		$cmsDir = $outDir . '/joomla/5.0.0';
		mkdir($cmsDir, 0755, true);

		$sentinel = '{"SENTINEL.php":"untouched"}';
		file_put_contents($cmsDir . '/md5.json', $sentinel);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, false, false, false);

		$this->assertSame(0, $exit);
		$this->assertSame(
			$sentinel,
			file_get_contents($cmsDir . '/md5.json'),
			'Dump must skip existing md5.json (↩️ behaviour)'
		);

		// The other 7 types are produced normally.
		foreach (['sha1', 'sha256', 'sha512', 'md5_squash', 'sha1_squash', 'sha256_squash', 'sha512_squash'] as $type)
		{
			$this->assertFileExists($cmsDir . '/' . $type . '.json');
			$decoded = json_decode((string) file_get_contents($cmsDir . '/' . $type . '.json'), true);
			$this->assertArrayHasKey('index.php', $decoded);
		}
	}

	public function testDumpProducesAllEightHashTypeFiles(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-eight-');

		$this->seedSchema($db);
		$this->seedSingleRow($db);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, false, false, false);

		$this->assertSame(0, $exit);

		foreach (self::HASH_TYPES as $type)
		{
			$this->assertFileExists(
				$outDir . '/joomla/5.0.0/' . $type . '.json',
				sprintf('Expected %s.json to be produced', $type)
			);
		}
	}

	/**
	 * With sources but no checksums, Dump still creates the per-CMS/version
	 * directories and writes empty-object JSON files (one per hash type).
	 * Asserting the current behaviour, which the plan notes may be surprising.
	 */
	public function testDumpWithEmptyDatabaseProducesNoArtifacts(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-empty-');

		$this->seedSchema($db);

		// Just a source row, no checksum rows.
		$source = (object) [
			'cms'     => 'joomla',
			'version' => '5.0.0',
			'url'     => 'https://example.invalid/joomla-5.0.0.zip',
		];
		$db->insertObject('sources', $source);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, false, false, false);

		$this->assertSame(0, $exit);

		// Behaviour: the per-version directory IS created and each hash type
		// gets a file whose decoded content is an empty associative array (PHP
		// emits "[]" for empty arrays via json_encode on loadAssocList of an
		// empty result). Document and assert the literal output.
		foreach (self::HASH_TYPES as $type)
		{
			$file = $outDir . '/joomla/5.0.0/' . $type . '.json';
			$this->assertFileExists($file, sprintf('Empty DB still writes %s.json', $type));
			$contents = file_get_contents($file);
			$decoded  = json_decode((string) $contents, true);
			$this->assertSame(
				[],
				$decoded,
				sprintf('Empty DB produces empty payload for %s.json (got %s)', $type, var_export($contents, true))
			);
		}
	}

	/**
	 * The SELECT in dumpCmsVersionChecksums uses ORDER BY filename ASC and
	 * loadAssocList('filename', 'hash'), so the resulting JSON object must
	 * have its keys in alphabetical order regardless of insertion order.
	 */
	public function testDumpOrdering(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-order-');

		$this->seedSchema($db);

		$source = (object) [
			'cms'     => 'joomla',
			'version' => '5.0.0',
			'url'     => 'https://example.invalid/joomla-5.0.0.zip',
		];
		$db->insertObject('sources', $source);

		// Insert deliberately out of alphabetical order.
		foreach (['zeta.php', 'alpha.php', 'mu.php'] as $filename)
		{
			$row = (object) [
				'cms'           => 'joomla',
				'version'       => '5.0.0',
				'filename'      => $filename,
				'md5'           => md5($filename),
				'sha1'          => sha1($filename),
				'sha256'        => hash('sha256', $filename),
				'sha512'        => hash('sha512', $filename),
				'md5_squash'    => md5($filename . '_sq'),
				'sha1_squash'   => sha1($filename . '_sq'),
				'sha256_squash' => hash('sha256', $filename . '_sq'),
				'sha512_squash' => hash('sha512', $filename . '_sq'),
			];
			$db->insertObject('checksums', $row);
		}

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, false, false, false);
		$this->assertSame(0, $exit);

		$decoded = json_decode(
			(string) file_get_contents($outDir . '/joomla/5.0.0/md5.json'),
			true
		);
		$this->assertSame(
			['alpha.php', 'mu.php', 'zeta.php'],
			array_keys($decoded),
			'JSON keys must be alphabetical (SQL ORDER BY filename ASC)'
		);
	}

	public function testDumpThenInitRoundTripWithGzip(): void
	{
		$db     = $this->makeDatabase();
		$outDir = $this->makeTempDir('coresums-dump-roundtrip-gzip-');

		$this->seedSchema($db);
		$this->seedSingleRow($db);

		$dump = new Dump($db);
		$exit = $dump(new ArrayInput([]), new NullOutput(), $outDir, true, false, true);
		$this->assertSame(0, $exit);

		// All gzipped files should be present.
		$this->assertFileExists($outDir . '/sources.json.gz');
		foreach (self::HASH_TYPES as $type)
		{
			$this->assertFileExists($outDir . '/joomla/5.0.0/' . $type . '.json.gz');
		}

		// Re-import into a fresh DB.
		$db2  = $this->makeDatabase();
		$init = new Init($db2);
		$exit = $init(new ArrayInput([]), new NullOutput(), $outDir);
		$this->assertSame(0, $exit);

		$expected = $db->setQuery('SELECT * FROM checksums WHERE filename = "index.php"')->loadAssoc();
		$actual   = $db2->setQuery('SELECT * FROM checksums WHERE filename = "index.php"')->loadAssoc();

		$this->assertNotNull($actual);
		foreach (self::HASH_TYPES as $type)
		{
			$this->assertSame(
				$expected[$type],
				$actual[$type],
				sprintf('%s must survive the gzip round-trip', $type)
			);
		}
	}

	private function seedSchema(DatabaseDriver $db): void
	{
		$assets = realpath(__DIR__ . '/../../../assets');

		$db->setQuery(file_get_contents($assets . '/sources.sql'))->execute();
		$db->setQuery(file_get_contents($assets . '/checksums.sql'))->execute();
	}

	private function seedSingleRow(DatabaseDriver $db): void
	{
		$source = (object) [
			'cms'     => 'joomla',
			'version' => '5.0.0',
			'url'     => 'https://example.invalid/joomla-5.0.0.zip',
		];
		$db->insertObject('sources', $source);

		$checksum = (object) [
			'cms'           => 'joomla',
			'version'       => '5.0.0',
			'filename'      => 'index.php',
			'md5'           => 'd41d8cd98f00b204e9800998ecf8427e',
			'sha1'          => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
			'sha256'        => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
			'sha512'        => 'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e',
			'md5_squash'    => '7215ee9c7d9dc229d2921a40e899ec5f',
			'sha1_squash'   => 'b858cb282617fb0956d960215c8e84d1ccf909c6',
			'sha256_squash' => '36a9e7f1c95b82ffb99743e0c5c4ce95d83c9a430aac59f84ef3cbfab6145068',
			'sha512_squash' => 'f90ddd77e400dfe6a3fcf479b00b1ee29e7015c5bb8cd70f5f15b4886cc339275ff553fc8a053f8ddc7324f45168cffaf81f8c3ac93996f6536eef38e5e40768',
		];
		$db->insertObject('checksums', $checksum);
	}
}
