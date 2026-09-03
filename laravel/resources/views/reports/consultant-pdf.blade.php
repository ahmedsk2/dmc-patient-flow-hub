<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
@php
    use App\Support\ReportSvg;
    $img = fn (string $f) => public_path('images/' . $f);
    $n = $physician['numbers'];
    // 4-series monthly trend (admissions / discharges / consultations / sign-offs)
    $trendLabels = $monthlyTrend['labels'] ?? [];
    $trendSeries = [
        ['label' => 'Admissions',    'color' => ReportSvg::SERIES_COLORS[0], 'data' => $monthlyTrend['admissions'] ?? []],
        ['label' => 'Discharges',    'color' => ReportSvg::SERIES_COLORS[1], 'data' => $monthlyTrend['discharges'] ?? []],
        ['label' => 'Consultations', 'color' => ReportSvg::SERIES_COLORS[2], 'data' => $monthlyTrend['consultations'] ?? []],
        ['label' => 'Sign-offs',     'color' => ReportSvg::SERIES_COLORS[3], 'data' => $monthlyTrend['signoffs'] ?? []],
    ];
    // discharge-destination donut + discharged-to donut (zip labels with data)
    $destSlices = array_combine($physician['destinations']['labels'], $physician['destinations']['data']);
    $toSlices = array_combine($physician['dischargedTo']['labels'], $physician['dischargedTo']['data']);
@endphp
<style>
    @page { margin: 18pt 22pt; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1e2a2e; font-size: 10px; margin: 0; }
    .page { page-break-after: always; }
    .page-last { page-break-after: avoid; }
    .banner { width: 100%; }
    .head { border-bottom: 3px solid #009ca6; padding-bottom: 8px; margin: 8px 0 12px; }
    .head h1 { font-size: 18px; margin: 0; color: #00565e; }
    .head .sub { color: #5b6a6e; font-size: 11px; margin-top: 2px; }
    .head .org { float: right; text-align: right; color: #5b6a6e; font-size: 9px; }
    table.cards { width: 100%; border-collapse: separate; border-spacing: 10px 4px; }
    table.cards td { width: 25%; }
    .counter { border: 3px solid #2986cc; border-radius: 14px; background: #f3f3f3; text-align: center; padding: 12px 6px 10px; }
    .counter .val { font-size: 17px; font-weight: bold; color: #555; }
    .counter h3 { font-size: 9px; font-weight: bold; color: #2986cc; text-transform: uppercase; margin: 6px 0 0; letter-spacing: 0.3px; }
    .counter .accent { width: 38px; border-bottom: 4px solid #f27f21; margin: 6px auto 0; }
    .panel { border: 2px solid #9aa1a6; background: #f7f8f7; padding: 10px 12px; margin-top: 8px; }
    .chart-title { text-align: center; font-size: 11px; font-weight: 500; color: #2f4f4f; margin: 8px 0 2px; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: #00565e; margin: 12px 0 5px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #00565e; color: #fff; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 3px 5px; border-bottom: 1px solid #e3eaea; font-size: 9px; }
    table.data tr.even td { background: #f7fafa; }
    .foot { margin-top: 14px; border-top: 1px solid #e3eaea; padding-top: 6px; text-align: center; color: #5b6a6e; font-size: 8px; }
    /* DATA-CLASSIFICATION.md §4/§6: every export carries its level — a running footer on EVERY page */
    .classification-foot { position: fixed; bottom: 4pt; left: 0; right: 0; text-align: center; font-size: 8pt; color: #9aa1a6; }
</style>
</head>
<body>

<div class="classification-foot">CONFIDENTIAL — Internal use / خاص — للاستخدام الداخلي</div>

{{-- ============== PAGE 1 — KPI CARDS + MONTHLY TREND ============== --}}
<div class="page">
    <img class="banner" src="{{ $img('reportheader1.png') }}" alt="">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>Consultant Scorecard — {{ $physician['name'] }}</h1>
        <div class="sub">{{ $from }} to {{ $to }}</div>
    </div>

    <table class="cards">
        <tr>
            <td><div class="counter"><div class="val">{{ number_format($n['admissions']) }}</div><h3>Admissions</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ number_format($n['discharges']) }}</div><h3>Discharges</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ number_format($n['transToIcu']) }}</div><h3>Transfers to ICU</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ number_format($n['deaths']) }}</div><h3>Deaths</h3><div class="accent"></div></div></td>
        </tr>
        <tr>
            <td><div class="counter"><div class="val">{{ $n['avgLos'] }}d <span style="font-size:10px;color:#888;">(unit median {{ round($unitMedianLos, 1) }}d)</span></div><h3>Avg Ward LOS</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ number_format($n['readmissions']) }}</div><h3>≤{{ $readmitWindow }}d Readmissions</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ $weekend }} ({{ number_format($weekendPct, 1) }}%)</div><h3>Weekend Discharge</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ number_format($n['consultations']) }} / {{ number_format($n['signoffs']) }}</div><h3>Consults / Sign-offs</h3><div class="accent"></div></div></td>
        </tr>
    </table>

    <div class="panel">
        <div class="chart-title">Monthly trend: Admissions | Discharges | Consultations | Sign-offs</div>
        {!! ReportSvg::groupedBar($trendLabels, $trendSeries, 750, 300, true) !!}
    </div>
</div>

{{-- ============== PAGE 2 — DESTINATION DONUTS + TOP DIAGNOSES ============== --}}
<div class="page-last">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>Consultant Scorecard — {{ $physician['name'] }}</h1>
        <div class="sub">Destinations & diagnoses · {{ $from }} to {{ $to }}</div>
    </div>

    <div class="panel">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 36%; vertical-align: top;">
                    {!! ReportSvg::doughnut($destSlices, 245, 230, 'Discharge / Transfer') !!}
                </td>
                <td style="width: 36%; vertical-align: top;">
                    {!! ReportSvg::doughnut($toSlices, 245, 230, 'Discharged To') !!}
                </td>
                <td style="width: 28%; vertical-align: top;">
                    <h2>Top diagnoses</h2>
                    <table class="data">
                        <tbody>
                            @forelse ($physician['topDx'] as $d)
                                <tr><td>{{ \Illuminate\Support\Str::limit($d['label'], 36) }}</td><td class="r">{{ $d['value'] }}</td></tr>
                            @empty
                                <tr><td colspan="2">No diagnoses in range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="foot">DMC Internal Medicine · Patient-Flow Hub · Confidential — aggregate per-consultant data · Generated {{ $generatedAt }}</div>
</div>
</body>
</html>
