<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\CoreSums\Command;

use Akeeba\CoreSums\HttpFactory\HttpFactory;
use GuzzleHttp\RequestOptions;
use Joomla\Database\DatabaseDriver;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use wapmorgan\UnifiedArchive\Abilities;
use wapmorgan\UnifiedArchive\Drivers\NelexaZip;
use wapmorgan\UnifiedArchive\Drivers\TarByPear;
use wapmorgan\UnifiedArchive\UnifiedArchive;

class Generate
{
	use IoStyleTrait;
	use CmsNamesTrait;

	private const IMPORTANT_EXTENSIONS = ['php', 'inc', 'ini', 'xml', 'js', 'es6', 'mjs', 'json'];

	public function __construct(private readonly DatabaseDriver $db, private readonly HttpFactory $httpFactory) {}

	public function __invoke(
		InputInterface $input, OutputInterface $output, string $cms = 'joomla', ?string $cmsVersion = '',
		bool $all = false, bool $new = false
	)
	{
		$this->initIo($input, $output);

		if (empty($cmsVersion) && !$all)
		{
			$this->io->error('You must tell me which version to process or use --all');

			return;
		}

		if ($all)
		{
			$this->io->title(
				sprintf(
					'Processing all %s versions (THIS WILL BE SLOW)', $this->getCmsName($cms),
				)
			);

			$this->processAll($cms, $new);

			$this->io->success(
				sprintf('Finished processing all versions of %s', $this->getCmsName($cms))
			);
		}
		else
		{
			$this->io->title(
				sprintf('Processing %s %s', $this->getCmsName($cms), $cmsVersion)
			);

			$this->processVersion($cms, $cmsVersion, $new);

			$this->io->success(
				sprintf('Finished processing %s %s', $this->getCmsName($cms), $cmsVersion)
			);
		}
	}

	private function haveChecksumsFor(string $cms, string $version): bool
	{
		$db    = $this->db;
		$query = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('checksums'))->where(
			[
				$db->quoteName('cms') . ' = :cms',
				$db->quoteName('version') . ' = :version',
			]
		)->bind(':cms', $cms)->bind(':version', $version);

		$count = $db->setQuery($query)->loadResult() ?: 0;

		return $count > 0;
	}

	private function processAll(string $cms, bool $new = false): void
	{
		$db       = $this->db;
		$query    = $db->getQuery(true)->select($db->quoteName('version'))->from($db->quoteName('sources'))->where(
				$db->quoteName('cms') . ' = :cms'
			)->bind(
				':cms', $cms
			);
		$versions = $db->setQuery($query)->loadColumn() ?: [];

		foreach ($versions as $version)
		{
			$this->io->title(
				sprintf('Processing %s %s', $this->getCmsName($cms), $version)
			);

			$this->processVersion($cms, $version, $new);
		}
	}

	private function processVersion(string $cms, string $version, bool $new = false)
	{
		if ($new && $this->haveChecksumsFor($cms, $version))
		{
			$this->io->note(
				sprintf(
					'We already have checksums for %s %s. No update necessary', $this->getCmsName($cms), $version
				)
			);

			return;
		}

		// Get the download URL for this version
		$downloadUrl = $this->getDownloadURL($cms, $version);

		if (empty($downloadUrl))
		{
			$this->io->warning(
				sprintf('There is no download URL archive for %s %s', $this->getCmsName($cms), $version)
			);

			return;
		}

		$tempFile = realpath(__DIR__ . '/../../tmp') . '/' . md5($downloadUrl);

		if (file_exists($tempFile))
		{
			$this->io->comment(
				sprintf('%s %s is already downloaded', $this->getCmsName($cms), $version)
			);
		}
		else
		{
			$this->io->comment(
				sprintf('Download %s %s from %s', $this->getCmsName($cms), $version, $downloadUrl)
			);

			// Download the archive
			$client   = $this->httpFactory->makeClient(cache: false);
			$response = $client->request(
				'GET', $downloadUrl, [
					RequestOptions::SINK => $tempFile,
				]
			);

			if ($response->getStatusCode() != 200)
			{
				$this->io->error(
					[
						sprintf(
							'Downloading %s %s from %s failed. HTTP %s.', $this->getCmsName($cms), $version,
							$downloadUrl, $response->getStatusCode()
						),
					]
				);

				return;
			}
		}

		// The archive library fails to auto-detect tar.gz, so we'll give it a little push
		$mimeType = (new FinfoMimeTypeDetector())->detectMimeTypeFromFile($tempFile);

		$format = match ($mimeType)
		{
			'application/gzip' => 'tar.gz',
			'application/zip' => 'zip',
			default => null,
		};

		$driver = match ($mimeType)
		{
			'application/gzip' => TarByPear::class,
			'application/zip' => NelexaZip::class,
			default => null,
		};

		$archive = new UnifiedArchive(
			$tempFile, $format, [
			Abilities::EXTRACT_CONTENT,
			Abilities::STREAM_CONTENT,
		], driver: $driver
		);

		// Remove existing checksums
		$db    = $this->db;
		$query = $db->getQuery(true)->delete($db->quoteName('checksums'))->where(
				[
					$db->quoteName('cms') . ' = :cms',
					$db->quoteName('version') . ' = :version',
				]
			)->bind(':cms', $cms)->bind(':version', $version);
		$db->setQuery($query)->execute();

		$progressBar = $this->io->createProgressBar($archive->count());
		$progressBar->start();

		// Calculate all new checksums
		foreach ($archive->getFiles() as $filename)
		{
			// Make sure this is an extension I care about
			$extension = pathinfo($filename, PATHINFO_EXTENSION);

			if (!in_array($extension, self::IMPORTANT_EXTENSIONS))
			{
				continue;
			}

			$progressBar->advance();
			$progressBar->setMessage($filename);

			$fileContents     = $archive->getFileContent($filename);
			$squashedContents = $this->squashContents($fileContents);

			$o = (object) [
				'cms'           => $cms,
				'version'       => $version,
				'filename'      => $filename,
				'md5'           => hash('md5', $fileContents),
				'sha1'          => hash('sha1', $fileContents),
				'sha256'        => hash('sha256', $fileContents),
				'sha512'        => hash('sha512', $fileContents),
				'md5_squash'    => hash('md5', $squashedContents),
				'sha1_squash'   => hash('sha1', $squashedContents),
				'sha256_squash' => hash('sha256', $squashedContents),
				'sha512_squash' => hash('sha512', $squashedContents),
			];

			$db->insertObject('checksums', $o);
		}

		$progressBar->finish();

		unset($archive);

		unlink($tempFile);
	}

	private function getDownloadURL(string $cms, string $version): ?string
	{
		$db    = $this->db;
		$query = $db->getQuery(true)->select($db->quoteName('url'))->from($db->quoteName('sources'))->where(
				[
					$db->quoteName('cms') . ' = :cms',
					$db->quoteName('version') . ' = :version',
				]
			)->bind(':cms', $cms)->bind(':version', $version);

		return $db->setQuery($query)->loadResult() ?: null;
	}

	private function squashContents(string $contents): string
	{
		return preg_replace('#[\n\r\t\s\v]+#ms', ' ', $contents);
	}
}