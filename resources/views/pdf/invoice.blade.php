<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $payment->invoiceNumber() }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1C1F1E; }
    .head { width: 100%; border-bottom: 2px solid #16302B; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #16302B; }
    .brand span { color: #C2410C; }
    .muted { color: #6B6F6C; }
    table.rows { width: 100%; border-collapse: collapse; margin-top: 12px; }
    table.rows td { padding: 7px 0; border-bottom: 1px solid #E6E2D9; }
    .r { text-align: right; }
    .total { font-size: 18px; font-weight: bold; color: #16302B; }
    .stamp { display: inline-block; padding: 3px 10px; border: 2px solid #15803D; color: #15803D; font-weight: bold; }
    .refund { border-color: #B42318; color: #B42318; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td><span class="brand">Car<span>Yard</span></span><br><span class="muted">{{ config('app.url') }}</span></td>
        <td class="r"><strong style="font-size: 16px;">{{ $payment->status->value === 'refunded' ? 'Refunded invoice' : 'Invoice and receipt' }}</strong><br>{{ $payment->invoiceNumber() }}<br><span class="muted">{{ $paidAt }}</span></td>
    </tr>
</table>

<p class="muted" style="margin: 0;">Billed to</p>
<p style="margin: 2px 0 12px; font-size: 13px;"><strong>{{ $lot->name }}</strong><br>{{ collect([$lot->address, $lot->city, $lot->state])->filter()->implode(', ') }}</p>

<table class="rows">
    <tr><td><strong>Description</strong></td><td class="r"><strong>Amount</strong></td></tr>
    <tr><td>{{ $payment->description }}</td><td class="r">{{ $payment->money() }}</td></tr>
    <tr><td><strong>Total</strong></td><td class="r total">{{ $payment->money() }}</td></tr>
</table>

<p style="margin-top: 14px;">
    <span class="stamp {{ $payment->status->value === 'refunded' ? 'refund' : '' }}">{{ $payment->status->value === 'refunded' ? 'REFUNDED' : 'PAID' }}</span>
    <span class="muted" style="margin-left: 8px;">{{ ucfirst($payment->provider) }} · ref {{ $payment->reference }}</span>
</p>
</body>
</html>
