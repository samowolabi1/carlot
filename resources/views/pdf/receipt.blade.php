<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt {{ $payment->receipt_no }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1C1F1E; }
    h1 { font-size: 18px; margin: 0; color: #16302B; }
    .muted { color: #6B6F6C; }
    .head { width: 100%; border-bottom: 2px solid #16302B; padding-bottom: 10px; margin-bottom: 14px; }
    .badge { display: inline-block; background: #16302B; color: #fff; font-weight: bold; padding: 6px 8px; border-radius: 6px; }
    table.rows { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.rows td { padding: 6px 0; border-bottom: 1px solid #E6E2D9; vertical-align: top; }
    table.rows td.r { text-align: right; }
    .amount { font-size: 22px; font-weight: bold; color: #16302B; }
    .void { color: #B42318; font-size: 26px; font-weight: bold; border: 3px solid #B42318; padding: 4px 10px; display: inline-block; }
    .foot { margin-top: 22px; padding-top: 10px; border-top: 1px solid #E6E2D9; font-size: 10px; }
    a { color: #C2410C; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <span class="badge">{{ $lot->initials() }}</span>
            <strong style="font-size: 14px; margin-left: 6px;">{{ $lot->name }}</strong><br>
            <span class="muted">{{ collect([$lot->address, $lot->city])->filter()->implode(', ') }}@if ($lotPhone) · {{ $lotPhone }}@endif</span>
        </td>
        <td style="text-align: right;">
            <h1>Receipt</h1>
            <strong>{{ $payment->receipt_no }}</strong><br>
            <span class="muted">{{ $paidAt }}</span>
        </td>
    </tr>
</table>

@if ($payment->isVoid())
    <p><span class="void">VOID</span><br><span class="muted">{{ $payment->void_reason }}</span></p>
@endif

<p class="muted" style="margin: 0;">{{ $payment->amount < 0 ? 'Refunded to' : 'Received from' }}</p>
<p style="margin: 2px 0 12px; font-size: 13px;"><strong>{{ $order->customer->name }}</strong> · {{ $customerPhone }}</p>

<p class="muted" style="margin: 0;">Amount</p>
<p class="amount" style="margin: 2px 0 4px;">{{ $order->money(abs($payment->amount)) }}</p>
<p class="muted" style="margin: 0 0 12px;">{{ $payment->method->label() }}@if ($payment->reference) · Ref {{ $payment->reference }}@endif</p>

<table class="rows">
    <tr><td>Order</td><td class="r">{{ $order->order_no }}</td></tr>
    <tr><td>Car</td><td class="r">{{ $car }}@if ($vin)<br><span class="muted">VIN {{ $vin }}</span>@endif</td></tr>
    <tr><td>Agreed price</td><td class="r">{{ $order->money($order->agreed_price) }}</td></tr>
    @if ($order->discount > 0)
        <tr><td>Discount</td><td class="r">−{{ $order->money($order->discount) }}</td></tr>
    @endif
    @if ($order->trade_in_value > 0)
        <tr><td>Trade-in</td><td class="r">−{{ $order->money($order->trade_in_value) }}</td></tr>
    @endif
    <tr><td>Paid to date</td><td class="r">{{ $order->money($paidToDate) }}</td></tr>
    <tr><td><strong>Balance</strong></td><td class="r"><strong>{{ $balance > 0 ? $order->money($balance) : 'Fully paid' }}</strong></td></tr>
</table>

@if ($receiver)
    <p class="muted" style="margin-top: 12px;">{{ $payment->amount < 0 ? 'Refunded' : 'Received' }} by {{ $receiver }}</p>
@endif

<div class="foot">
    <strong>Track your order on LotLink</strong><br>
    {{-- dompdf can't break long words, so the signed link is printed in chunks. --}}
    <a href="{{ $trackUrl }}">{!! collect(str_split($trackUrl, 64))->map(fn ($part) => e($part))->implode('<br>') !!}</a>
    <p class="muted" style="margin-top: 8px;">Powered by <a href="{{ $poweredBy }}">LotLink</a>. {{ $lot->name }} issued this receipt; LotLink does not handle the money.</p>
</div>
</body>
</html>
