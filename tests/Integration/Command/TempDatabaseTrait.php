<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Integration\Command;

use Joomla\Database\Sqlite\SqliteDriver;

trait TempDatabaseTrait
{
	/** @var string[] */
	private array $tempDbFiles = [];

	/** @var string[] */
	private array $tempDirs = [];

	protected function makeDatabase(): SqliteDriver
	{
		$file                = tempnam(sys_get_temp_dir(), 'coresums-test-');
		$this->tempDbFiles[] = $file;

		return new SqliteDriver([
			'version'  => 3,
			'driver'   => 'sqlite',
			'database' => $file,
		]);
	}

	protected function makeTempDir(string $prefix = 'coresums-test-'): string
	{
		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid($prefix, true);
		mkdir($base, 0755, true);
		$this->tempDirs[] = $base;

		return $base;
	}

	protected function rrmdir(string $dir): void
	{
		if (!is_dir($dir))
		{
			return;
		}

		foreach (scandir($dir) as $entry)
		{
			if ($entry === '.' || $entry === '..')
			{
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $entry;

			if (is_dir($path))
			{
				$this->rrmdir($path);
			}
			else
			{
				@unlink($path);
			}
		}

		@rmdir($dir);
	}

	protected function cleanupTempArtifacts(): void
	{
		foreach ($this->tempDbFiles as $file)
		{
			if (file_exists($file))
			{
				@unlink($file);
			}
		}
		$this->tempDbFiles = [];

		foreach ($this->tempDirs as $dir)
		{
			$this->rrmdir($dir);
		}
		$this->tempDirs = [];
	}
}
