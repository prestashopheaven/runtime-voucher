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

namespace PrestaShopHeaven\Runtime\Voucher;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The code of a voucher: the prefix of the merchant followed by random characters a customer can type without
 * confusion (no 0/O, 1/I/L).
 */
final class VoucherCode
{
    /** The characters of the random part. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** The pattern a prefix has to match (after upper-casing): letters, digits, "-" and "_", at most 20. */
    public const PREFIX_PATTERN = '/^[A-Z0-9_-]{0,20}$/D';

    /**
     * A new random code, e.g. "CART-7KQ4M2XA" for prefix "CART-".
     *
     * @param string $prefix the merchant's prefix: A-Z, 0-9, "-" and "_", at most 20 (lower case is upper-cased)
     * @param int $length the number of random characters, 4 to 32
     *
     * @throws VoucherException with INVALID_PREFIX or INVALID_LENGTH
     */
    public static function generate(string $prefix, int $length = 8): string
    {
        $prefix = self::normalizePrefix($prefix);
        if ($length < 4 || $length > 32) {
            throw new VoucherException(sprintf('The random part of a voucher code has 4 to 32 characters, not %d.', $length), VoucherException::INVALID_LENGTH);
        }
        $last = strlen(self::ALPHABET) - 1;
        $code = $prefix;
        for ($i = 0; $i < $length; ++$i) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * The prefix in upper case, checked.
     *
     * @throws VoucherException with INVALID_PREFIX
     */
    public static function normalizePrefix(string $prefix): string
    {
        $prefix = strtoupper(trim($prefix, " \t\n\r"));
        if (!preg_match(self::PREFIX_PATTERN, $prefix)) {
            throw new VoucherException('A voucher prefix has at most 20 characters: A-Z, 0-9, "-" and "_".', VoucherException::INVALID_PREFIX);
        }

        return $prefix;
    }
}
