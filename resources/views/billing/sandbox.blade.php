<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Test checkout · LotLink</title>
<style>
    body { margin: 0; min-height: 100dvh; display: flex; align-items: center; justify-content: center; background: #F6F4EF; color: #16181D; font: 16px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; }
    main { width: 100%; max-width: 380px; margin: 16px; background: #fff; border: 1px solid #E1DCD2; border-radius: 18px; padding: 24px; display: flex; flex-direction: column; gap: 14px; }
    .tag { align-self: flex-start; background: #FFF6F0; color: #9A3412; border: 1px solid #F6C9AC; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
    h1 { margin: 0; font-size: 20px; }
    .amount { font-size: 32px; font-weight: 700; color: #16302B; }
    p { margin: 0; color: #5B5F66; font-size: 14px; }
    form { display: flex; flex-direction: column; gap: 10px; }
    button { height: 48px; border-radius: 12px; font: 600 15px system-ui, sans-serif; cursor: pointer; }
    .pay { border: 0; background: #C2410C; color: #fff; }
    .decline { border: 1px solid #16302B; background: #fff; color: #16302B; }
</style>
</head>
<body>
<main>
    <span class="tag">Test mode · no real money</span>
    <h1>{{ $payment->description }}</h1>
    <span class="amount">{{ $payment->money() }}</span>
    <p>This stands in for Paystack while PAYMENT_DRIVER=sandbox. Choose what happens and you'll go back to LotLink, which checks the result the same way it checks a real payment.</p>
    <form method="post" action="{{ URL::signedRoute('billing.sandbox.complete', ['payment' => $payment->ulid]) }}">
        @csrf
        <button type="submit" name="result" value="paid" class="pay">Pay {{ $payment->money() }}</button>
        <button type="submit" name="result" value="declined" class="decline">Decline the card</button>
    </form>
</main>
</body>
</html>
