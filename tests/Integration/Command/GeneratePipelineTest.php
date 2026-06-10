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
use Akeeba\CoreSums\Tests\Fixtures\FixtureBuilder;
use Joomla\Database\Sqlite\SqliteDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * End-to-end tests for {@see Generate} using golden fixture archives.
 *
 * Strategy: pre-stage the fixture archive at the exact temp-file path that
 * {@see Generate::processVersion()} would download to (md5($downloadUrl) under
 * the project's tmp/ directory). Generate will then short-circuit the download
 * (via the `file_exists($tempFile)` branch) and proceed straight to extracting
 * and hashing — so no HTTP client is ever invoked.
 */
#[CoversClass(Generate::class)]
final class GeneratePipelineTest extends TestCase
{
	use TempDatabaseTrait;

	private const PROJECT_TMP = __DIR__ . '/../../../tmp';

	private const JOOMLA_URL = 'https://fixtures.invalid/joomla-1.0.0.tar.gz';
	private const WP_URL     = 'https://fixtures.invalid/wordpress-6.0.0.zip';

	/** @var string[] Tmp staging files to clean up in tearDown. */
	private array $stagedTmpFiles = [];

	public static function setUpBeforeClass(): void
	{
		require_once __DIR__ . '/../../fixtures/build-fixtures.php';

		FixtureBuilder::buildAll(__DIR__ . '/../../fixtures/archives');

		// Generate uses realpath(__DIR__ . '/../../tmp') which fails if the
		// directory doesn't exist. Ensure it does.
		if (!is_dir(self::PROJECT_TMP))
		{
			mkdir(self::PROJECT_TMP, 0755, true);
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->stagedTmpFiles as $path)
		{
			if (file_exists($path))
			{
				@unlink($path);
			}
		}
		$this->stagedTmpFiles = [];

		$this->cleanupTempArtifacts();
	}

	public function testGenerateJoomlaArchiveProducesGoldenHashes(): void
	{
		$db = $this->makeReadyDatabase();
		$this->insertSource($db, 'joomla', '1.0.0', self::JOOMLA_URL);
		$this->stageArchive(self::JOOMLA_URL, __DIR__ . '/../../fixtures/archives/joomla-fixture-1.0.0.tar.gz');

		$exit = $this->makeGenerate($db)(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'1.0.0'
		);

		$this->assertSame(0, $exit);

		// Excluded files (not in IMPORTANT_EXTENSIONS allowlist) must not be persisted.
		$this->assertChecksumAbsent($db, 'joomla', '1.0.0', 'configuration.php-dist');
		$this->assertChecksumAbsent($db, 'joomla', '1.0.0', 'README.txt');
		$this->assertChecksumAbsent($db, 'joomla', '1.0.0', 'images/logo.png');

		foreach ($this->joomlaGoldenHashes() as $filename => $expected)
		{
			$this->assertChecksumRow($db, 'joomla', '1.0.0', $filename, $expected);
		}
	}

	public function testGenerateWordPressArchiveStripsTopLevelPrefix(): void
	{
		$db = $this->makeReadyDatabase();
		$this->insertSource($db, 'wordpress', '6.0.0', self::WP_URL);
		$this->stageArchive(self::WP_URL, __DIR__ . '/../../fixtures/archives/wordpress-fixture-6.0.0.zip');

		$exit = $this->makeGenerate($db)(
			new ArrayInput([]),
			new NullOutput(),
			'wordpress',
			'6.0.0'
		);

		$this->assertSame(0, $exit);

		$filenames = $db
			->setQuery('SELECT filename FROM checksums WHERE cms = "wordpress" AND version = "6.0.0"')
			->loadColumn();

		// Explicit assertion that commit 19812af's prefix stripping happened.
		foreach ($filenames as $filename)
		{
			$this->assertStringStartsNotWith(
				'wordpress/',
				$filename,
				"Filename '$filename' still carries the wordpress/ prefix"
			);
		}

		// .html files are excluded by extension filter.
		$this->assertChecksumAbsent($db, 'wordpress', '6.0.0', 'readme.html');
		$this->assertChecksumAbsent($db, 'wordpress', '6.0.0', 'wordpress/readme.html');

		foreach ($this->wordpressGoldenHashes() as $filename => $expected)
		{
			$this->assertChecksumRow($db, 'wordpress', '6.0.0', $filename, $expected);
		}
	}

	public function testGenerateSkipsAlreadyProcessedVersionUnderNewFlag(): void
	{
		$db = $this->makeReadyDatabase();
		$this->insertSource($db, 'joomla', '1.0.0', self::JOOMLA_URL);

		// Pre-insert a sentinel row.
		$sentinel = (object) [
			'cms'           => 'joomla',
			'version'       => '1.0.0',
			'filename'      => 'sentinel.php',
			'md5'           => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'sha1'          => str_repeat('a', 40),
			'sha256'        => str_repeat('a', 64),
			'sha512'        => str_repeat('a', 128),
			'md5_squash'    => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
			'sha1_squash'   => str_repeat('b', 40),
			'sha256_squash' => str_repeat('b', 64),
			'sha512_squash' => str_repeat('b', 128),
		];
		$db->insertObject('checksums', $sentinel);

		// Deliberately do NOT stage the archive: if Generate tries to process,
		// it will fail loudly (no HTTP client available, no archive file).
		$exit = $this->makeGenerate($db)(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'1.0.0',
			false,
			true
		);

		$this->assertSame(0, $exit);

		$rows = $db
			->setQuery('SELECT filename FROM checksums WHERE cms = "joomla" AND version = "1.0.0"')
			->loadColumn();

		$this->assertSame(['sentinel.php'], $rows, 'Sentinel row must be untouched and no new rows added.');
	}

	public function testGenerateReprocessesUnderNonNewRun(): void
	{
		$db = $this->makeReadyDatabase();
		$this->insertSource($db, 'joomla', '1.0.0', self::JOOMLA_URL);
		$this->stageArchive(self::JOOMLA_URL, __DIR__ . '/../../fixtures/archives/joomla-fixture-1.0.0.tar.gz');

		// Pre-insert a stale row that Generate must purge before re-writing.
		$stale = (object) [
			'cms'           => 'joomla',
			'version'       => '1.0.0',
			'filename'      => 'stale.php',
			'md5'           => str_repeat('a', 32),
			'sha1'          => str_repeat('a', 40),
			'sha256'        => str_repeat('a', 64),
			'sha512'        => str_repeat('a', 128),
			'md5_squash'    => str_repeat('b', 32),
			'sha1_squash'   => str_repeat('b', 40),
			'sha256_squash' => str_repeat('b', 64),
			'sha512_squash' => str_repeat('b', 128),
		];
		$db->insertObject('checksums', $stale);

		// $new = false: Generate.php deletes all rows for this (cms, version)
		// before writing new ones. The plan calls this the "--all"/replace
		// semantics; reading processVersion() confirms the DELETE happens
		// unconditionally when $new is false (regardless of $all).
		$exit = $this->makeGenerate($db)(
			new ArrayInput([]),
			new NullOutput(),
			'joomla',
			'1.0.0',
			false,
			false
		);

		$this->assertSame(0, $exit);

		$this->assertChecksumAbsent($db, 'joomla', '1.0.0', 'stale.php');

		// And the golden rows from the fixture must be present.
		$expected = $this->joomlaGoldenHashes();
		$row      = $db
			->setQuery('SELECT md5 FROM checksums WHERE cms = "joomla" AND version = "1.0.0" AND filename = "index.php"')
			->loadResult();
		$this->assertSame($expected['index.php']['md5'], $row);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function makeReadyDatabase(): SqliteDriver
	{
		$db = $this->makeDatabase();

		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/sources.sql'))->execute();
		$db->setQuery(file_get_contents(__DIR__ . '/../../../assets/checksums.sql'))->execute();

		return $db;
	}

	private function insertSource(SqliteDriver $db, string $cms, string $version, string $url): void
	{
		// Joomla\Database\DatabaseDriver::insertObject takes its second arg by reference.
		$object = (object) [
			'cms'     => $cms,
			'version' => $version,
			'url'     => $url,
		];
		$db->insertObject('sources', $object);
	}

	private function stageArchive(string $url, string $sourcePath): void
	{
		$tmpDir   = realpath(self::PROJECT_TMP);
		$tempFile = $tmpDir . DIRECTORY_SEPARATOR . md5($url);

		copy($sourcePath, $tempFile);

		// Generate unlinks the temp file on success — but if it doesn't reach
		// that point we still want to clean up.
		$this->stagedTmpFiles[] = $tempFile;
	}

	private function makeGenerate(SqliteDriver $db): Generate
	{
		// We never invoke the HTTP client because archives are pre-staged.
		// The HttpFactory is wired through but inert.
		$httpFactory = new HttpFactory(new Container());

		return new Generate($db, $httpFactory);
	}

	private function assertChecksumAbsent(SqliteDriver $db, string $cms, string $version, string $filename): void
	{
		$count = (int) $db
			->setQuery(
				'SELECT COUNT(*) FROM checksums WHERE cms = '
				. $db->quote($cms) . ' AND version = ' . $db->quote($version)
				. ' AND filename = ' . $db->quote($filename)
			)
			->loadResult();

		$this->assertSame(0, $count, "Expected no checksum row for '$filename'.");
	}

	private function assertChecksumRow(SqliteDriver $db, string $cms, string $version, string $filename, array $expected): void
	{
		$row = $db
			->setQuery(
				'SELECT * FROM checksums WHERE cms = '
				. $db->quote($cms) . ' AND version = ' . $db->quote($version)
				. ' AND filename = ' . $db->quote($filename)
			)
			->loadAssoc();

		$this->assertNotNull($row, "Missing checksum row for '$filename'");

		foreach ($expected as $col => $hash)
		{
			$this->assertSame(
				$hash,
				$row[$col],
				"Mismatched $col for '$filename'"
			);
		}
	}

	/**
	 * Golden hashes for the Joomla fixture. Computed once with the contents
	 * defined in FixtureBuilder::joomlaFiles(); regenerate if those change.
	 */
	private function joomlaGoldenHashes(): array
	{
		return [
			'index.php' => [
				'md5'           => '6b4d45c44c19566326e9ff62654f1d7e',
				'sha1'          => '5d05e69a0d73d45f8cc769987fd3cd0e52420091',
				'sha256'        => '0721205375d448ec7a9084cdf74bff4ecf5f56f7f327357b5f94d19b1b6ce172',
				'sha512'        => '9873262a4739df77ef0dcedf9bad34c478b1f94b9ce7737b1843799d006de40bf41a9ed0e4b3a902f57abfc7cafd3d2b0d88b246d0cbdaf742c48d72350d5a00',
				'md5_squash'    => '382ea839258fe32cd0c84a77842b80e7',
				'sha1_squash'   => '79567b88ee15d4da54e2ffc2badfee42b28d704d',
				'sha256_squash' => 'a54d063a47a1c34cb9272cbb3ce17b09f14cdca567215eb9a2c207fee756ed27',
				'sha512_squash' => '8787f3c3123d26f9f38e69366a7ad5f54208a2cf0e340624e13c61e57dcd889224ab80a7116849de1a36a7d635469ffd91ebbff97a777e14980100f294901a27',
			],
			'administrator/index.php' => [
				'md5'           => 'e5193636907f6970b98e5c81541a393d',
				'sha1'          => '9c3feaba49d11aaf79c261d1765b7a028611001e',
				'sha256'        => '38de3f0909bc8ab06e564f775673aaf02784901fbd985bb2b65ea1b383aca703',
				'sha512'        => 'b2615e42d6c22fef0fa7076eb7eeaae87c2987ae74d888d0c0f9135c3953ee9eb1cb05f1d82dfecdf6f10134f8cf155db5a25b3beaf17eecd598207fd28fe7a3',
				'md5_squash'    => 'adb6bc18f2a15ac78c18050b33b5bb36',
				'sha1_squash'   => '1e5e870abce824656381f6e8174b15541f36f070',
				'sha256_squash' => '82a5424482af55248c5af5ad5159166b08cefcb3665215409a8cce045c2011f9',
				'sha512_squash' => '511b630097c990401b3a507939b27a6c2e9bab7b08c484da9cea02e33053f5e014cfc885f2b6077473f699e2d8da78de2f66e7ce4e3eef07f166ec5ce8765123',
			],
			'language/en-GB/en-GB.ini' => [
				'md5'           => '61b5de6481118e6244d153809d5a8380',
				'sha1'          => 'a6a62a6be96ca413542befecc2757f98fe03dfe5',
				'sha256'        => '585c8bdeed9d06d7c72b9ad47025b5d50ca697483f4618ced2659e31b326a4d9',
				'sha512'        => '912c4d41ac84163fbb20109fde34fc50953c2fe0181ed2867999f1bcfbcdef35d6af8828c0eab1365988c7cb3ec3946262182faa7ea8a084c754b0c49545eb2a',
				'md5_squash'    => '58dfe6a85f4a062fa0c54c318800ecf8',
				'sha1_squash'   => '59b5e107d69ca2a97b0ad954a00cec647d1ea44a',
				'sha256_squash' => '89b6b85bec0d2fc397f97253d34d5434ca729882fed38b65d0a012393398ec81',
				'sha512_squash' => 'b376ec59476d2f86d755b2cb2d38def33e2d19e6d3ef0075a986457e2f8750155c66b8bed74e486dbb76c2864849f59e5d446df69f5a0f64ed5edfdb6380cef7',
			],
			'media/system/js/core.js' => [
				'md5'           => 'f20372f6903e89fbd11fb2d2684922d0',
				'sha1'          => '0529476a76bde9200966a610f33d5335e6da8722',
				'sha256'        => 'b9a604979c9b2929d86fca2e07b2af1d125ab5e9de226bda1ed91caa417724d3',
				'sha512'        => '68cb9c46b55bc88534240c5ca2bf648dc3b4b5b79f25ae7d165264dd7670afb16228b57c2e25197442b9b9068dd8129bdc83f26163178d784e7b684f87e9a72e',
				'md5_squash'    => 'a55e7c7d85e524bf3e1d32928cd69acd',
				'sha1_squash'   => 'c4c4b44c8798bbf5298cb34a0bc58c813b61514a',
				'sha256_squash' => '21747fd3150a651bd8419486e87833ba78da5a8a4e63b5c72c6f4d3a13a3420a',
				'sha512_squash' => '502e24fd588a653394e3c904246e58ca545aa055d48409fe559a3fe100e2ce597bd8b6e6b281255ea4c9fc8aada9c59950d6d16984fa1517ed49d0ddceae6f82',
			],
			'templates/cassiopeia/templateDetails.xml' => [
				'md5'           => 'dff6a32aa7012cbd39161dcccde31bbb',
				'sha1'          => '9e6c830bed5b8ab4e040a3a453e64a92c1373962',
				'sha256'        => 'ee56fa1f3c0c5189412ca32e6c988c4f313c350a0bde00888d846bae5c65b65d',
				'sha512'        => 'e1f523b2db0455ac14380f64f7fa9227448489873412576aa46c8e69c118c5d4e94c09fd0359b13922d7f143ec80482b12ac97e0b0d2abaaf98aaffd3791b17f',
				'md5_squash'    => 'dff6a32aa7012cbd39161dcccde31bbb',
				'sha1_squash'   => '9e6c830bed5b8ab4e040a3a453e64a92c1373962',
				'sha256_squash' => 'ee56fa1f3c0c5189412ca32e6c988c4f313c350a0bde00888d846bae5c65b65d',
				'sha512_squash' => 'e1f523b2db0455ac14380f64f7fa9227448489873412576aa46c8e69c118c5d4e94c09fd0359b13922d7f143ec80482b12ac97e0b0d2abaaf98aaffd3791b17f',
			],
		];
	}

	/**
	 * Golden hashes for the WordPress fixture. Keys are the post-strip
	 * filenames (no `wordpress/` prefix) — that is what Generate persists.
	 */
	private function wordpressGoldenHashes(): array
	{
		return [
			'index.php' => [
				'md5'           => '2afd37fb65ad16f0a59c7350657b9c1e',
				'sha1'          => '4088bfd347a34406e0e81bed659d08a4f069c911',
				'sha256'        => '84ec92b568cb63c94da7aeb61012fcbfcc3e771afd8c00b29cd6b59ed30f328c',
				'sha512'        => 'd263a6f8b02b58575a9d8e29601d02279219510623bbe078655be6a276b9a6420804f3b23eb0759ed3367f8f3121f526ef0297f4f0ec84e30d9e3b9acbb70fac',
				'md5_squash'    => '325280db1ee9615a03e9a37c350f1513',
				'sha1_squash'   => 'c4a55eb6ff7cb9ff6fb2bce19f99b9e3e2b7b975',
				'sha256_squash' => '95d41782218758c7d43423524638d352f53b1672e5f5f0a8a0e90269cbd354e0',
				'sha512_squash' => 'af1ac2b585f8837bb08afc175895c6d83851754f99da18225a7ea8f778d22ba5b55e34f8f5552358184d5386c92d9116d0cf1c66a0e0d3c3c66f2e4beb30da05',
			],
			'wp-includes/version.php' => [
				'md5'           => '8af8a1cecb05180be48e58f847474ee9',
				'sha1'          => '7a20e3cefcf9c27f68af20e8532a498fc446d094',
				'sha256'        => 'c2c8e9f35398acb885b1d6eb74e83b376a1d52fb8fa74c81bba4e94c149ca635',
				'sha512'        => '0bac719f6c3f4572fa516cffb1e7d2c2b64210c21b8b9201bdbb528466518543cabc05de432760c39c7447837dd7ae3beea0ca523d139c954f975e4f903c7fbc',
				'md5_squash'    => 'ca062cff61bcf7979d056cffabc35ceb',
				'sha1_squash'   => 'ab581c2596241906bf6d07b5260d5cf6ecfba639',
				'sha256_squash' => 'a4d9114d09428ce2c5509577e4320ba90b6532edc7e23d88b8e9cc5e0f80f7bd',
				'sha512_squash' => '8f687ec40ad80b434a787be9ebb11a9bf3ea38059ff92ce4bfe2b845104d636d170cbe56f616079885e0db490d36a47622b27f27ae9ebf5790325ddc96c55450',
			],
			'wp-config-sample.php' => [
				'md5'           => 'b726e0e77b3ade3f3196395a4d6cb9b1',
				'sha1'          => 'd1f886b951e0cecd6b4f1a245b424df04221276b',
				'sha256'        => 'd875951edc13a31001e0dcd7caa183ac71e05d5b4270745bf680a6f03f8f4cad',
				'sha512'        => '6cff2000769e2e700adfc827b21f9e222916d9e024fca0832363d39a1d21c7f05f1091abd689488cdea2cd791f39e6aa1aef4c46c15a03f1a4881b149cf4d2bd',
				'md5_squash'    => '2d6a30176ea9f215bfa01099c124030a',
				'sha1_squash'   => '27c640da93466c2bc76f53a4af4f7e59b1d70c6b',
				'sha256_squash' => '0ed38c520cf7ad0e67f3585c6e16ce7efcfac4c3aa157827757e1b67feeba64b',
				'sha512_squash' => 'f48b36ab4ce74035a2e59a03349dfeff439e5da19da26d665af96a8834e4e5acf5e3ff41ff1a751f11a7fd9602a6c3942965f122b685cfbd5b81ebdacbaab997',
			],
		];
	}
}
