# prestashopheaven/runtime-voucher

A personal one-time voucher (PrestaShop cart rule) for one customer: code with a prefix, valid for a number of hours, a percentage or an amount, optionally only for some products and one shop, shown to the customer in the cart.

## Use when

A module gives a customer a personal discount code: a win-back or abandoned-cart mail, a loyalty or review reward, a price-drop or birthday voucher.

## Install

```
composer require prestashopheaven/runtime-voucher
```

## Usage

```php
use PrestaShopHeaven\Runtime\Voucher\VoucherIssuer;
use PrestaShopHeaven\Runtime\Voucher\VoucherSpec;
use PrestaShopHeaven\Runtime\Voucher\VoucherException;
use PrestaShopHeaven\Runtime\Voucher\VoucherNotIssuedException;

$spec = VoucherSpec::percent(10)            // or VoucherSpec::amount(5.0, $currencyId, true)
    ->withPrefix('CART-')                   // A-Z, 0-9, "-", "_", at most 20 (VoucherException otherwise)
    ->validFor(48)                          // hours, 1-8760
    ->forCustomer($customerId)
    ->forShop($shopId)                      // only applied when multistore is used
    ->onlyProducts($productIdsOfTheCart)    // valid only with one of them; a percentage of these products only
    ->named([$langId => 'Your discount']);  // other languages get the first name; none = the code

$voucher = (new VoucherIssuer())->issue($spec, $nowInShopTimeZone); // VoucherNotIssuedException on failure
$voucher->code;        // "CART-7KQ4M2XA"
$voucher->validUntil;  // DateTimeImmutable
(new VoucherIssuer())->isUsable($voucher->id, $now); // still active, not used, within its dates
```

The voucher is a normal cart rule: once (quantity 1, once per customer), not split over orders, shown in the voucher
block of the customer's cart (highlight, Classic and Hummingbird), visible and editable in Catalog > Discounts.

---

Read-only split of [`prestashopheaven/mono-runtime-commerce`](https://github.com/prestashopheaven/mono-runtime-commerce): changes,
issues and pull requests go there. MIT License.
