<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Query objects must come from DatabaseDriver::createQuery(). getQuery(true) is deprecated since joomla/database 2.2
 * and goes away in 4.0.
 *
 * joomla/database reports the deprecation with a silenced trigger_error(), which PHPUnit does not show, so a runtime
 * check would not catch a regression. Scanning the source also covers code paths no test exercises.
 */
#[CoversNothing]
final class QueryApiTest extends TestCase
{
	public function testSourceDoesNotUseDeprecatedGetQueryTrue(): void
	{
		$offences = [];
		$srcDir   = realpath(__DIR__ . '/../../../src');
		$files    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

		/** @var \SplFileInfo $file */
		foreach ($files as $file)
		{
			if (!$file->isFile() || $file->getExtension() !== 'php')
			{
				continue;
			}

			foreach (file($file->getPathname()) as $index => $line)
			{
				if (preg_match('/->\s*getQuery\s*\(\s*(true|1)\s*\)/i', $line))
				{
					$offences[] = sprintf(
						'%s:%d', substr($file->getPathname(), strlen($srcDir) + 1), $index + 1
					);
				}
			}
		}

		$this->assertSame([], $offences, 'Use $db->createQuery() instead of $db->getQuery(true)');
	}
}
