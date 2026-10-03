<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Inspection report — {{ $car }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1C1F1E; }
    h1 { font-size: 18px; margin: 0; color: #16302B; }
    h2 { font-size: 12px; margin: 14px 0 4px; color: #16302B; }
    .muted { color: #6B6F6C; }
    .head { width: 100%; border-bottom: 2px solid #16302B; padding-bottom: 10px; margin-bottom: 12px; }
    .badge { display: inline-block; background: #16302B; color: #fff; font-weight: bold; padding: 6px 8px; border-radius: 6px; }
    .score { font-size: 30px; font-weight: bold; color: #16302B; }
    .pill { display: inline-block; padding: 2px 7px; border-radius: 8px; font-weight: bold; font-size: 9.5px; }
    .pass { background: #E4ECE9; color: #16302B; }
    .advisory { background: #FDF1DC; color: #8A5A0B; }
    .fail { background: #FDECEC; color: #B42318; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items td { padding: 4px 0; border-bottom: 1px solid #EEEAE3; vertical-align: top; }
    table.items td.r { text-align: right; width: 70px; }
    .note { color: #4A4D53; font-size: 9.5px; }
    .foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid #E6E2D9; font-size: 9.5px; }
    .signed { margin-top: 12px; padding: 8px 10px; border: 1px solid #16302B; border-radius: 6px; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <span class="badge">{{ $lot->initials() }}</span>
            <strong style="font-size: 13px; margin-left: 6px;">{{ $lot->name }}</strong><br>
            <span class="muted">{{ collect([$lot->address, $lot->city])->filter()->implode(', ') }}</span>
        </td>
        <td style="text-align: right;">
            <h1>Inspection report</h1>
            <span class="muted">{{ $date }}</span>
        </td>
    </tr>
</table>

<table style="width: 100%;">
    <tr>
        <td>
            <strong style="font-size: 14px;">{{ $car }}</strong><br>
            @if ($vinTail)<span class="muted">VIN ···{{ $vinTail }}</span><br>@endif
            @if ($mileage)<span class="muted">{{ $mileage }}</span><br>@endif
            <span class="muted">{{ $inspection->isIndependent() ? 'Independently inspected' : 'Inspected by the seller' }} · {{ $inspection->inspector_name }}</span>
        </td>
        <td style="text-align: right; width: 120px;">
            <span class="score">{{ $inspection->score }}</span><span class="muted"> / 100</span>
        </td>
    </tr>
</table>

@if ($inspection->summary)
    <p style="margin: 10px 0 0;">{{ $inspection->summary }}</p>
@endif

@foreach ($groups as $group)
    <h2>{{ $group['label'] }} <span class="pill {{ $group['result'] }}">{{ $group['result_label'] }}</span></h2>
    <table class="items">
        @foreach ($group['items'] as $item)
            <tr>
                <td>{{ $item['label'] }}@if ($item['note'])<br><span class="note">{{ $item['note'] }}</span>@endif</td>
                <td class="r"><span class="pill {{ $item['result'] }}">{{ $item['result_label'] }}</span></td>
            </tr>
        @endforeach
    </table>
@endforeach

@if ($inspection->isIndependent())
    <div class="signed">
        Signed by <strong>{{ $inspection->inspector_name }}</strong>, a registered CarYard inspector, on {{ $signedAt }}.
    </div>
@endif

<div class="foot muted">
    Score: pass = 1 point, advisory = ½, fail = 0, over 40 checks. This report describes the car on the day it was inspected;
    check it again at your visit. Report {{ $inspection->ulid }} · {{ $url }}
</div>
</body>
</html>
