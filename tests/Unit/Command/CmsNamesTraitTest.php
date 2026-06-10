<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Unit\Command;

use Akeeba\CoreSums\Command\CmsNamesTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversTrait(CmsNamesTrait::class)]
final class CmsNamesTraitTest extends TestCase
{
	#[DataProvider('cmsNameProvider')]
	public function testGetCmsNameMapsKnownAndUnknownTags(string $tag, string $expected): void
	{
		$subject = new class {
			use CmsNamesTrait { getCmsName as public; }
		};

		$this->assertSame($expected, $subject->getCmsName($tag));
	}

	public static function cmsNameProvider(): array
	{
		return [
			'joomla maps to Joomla!'        => ['joomla', 'Joomla!'],
			'wordpress maps to WordPress'   => ['wordpress', 'WordPress'],
			'unknown lowercase ucfirsts'    => ['drupal', 'Drupal'],
			'already-cased passes through'  => ['Magento', 'Magento'],
			'empty string stays empty'      => ['', ''],
		];
	}
}
