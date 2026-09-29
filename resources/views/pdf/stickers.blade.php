<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $lot->name }} windscreen stickers</title>
<style>
    @page { margin: 22px; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #16181D; }
    table { width: 100%; border-collapse: separate; border-spacing: 10px; }
    td { width: 33.3%; border: 1.5px dashed #B9B2A5; border-radius: 10px; padding: 12px 10px; text-align: center; vertical-align: top; }
    .lot { font-size: 10px; font-weight: bold; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .5px; }
    .qr { width: 150px; height: 150px; margin: 6px 0; }
    .title { font-size: 11.5px; font-weight: bold; }
    .price { font-size: 14px; font-weight: bold; color: {{ $brand }}; margin-top: 2px; }
    .hint { font-size: 8.5px; color: #6B6F6C; margin-top: 5px; }
    .row { page-break-inside: avoid; }
</style>
</head>
<body>
<table>
    @foreach ($rows as $row)
        <tr class="row">
            @foreach ($row as $s)
                <td>
                    <div class="lot">{{ $lot->name }}</div>
                    <img src="{{ $s['qr'] }}" class="qr" alt="">
                    <div class="title">{{ $s['title'] }}</div>
                    @if ($s['price'])<div class="price">{{ $s['price'] }}</div>@endif
                    <div class="hint">Scan for photos, price and to book a test drive</div>
                </td>
            @endforeach
            @for ($i = count($row); $i < 3; $i++)<td style="border: none;"></td>@endfor
        </tr>
    @endforeach
</table>
</body>
</html>
