<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $lot->name }} poster</title>
<style>
    @page { margin: 0; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #16181D; margin: 0; }
    .band { background: {{ $brand }}; color: #fff; text-align: center; padding: {{ 44 * $scale }}px 30px {{ 36 * $scale }}px; }
    .logo { width: {{ 90 * $scale }}px; height: {{ 90 * $scale }}px; border-radius: 18px; background: #fff; }
    .initials { margin: 0 auto; border-collapse: collapse; }
    .initials td { width: {{ 90 * $scale }}px; height: {{ 90 * $scale }}px; border-radius: 18px; background: #fff; color: {{ $brand }}; font-size: {{ 34 * $scale }}px; font-weight: bold; text-align: center; vertical-align: middle; padding: 0; }
    h1 { font-size: {{ 40 * $scale }}px; margin: {{ 18 * $scale }}px 0 6px; }
    .tag { font-size: {{ 16 * $scale }}px; opacity: .9; margin: 0; }
    .body { text-align: center; padding: {{ 34 * $scale }}px 40px 0; }
    .scan { font-size: {{ 26 * $scale }}px; font-weight: bold; margin: 0 0 {{ 18 * $scale }}px; }
    .qr { width: {{ 360 * $scale }}px; height: {{ 360 * $scale }}px; }
    .site { font-size: {{ 18 * $scale }}px; margin: {{ 14 * $scale }}px 0 0; }
    .wa { font-size: {{ 20 * $scale }}px; font-weight: bold; margin: {{ 24 * $scale }}px 0 0; }
    .foot { position: absolute; bottom: {{ 26 * $scale }}px; left: 0; right: 0; text-align: center; font-size: {{ 12 * $scale }}px; color: #6B6F6C; }
</style>
</head>
<body>
<div class="band">
    @if ($logo)
        <img src="{{ $logo }}" class="logo" alt="">
    @else
        <table class="initials"><tr><td>{{ $lot->initials() }}</td></tr></table>
    @endif
    <h1>{{ $lot->name }}</h1>
    @if ($lot->tagline)<p class="tag">{{ $lot->tagline }}</p>@endif
</div>
<div class="body">
    <p class="scan">Scan to see every car and book a test drive</p>
    <img src="{{ $qr }}" class="qr" alt="QR code">
    <p class="site">{{ $site }}</p>
    @if ($whatsapp)<p class="wa">WhatsApp {{ $whatsapp }}</p>@endif
</div>
<div class="foot">Open your phone camera and point it at the code · Powered by CarYard</div>
</body>
</html>
