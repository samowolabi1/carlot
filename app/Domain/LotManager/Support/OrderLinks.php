<?php

namespace App\Domain\LotManager\Support;

use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use Illuminate\Support\Facades\URL;

/**
 * Customer links from receipts and messages. Signed, so they open without an account;
 * they don't expire because a receipt is kept for years.
 */
final class OrderLinks
{
    public static function track(SalesOrder $order): string
    {
        return URL::signedRoute('orders.track', ['order' => $order->ulid]);
    }

    public static function receipt(OrderPayment $payment, SalesOrder $order): string
    {
        return URL::signedRoute('orders.receipt', ['order' => $order->ulid, 'payment' => $payment->ulid]);
    }

    /** CarYard home with UTM tags, so sign-ups can be traced to lots (TDD M19: Growth hooks). */
    public static function poweredBy(string $medium, ?string $lotSlug = null): string
    {
        return url('/').'?'.http_build_query(array_filter([
            'utm_source' => 'caryard',
            'utm_medium' => $medium,
            'utm_campaign' => $lotSlug,
        ]));
    }
}
