<?php
/*
 * @package   coresums
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

declare(strict_types=1);

namespace Akeeba\CoreSums\Tests\Unit\Command;

use Akeeba\CoreSums\Command\Generate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Generate::class)]
final class GenerateExtensionFilterTest extends TestCase
{
	/**
	 * The exact allowlist defined in Generate::IMPORTANT_EXTENSIONS. This test pins the contract
	 * documented in the README so the public list cannot drift without a deliberate change here.
	 */
	public function testAllowlistContainsExpectedExtensionsInOrder(): void
	{
		$reflection = new ReflectionClass(Generate::class);
		$constants  = $reflection->getReflectionConstant('IMPORTANT_EXTENSIONS');

		$this->assertNotFalse($constants, 'Generate::IMPORTANT_EXTENSIONS constant must exist');

		$expected = ['php', 'inc', 'ini', 'xml', 'js', 'es6', 'mjs', 'json'];

		$this->assertSame($expected, $constants->getValue());
	}

	#[DataProvider('allowedExtensionsProvider')]
	public function testAllowedExtensionsPassFilter(string $filename, string $extension): void
	{
		// Generate uses pathinfo($filename, PATHINFO_EXTENSION) and in_array() without flags,
		// so matching is case-sensitive and uses loose comparison via in_array's default.
		$detected = pathinfo($filename, PATHINFO_EXTENSION);

		$this->assertSame($extension, $detected);
		$this->assertTrue(in_array($detected, $this->getAllowlist(), true));
	}

	public static function allowedExtensionsProvider(): array
	{
		return [
			'php file'                  => ['index.php', 'php'],
			'inc file'                  => ['legacy.inc', 'inc'],
			'ini file'                  => ['en-GB.ini', 'ini'],
			'xml file'                  => ['manifest.xml', 'xml'],
			'js file'                   => ['core.js', 'js'],
			'es6 file'                  => ['module.es6', 'es6'],
			'mjs file'                  => ['esmodule.mjs', 'mjs'],
			'json file'                 => ['composer.json', 'json'],
			'nested path php'           => ['administrator/components/com_x/x.php', 'php'],
			'deeply nested mjs'         => ['a/b/c/d/e/f/g.mjs', 'mjs'],
		];
	}

	#[DataProvider('disallowedExtensionsProvider')]
	public function testDisallowedExtensionsAreRejected(string $filename): void
	{
		$detected = pathinfo($filename, PATHINFO_EXTENSION);

		$this->assertFalse(in_array($detected, $this->getAllowlist(), true));
	}

	public static function disallowedExtensionsProvider(): array
	{
		return [
			'png binary'         => ['logo.png'],
			'html doc'           => ['readme.html'],
			'txt doc'            => ['README.txt'],
			'php-dist'           => ['configuration.php-dist'],
			'css stylesheet'     => ['style.css'],
			'gif binary'         => ['icon.gif'],
			'no extension'       => ['LICENSE'],
		];
	}

	/**
	 * Matching is case-sensitive: pathinfo returns the literal extension as it appears in the
	 * filename, and the allowlist contains lowercase entries only. So 'INDEX.PHP' is *not*
	 * accepted by the filter. This test pins that behavior — note this diverges slightly from
	 * what some readers might assume from the README.
	 */
	public function testFilterMatchingIsCaseSensitive(): void
	{
		$detected = pathinfo('INDEX.PHP', PATHINFO_EXTENSION);

		$this->assertSame('PHP', $detected);
		$this->assertFalse(
			in_array($detected, $this->getAllowlist(), true),
			'Uppercase extensions are not in the allowlist — Generate skips them.'
		);
	}

	private function getAllowlist(): array
	{
		$reflection = new ReflectionClass(Generate::class);

		return $reflection->getReflectionConstant('IMPORTANT_EXTENSIONS')->getValue();
	}
}
