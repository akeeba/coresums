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
final class GenerateSquashContentsTest extends TestCase
{
	#[DataProvider('squashProvider')]
	public function testSquashContents(string $input, string $expected): void
	{
		$ref    = new ReflectionClass(Generate::class);
		$method = $ref->getMethod('squashContents');
		$instance = $ref->newInstanceWithoutConstructor();

		$this->assertSame($expected, $method->invoke($instance, $input));
	}

	public static function squashProvider(): array
	{
		return [
			'collapses newlines'                    => ["foo\nbar", 'foo bar'],
			'collapses tabs'                        => ["foo\tbar", 'foo bar'],
			'collapses CRLF'                        => ["foo\r\nbar", 'foo bar'],
			'collapses mixed runs to single space'  => ["foo \t \n\r  bar", 'foo bar'],
			'leading and trailing whitespace'       => ["  foo  ", ' foo '],
			'no whitespace untouched'               => ['hello', 'hello'],
			'empty string'                          => ['', ''],
			'only whitespace becomes single space'  => ["\n\t  \r", ' '],
			'vertical tab collapsed'                => ["foo\x0bbar", 'foo bar'],
			'preserves non-whitespace punctuation'  => ["a\n=\t1;", 'a = 1;'],
		];
	}
}
