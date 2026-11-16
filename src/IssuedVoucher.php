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
 * A voucher that exists as a cart rule: its id, code, until when it is valid and what it gives (plain values).
 */
final class IssuedVoucher
{
    /**
     * The values of the cart rule.
     *
     * @param int $id the cart rule id
     * @param string $code the code the customer enters
     * @param \DateTimeImmutable $validUntil the end of the cart rule (date_to, in the shop's time zone)
     * @param float $percent the percentage (0 for an amount)
     * @param float $amount the amount (0 for a percentage)
     * @param int $currencyId the currency of the amount (0 for a percentage)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly \DateTimeImmutable $validUntil,
        public readonly float $percent,
        public readonly float $amount,
        public readonly int $currencyId,
    ) {
    }
}
