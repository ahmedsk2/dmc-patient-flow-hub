<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
@php
    use App\Support\ReportSvg;
    // plain absolute path — dompdf resolves local files directly (chroot = base_path covers public/)
    $img = fn (string $f) => public_path('images/' . $f);
    $monthAbbr = array_map(fn ($m) => substr($m['label'], 0, 3), $months);
    $fourSeries = [
        ['label' => 'Admissions',               'color' => ReportSvg::SERIES_COLORS[0], 'data' => array_column($months, 'admissions')],
        ['label' => 'Discharges',               'color' => ReportSvg::SERIES_COLORS[1], 'data' => array_column($months, 'discharges')],
        ['label' => 'New Consultations',        'color' => ReportSvg::SERIES_COLORS[2], 'data' => array_column($months, 'consultations')],
        ['label' => 'Signed Off Consultations', 'color' => ReportSvg::SERIES_COLORS[3], 'data' => array_column($months, 'signoffs')],
    ];
    $losSeries = fn (string $key, string $label, string $color) =>
        [['label' => $label, 'color' => $color, 'data' => array_column($months, $key)]];
    $consultantRows = $legacy['consultantLos'];
@endphp
<style>
    @page { margin: 18pt 22pt; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1e2a2e; font-size: 10px; margin: 0; }
    .page { page-break-after: always; }
    .page-last { page-break-after: avoid; }
    .banner { width: 100%; }
    /* legacy counter cards (statistics/a4.php .counter.blue with the orange house accent) */
    table.cards { width: 100%; border-collapse: separate; border-spacing: 12px 4px; }
    table.cards td { width: 25%; }
    .counter { border: 3px solid #2986cc; border-radius: 16px; background: #f3f3f3; text-align: center; padding: 16px 6px 12px; }
    .counter .val { font-size: 20px; font-weight: bold; color: #555; }
    .counter h3 { font-size: 10px; font-weight: bold; color: #2986cc; text-transform: uppercase; margin: 8px 0 0; letter-spacing: 0.4px; }
    .counter .accent { width: 42px; border-bottom: 4px solid #f27f21; margin: 7px auto 0; }
    .panel { border: 2px solid #9aa1a6; background: #f7f8f7; padding: 10px 12px; margin-top: 8px; }
    .chart-title { text-align: center; font-size: 11px; font-weight: 500; color: #2f4f4f; margin: 8px 0 2px; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: #00565e; margin: 12px 0 5px; }
    .head { border-bottom: 3px solid #009ca6; padding-bottom: 8px; margin-bottom: 12px; }
    .head h1 { font-size: 18px; margin: 0; color: #00565e; }
    .head .sub { color: #5b6a6e; font-size: 11px; margin-top: 2px; }
    .head .org { float: right; text-align: right; color: #5b6a6e; font-size: 9px; }
    .kpis { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .kpis td { width: 14.2%; text-align: center; padding: 7px 4px; background: #f1f6f6; border: 2px solid #fff; }
    .kpis .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; color: #5b6a6e; }
    .kpis .val { font-size: 15px; font-weight: bold; color: #00727b; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #00565e; color: #fff; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 3px 5px; border-bottom: 1px solid #e3eaea; font-size: 9px; }
    table.data tr.even td { background: #f7fafa; }
    table.data tr.total td { background: #d4f0ef; font-weight: bold; color: #00565e; border-top: 2px solid #009ca6; }
    .cols { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .cols > tbody > tr > td { vertical-align: top; width: 50%; padding: 0; }
    .cols td.left { padding-right: 12px; }
    .foot { margin-top: 14px; border-top: 1px solid #e3eaea; padding-top: 6px; text-align: center; color: #5b6a6e; font-size: 8px; }
</style>
</head>
<body>

{{-- ============================ PAGE 1 — COVER ============================ --}}
<div class="page">
    <img class="banner" src="{{ $img('reportheader.png') }}" alt="">
    <div style="text-align: center; margin-top: 130pt;">
        <span style="color: #004aab; font-size: 26px; font-weight: 600;">Internal Medicine Department Performance Report For {{ $year }}</span>
    </div>
    <div style="text-align: center; margin-top: 150pt; color: #5b6a6e; font-size: 10px;">
        Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}
    </div>
</div>

{{-- ============== PAGE 2 — COUNTER CARDS + MONTHLY OVERVIEW ============== --}}
<div class="page">
    <img class="banner" src="{{ $img('reportheader1.png') }}" alt="">
    <div class="panel">
        <table class="cards">
            <tr>
                <td><div class="counter">
                    <div class="val">{{ number_format($legacy['totalPatients']) }}</div>
                    <h3>Total Patients</h3><div class="accent"></div>
                </div></td>
                <td><div class="counter">
                    <div class="val">{{ $legacy['weekend'] }} ({{ number_format($legacy['weekendPct'], 2) }}%)</div>
                    <h3>Weekend Discharge</h3><div class="accent"></div>
                </div></td>
                <td><div class="counter">
                    <div class="val">{{ $legacy['longStay'] }} ({{ number_format($legacy['longStayPct'], 2) }}%)</div>
                    <h3>Long Stay</h3><div class="accent"></div>
                </div></td>
                <td><div class="counter">
                    <div class="val">{{ number_format($legacy['readmissions']) }}</div>
                    <h3>72 hrs Readmissions</h3><div class="accent"></div>
                </div></td>
            </tr>
        </table>

        <div class="chart-title">Monthly Overview: Admission | Discharge | Consultations</div>
        {!! ReportSvg::groupedBar($monthAbbr, $fourSeries, 750, 310, true) !!}
    </div>
</div>

{{-- ========== PAGE 3 — LOS CHARTS + DESTINATION DOUGHNUT + CONSULTANT LOS ========== --}}
<div class="page">
    <img class="banner" src="{{ $img('reportheader1.png') }}" alt="">
    <div class="panel">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 66%; vertical-align: top;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 50%; vertical-align: top;">
                                <div class="chart-title">Clinical LOS</div>
                                {!! ReportSvg::groupedBar($monthAbbr, $losSeries('clinicalLos', 'Clinical LOS', '#2986cc'), 245, 185, true) !!}
                            </td>
                            <td style="width: 50%; vertical-align: top;">
                                <div class="chart-title">Physical LOS</div>
                                {!! ReportSvg::groupedBar($monthAbbr, $losSeries('physicalLos', 'Physical LOS', '#2986cc'), 245, 185, true) !!}
                            </td>
                        </tr>
                        <tr>
                            <td style="vertical-align: top;">
                                {!! ReportSvg::doughnut($legacy['destinations'], 245, 230, 'Discharge / Transfer in ' . $year) !!}
                            </td>
                            <td style="vertical-align: top;">
                                <div class="chart-title">ICU LOS</div>
                                {!! ReportSvg::groupedBar($monthAbbr, $losSeries('icuLos', 'ICU LOS', '#2986cc'), 245, 185, true) !!}
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="width: 34%; vertical-align: top;">
                    {!! ReportSvg::hBar($consultantRows, 250, 430, '#2986cc', 'Per Consultant Physical LOS') !!}
                </td>
            </tr>
        </table>
    </div>
</div>

{{-- ============ PAGE 4 — KPI SUMMARY + MONTHLY BREAKDOWN (Laravel additions) ============ --}}
<div class="page-last">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>DMC Internal Medicine</h1>
        <div class="sub">Annual Activity Report — {{ $year }} · detail annex</div>
    </div>

    <table class="kpis">
        <tr>
            <td><div class="label">Admissions</div><div class="val">{{ number_format($totals['admissions']) }}</div></td>
            <td><div class="label">Discharges</div><div class="val">{{ number_format($totals['discharges']) }}</div></td>
            <td><div class="label">ICU</div><div class="val">{{ number_format($totals['icu']) }}</div></td>
            <td><div class="label">Mortality %</div><div class="val">{{ $totals['mortalityRate'] }}%</div></td>
            <td><div class="label">Ward LOS</div><div class="val">{{ $avgLos }}d</div></td>
            <td><div class="label">ICU LOS</div><div class="val">{{ $icuLos }}d</div></td>
            <td><div class="label">Long-stay %</div><div class="val">{{ $totals['lsp'] }}%</div></td>
        </tr>
    </table>

    <h2>Monthly breakdown</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Month</th><th class="r">Adm</th><th class="r">Disch</th><th class="r">ICU</th>
                <th class="r">Deaths</th><th class="r">Consults</th><th class="r">Signoffs</th>
                <th class="r">Readmits</th><th class="r">To ICU</th><th class="r">Wknd disch</th>
                <th class="r">Bed days</th><th class="r">Clin LOS</th><th class="r">Phys LOS</th>
                <th class="r">ICU LOS</th><th class="r">Long-stay %</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($months as $i => $m)
                <tr class="{{ $i % 2 ? 'even' : '' }}">
                    <td>{{ $m['label'] }}</td>
                    <td class="r">{{ $m['admissions'] }}</td>
                    <td class="r">{{ $m['discharges'] }}</td>
                    <td class="r">{{ $m['icu'] }}</td>
                    <td class="r">{{ $m['deaths'] }}</td>
                    <td class="r">{{ $m['consultations'] }}</td>
                    <td class="r">{{ $m['signoffs'] }}</td>
                    <td class="r">{{ $m['readmits'] }}</td>
                    <td class="r">{{ $m['transToIcu'] }}</td>
                    <td class="r">{{ $m['weekend'] }} ({{ number_format($m['weekendPct'], 2) }}%)</td>
                    <td class="r">{{ $m['bedDays'] }}</td>
                    <td class="r">{{ number_format($m['clinicalLos'], 2) }}</td>
                    <td class="r">{{ number_format($m['physicalLos'], 2) }}</td>
                    <td class="r">{{ number_format($m['icuLos'], 2) }}</td>
                    <td class="r">{{ $m['lsp'] }}%</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td class="r">{{ $totals['admissions'] }}</td>
                <td class="r">{{ $totals['discharges'] }}</td>
                <td class="r">{{ $totals['icu'] }}</td>
                <td class="r">{{ $totals['deaths'] }}</td>
                <td class="r">{{ array_sum(array_column($months, 'consultations')) }}</td>
                <td class="r">{{ array_sum(array_column($months, 'signoffs')) }}</td>
                <td class="r">{{ $legacy['readmissions'] }}</td>
                <td class="r">{{ array_sum(array_column($months, 'transToIcu')) }}</td>
                <td class="r">{{ $legacy['weekend'] }} ({{ number_format($legacy['weekendPct'], 2) }}%)</td>
                <td class="r">{{ array_sum(array_column($months, 'bedDays')) }}</td>
                <td class="r" colspan="3"></td>
                <td class="r">{{ $totals['lsp'] }}%</td>
            </tr>
        </tbody>
    </table>

    <table class="cols">
        <tr>
            <td class="left">
                <h2>Top diagnoses</h2>
                <table class="data">
                    <tbody>
                        @forelse ($topDx as $d)
                            <tr><td>{{ \Illuminate\Support\Str::limit($d['name'], 48) }}</td><td class="r">{{ $d['count'] }}</td></tr>
                        @empty
                            <tr><td colspan="2">No data for {{ $year }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td>
                <h2>Admissions by consultant</h2>
                <table class="data">
                    <tbody>
                        @forelse ($perConsultant as $c)
                            <tr><td>{{ $c['name'] }}</td><td class="r">{{ $c['count'] }}</td></tr>
                        @empty
                            <tr><td colspan="2">No data for {{ $year }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <div class="foot">DMC Internal Medicine · Patient-Flow Hub · Confidential — contains aggregate clinical data · Generated {{ $generatedAt }}</div>
</div>
</body>
</html>
