<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

/**
 * Builds the fixture archives consumed by the Generate pipeline integration tests.
 *
 * Produces:
 *  - tests/fixtures/archives/joomla-fixture-1.0.0.tar.gz
 *  - tests/fixtures/archives/wordpress-fixture-6.0.0.zip
 *
 * The build is deterministic given the same inputs — the same byte contents are
 * always written, so the precomputed "golden" hashes in
 * GeneratePipelineTest will match.
 */

namespace Akeeba\CoreSums\Tests\Fixtures;

use PharData;
use ZipArchive;

final class FixtureBuilder
{
	/**
	 * Files that go into the Joomla fixture archive.
	 *
	 * The keys are the archive paths (relative to the archive root).
	 * Files with extensions outside the Generate allowlist are present
	 * deliberately so the test can assert they are skipped.
	 */
	public static function joomlaFiles(): array
	{
		return [
			'index.php'                                    => "<?php echo \"hi\";\n",
			'configuration.php-dist'                       => "<?php echo \"hi\";\n", // excluded: .php-dist
			'administrator/index.php'                      => "<?php\n// admin\n",
			'language/en-GB/en-GB.ini'                     => "KEY=\"value\"\n",
			'media/system/js/core.js'                      => "var x = 1;\n",
			'templates/cassiopeia/templateDetails.xml'     => "<?xml version=\"1.0\"?><extension/>",
			'README.txt'                                   => "Hello.\n",     // excluded
			'images/logo.png'                              => "\x89PNG\r\n\x1a\nFAKE", // excluded
		];
	}

	/**
	 * Files that go into the WordPress fixture archive.
	 *
	 * All paths are under the top-level wordpress/ directory the way real
	 * WordPress release zips ship; commit 19812af made Generate strip that
	 * prefix before persisting.
	 */
	public static function wordpressFiles(): array
	{
		return [
			'wordpress/index.php'                => "<?php // WP loader\n",
			'wordpress/wp-includes/version.php'  => "<?php\n\$wp_version = '6.0.0';\n",
			'wordpress/wp-config-sample.php'     => "<?php // sample config\n",
			'wordpress/readme.html'              => "<html>readme</html>", // excluded
		];
	}

	public static function buildJoomlaTarGz(string $outPath): void
	{
		if (file_exists($outPath))
		{
			@unlink($outPath);
		}

		$tarPath = $outPath . '.tmp.tar';

		if (file_exists($tarPath))
		{
			@unlink($tarPath);
		}

		$phar = new PharData($tarPath);

		foreach (self::joomlaFiles() as $name => $contents)
		{
			$phar->addFromString($name, $contents);
		}

		// Materialise the tar before gzipping.
		unset($phar);

		$tarHandle = fopen($tarPath, 'rb');
		$gzHandle  = gzopen($outPath, 'wb9');

		while (!feof($tarHandle))
		{
			gzwrite($gzHandle, fread($tarHandle, 8192));
		}

		fclose($tarHandle);
		gzclose($gzHandle);

		@unlink($tarPath);
	}

	public static function buildWordPressZip(string $outPath): void
	{
		if (file_exists($outPath))
		{
			@unlink($outPath);
		}

		$zip = new ZipArchive();

		if ($zip->open($outPath, ZipArchive::CREATE) !== true)
		{
			throw new \RuntimeException('Cannot open zip for writing: ' . $outPath);
		}

		foreach (self::wordpressFiles() as $name => $contents)
		{
			$zip->addFromString($name, $contents);
		}

		$zip->close();
	}

	public static function buildAll(string $archivesDir): void
	{
		if (!is_dir($archivesDir))
		{
			mkdir($archivesDir, 0755, true);
		}

		self::buildJoomlaTarGz($archivesDir . '/joomla-fixture-1.0.0.tar.gz');
		self::buildWordPressZip($archivesDir . '/wordpress-fixture-6.0.0.zip');
	}
}

// Allow direct execution: `php tests/fixtures/build-fixtures.php`.
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__)
{
	FixtureBuilder::buildAll(__DIR__ . '/archives');
	echo "Fixtures built in " . __DIR__ . "/archives\n";
}
