<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Command;

use Akeeba\CoreSums\Database\Schema;
use Joomla\Database\DatabaseDriver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Dump
{
	use IoStyleTrait;
	use CmsNamesTrait;

	/**
	 * @param   DatabaseDriver  $db             The database
	 * @param   string|null     $defaultOutDir  Output folder when none is given; defaults to tmp/dump
	 */
	public function __construct(
		private readonly DatabaseDriver $db,
		private readonly ?string $defaultOutDir = null
	) {}

	public function __invoke(
		InputInterface $input, OutputInterface $output,
		?string $outDir = null, bool $sources = false, bool $noSums = false, $gzip = false
	)
	{
		$this->initIo($input, $output);

		Schema::ensureIndexes($this->db);

		if (!empty($outDir))
		{
			/**
			 * A folder which does not exist is an error, not a reason to fall back to the default folder. When
			 * publishing, a wrong path would otherwise publish nothing and report nothing.
			 */
			if (!is_dir($outDir))
			{
				$this->io->error(sprintf('The output folder %s does not exist.', $outDir));

				return 1;
			}

			$outDir = realpath($outDir);
		}
		else
		{
			$outDir = $this->defaultOutDir ?? __DIR__ . '/../../tmp/dump';

			if (!is_dir($outDir))
			{
				mkdir($outDir, 0755, true);
			}
		}

		$thingsDone = 0;

		if ($sources)
		{
			$thingsDone++;

			$targetFile = $outDir . '/sources.json';

			$this->io->info(sprintf('Dumping sources to %s', $targetFile));

			$this->dumpSources($targetFile, $gzip);
		}

		if (!$noSums)
		{
			$thingsDone++;

			$this->io->info(sprintf('Dumping checksums to %s', $outDir));

			$this->dumpAllChecksums($outDir, $gzip);
		}

		if (empty($thingsDone))
		{
			$this->io->warning('You told me to do nothing!');

			return 1;
		}

		$this->io->success('Successful dump');

		return 0;
	}

	private function dumpSources(string $targetFile, bool $gzip = false)
	{
		if (file_exists($targetFile))
		{
			@unlink($targetFile);
		}

		@mkdir(dirname($targetFile), 0755, true);

		$db    = $this->db;
		$query = $db->createQuery()
			->select('*')
			->from('sources');
		$data  = $db->setQuery($query)->loadAssocList();

		uasort($data, fn($a, $b) => version_compare($a['version'], $b['version']));

		$json = json_encode(array_values($data));

		if ($gzip)
		{
			file_put_contents($targetFile . '.gz', gzcompress($json, 9, ZLIB_ENCODING_GZIP));
		}
		else
		{
			file_put_contents($targetFile, $json);
		}
	}

	private function dumpAllChecksums(string $outDir, bool $gzip = false)
	{
		$db    = $this->db;
		$query = $db->createQuery()
			->select(
				[
					$db->quoteName('cms'),
					$db->quoteName('version'),
				]
			)
			->from('sources');

		$allCMSVersions = $db->setQuery($query)->loadObjectList() ?: [];
		$allCMSVersions = array_reverse($allCMSVersions);

		foreach ($allCMSVersions as $what)
		{
			$this->io->writeln(
				sprintf('Dumping checksums for %s %s', $this->getCmsName($what->cms), $what->version)
			);

			$this->dumpCmsVersionChecksums($what->cms, $what->version, $outDir, $gzip);
		}
	}

	private function dumpCmsVersionChecksums($cms, $version, string $outDir, bool $gzip = false)
	{
		$subDir = $outDir . '/' . $cms . '/' . $version;
		$types  = [
			'md5',
			'sha1',
			'sha256',
			'sha512',
			'md5_squash',
			'sha1_squash',
			'sha256_squash',
			'sha512_squash',
		];

		$missingTypes = array_filter(
			$types,
			fn(string $type) => !$this->isDumped($subDir . '/' . $type . '.json' . ($gzip ? '.gz' : ''), $gzip)
		);

		if (empty($missingTypes))
		{
			$this->io->writeln('  -> already dumped ↩️');

			return;
		}

		/**
		 * Existing files are never overwritten. If we wrote empty files for a version whose checksums have not been
		 * generated yet, they would stay published even after the checksums become available.
		 */
		if (!$this->haveChecksumsFor($cms, $version))
		{
			$this->io->writeln('  -> no checksums yet; skipping ⏭️');

			return;
		}

		if (!is_dir($subDir))
		{
			@mkdir($subDir, 0755, true);
		}

		$db = $this->db;

		$this->io->write('  -> ');

		foreach ($types as $type)
		{
			$this->io->write(' ' . $type);

			if (!in_array($type, $missingTypes, true))
			{
				$this->io->write('↩️');

				continue;
			}

			$targetFile = $subDir . '/' . $type . '.json' . ($gzip ? '.gz' : '');

			$query = $db->createQuery()
				->select(
					[
						$db->quoteName('filename'),
						$db->quoteName($type, 'hash'),
					]
				)
				->from($db->quoteName('checksums'))
				->where([
					$db->quoteName('cms') . ' = :cms',
					$db->quoteName('version') . ' = :version',
				])
				->order($db->quoteName('filename') . ' ASC')
				->bind(':cms', $cms)
				->bind(':version', $version);

			$data = $db->setQuery($query)->loadAssocList('filename', 'hash');
			$json = json_encode($data);

			if ($gzip)
			{
				$json = gzcompress($json, 9, ZLIB_ENCODING_GZIP);
			}

			/**
			 * Write to a temporary file and rename it into place, so that an interrupted run never leaves a truncated
			 * file behind. Since existing files are skipped, a truncated file would otherwise be permanent.
			 */
			$tempFile = $targetFile . '.tmp';
			$result   = file_put_contents($tempFile, $json) !== false && rename($tempFile, $targetFile);

			if (!$result)
			{
				@unlink($tempFile);
			}

			$this->io->write($result ? '✅' : '❌');
		}

		$this->io->writeln('');
	}

	/**
	 * Does this checksums file already hold a usable dump?
	 *
	 * Older versions of Dump wrote an empty `[]` file for a version without checksums, and existing files are never
	 * overwritten; such a file would stay published even after the checksums are generated. Treat it, or a tiny file
	 * which does not decode, as missing. Only files of a few dozen bytes are read, so this stays cheap.
	 *
	 * @param   string  $file  The checksums file
	 * @param   bool    $gzip  Is the file GZip-compressed?
	 *
	 * @return  bool
	 */
	private function isDumped(string $file, bool $gzip): bool
	{
		if (!file_exists($file))
		{
			return false;
		}

		if (filesize($file) > 64)
		{
			return true;
		}

		$contents = @file_get_contents($file);

		if ($gzip && $contents !== false)
		{
			$contents = @gzdecode($contents);
		}

		if ($contents === false)
		{
			return false;
		}

		$decoded = json_decode($contents, true);

		return is_array($decoded) && !empty($decoded);
	}

	private function haveChecksumsFor(string $cms, string $version): bool
	{
		$db    = $this->db;
		$query = $db->createQuery()
			->select('1')
			->from($db->quoteName('checksums'))
			->where(
				[
					$db->quoteName('cms') . ' = :cms',
					$db->quoteName('version') . ' = :version',
				]
			)
			->bind(':cms', $cms)
			->bind(':version', $version)
			->setLimit(1);

		return (bool) $db->setQuery($query)->loadResult();
	}
}
