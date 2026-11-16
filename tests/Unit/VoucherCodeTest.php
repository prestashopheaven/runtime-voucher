<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShopHeaven\Runtime\Voucher\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Runtime\Voucher\VoucherCode;
use PrestaShopHeaven\Runtime\Voucher\VoucherException;

/**
 * VoucherCode: prefix + random characters from the unambiguous alphabet, the length limits and the prefix rules.
 */
final class VoucherCodeTest extends TestCase
{
    public function testACodeIsThePrefixWithRandomCharacters(): void
    {
        $code = VoucherCode::generate('cart-');

        self::assertMatchesRegularExpression('/^CART-[' . VoucherCode::ALPHABET . ']{8}$/', $code);
        self::assertNotSame($code, VoucherCode::generate('cart-'), 'two codes differ');
    }

    public function testTheLengthHasLimits(): void
    {
        self::assertSame(4, strlen(VoucherCode::generate('', 4)));
        self::assertSame(32, strlen(VoucherCode::generate('', 32)));
        self::assertSame(20 + 32, strlen(VoucherCode::generate(str_repeat('A', 20), 32)));
    }

    public function testTheAlphabetHasNoLookAlikes(): void
    {
        foreach (['0', 'O', '1', 'I', 'L'] as $character) {
            self::assertStringNotContainsString($character, VoucherCode::ALPHABET);
        }
    }

    /**
     * Lengths that are refused.
     *
     * @return iterable<string, array{0: int}>
     */
    public static function wrongLengths(): iterable
    {
        yield 'too short' => [3];
        yield 'zero' => [0];
        yield 'too long' => [33];
    }

    #[DataProvider('wrongLengths')]
    public function testAWrongLengthIsRefused(int $length): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_LENGTH);

        VoucherCode::generate('X', $length);
    }

    /**
     * Prefixes and their normal form.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function prefixes(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'lower case' => ['back_', 'BACK_'];
        yield 'spaces around' => ["  win-\n", 'WIN-'];
        yield 'twenty' => [str_repeat('z', 20), str_repeat('Z', 20)];
    }

    #[DataProvider('prefixes')]
    public function testAPrefixIsUpperCased(string $prefix, string $expected): void
    {
        self::assertSame($expected, VoucherCode::normalizePrefix($prefix));
    }

    /**
     * Prefixes that are refused.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function wrongPrefixes(): iterable
    {
        yield 'space inside' => ['A B'];
        yield 'too long' => [str_repeat('A', 21)];
        yield 'accent' => ['É'];
        yield 'html' => ['<b>'];
        yield 'nul byte' => ["AB\0"];
        yield 'new line at the end' => ["AB\nC"];
    }

    #[DataProvider('wrongPrefixes')]
    public function testAWrongPrefixIsRefused(string $prefix): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_PREFIX);

        VoucherCode::generate($prefix);
    }
}
