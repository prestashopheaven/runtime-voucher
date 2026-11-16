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
 * A voucher setting is not valid (a percentage out of range, a prefix with other characters, ...). The message is
 * English and meant for the log; the module shows its own translated text per code.
 */
final class VoucherException extends \InvalidArgumentException
{
    /** The percentage is not more than 0 and at most 100. */
    public const INVALID_PERCENT = 1;

    /** The amount is not more than 0, or the currency is not a positive id. */
    public const INVALID_AMOUNT = 2;

    /** The prefix has other characters than A-Z, 0-9, "-" and "_", or more than 20. */
    public const INVALID_PREFIX = 3;

    /** The number of hours is not 1 to 8760 (a year). */
    public const INVALID_HOURS = 4;

    /** An id (customer, shop, product, language) is not positive. */
    public const INVALID_ID = 5;

    /** A name is empty or longer than 254 characters. */
    public const INVALID_NAME = 6;

    /** The length of the random part of a code is not 4 to 32. */
    public const INVALID_LENGTH = 7;
}
