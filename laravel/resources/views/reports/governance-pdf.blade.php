<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
@php
    use App\Support\ReportSvg;
    $img = fn (string $f) => public_path('images/' . $f);
    $trendLabels = array_column($trend, 'label');
    $trendSeries = [
        ['label' => 'Admissions', 'color' => ReportSvg::SERIES_COLORS[0], 'data' => array_column($trend, 'admissions')],
        ['label' => 'Discharges', 'color' => ReportSvg::SERIES_COLORS[1], 'data' => array_column($trend, 'discharges')],
        ['label' => 'Deaths',     'color' => '#ef3e5b',                    'data' => array_column($trend, 'deaths')],
        ['label' => 'Readmits',   'color' => ReportSvg::SERIES_COLORS[3], 'data' => array_column($trend, 'readmits')],
    ];
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
    table.cards { width: 100%; border-collapse: separate; border-spacing: 12px 4px; }
    table.cards td { width: 25%; }
    .counter { border: 3px solid #2986cc; border-radius: 16px; background: #f3f3f3; text-align: center; padding: 16px 6px 12px; }
    .counter .val { font-size: 20px; font-weight: bold; color: #555; }
    .counter h3 { font-size: 10px; font-weight: bold; color: #2986cc; text-transform: uppercase; margin: 8px 0 0; letter-spacing: 0.4px; }
    .counter .accent { width: 42px; border-bottom: 4px solid #f27f21; margin: 7px auto 0; }
    .panel { border: 2px solid #9aa1a6; background: #f7f8f7; padding: 10px 12px; margin-top: 8px; }
    .chart-title { text-align: center; font-size: 11px; font-weight: 500; color: #2f4f4f; margin: 8px 0 2px; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: #00565e; margin: 12px 0 5px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #00565e; color: #fff; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 3px 5px; border-bottom: 1px solid #e3eaea; font-size: 9px; }
    table.data tr.even td { background: #f7fafa; }
    .empty { color: #8a949c; font-style: italic; padding: 14px 5px; }
    .deident { font-size: 8px; color: #8a949c; margin-top: 4px; }
    .foot { margin-top: 14px; border-top: 1px solid #e3eaea; padding-top: 6px; text-align: center; color: #5b6a6e; font-size: 8px; }
</style>
</head>
<body>

{{-- ============== PAGE 1 — SAFETY KPIs + TREND ============== --}}
<div class="page">
    <img class="banner" src="{{ $img('reportheader1.png') }}" alt="">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>Morbidity & Mortality Pack — {{ $title }}</h1>
        <div class="sub">{{ $from }} to {{ $to }} · de-identified governance review</div>
    </div>

    <table class="cards">
        <tr>
            <td><div class="counter"><div class="val">{{ $kpis['deaths'] }} ({{ $kpis['mortalityRate'] }}%)</div><h3>Mortality</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ number_format($kpis['readmissions']) }}</div><h3>≤{{ $readmitWindow }}d Readmissions</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ $kpis['longStay'] }} ({{ $kpis['longStayPct'] }}%)</div><h3>Long Stay</h3><div class="accent"></div></div></td>
            <td><div class="counter"><div class="val">{{ $kpis['weekend'] }} ({{ $kpis['weekendPct'] }}%)</div><h3>Weekend Discharge</h3><div class="accent"></div></div></td>
        </tr>
    </table>

    <div class="panel">
        <div class="chart-title">Period trend: Admissions | Discharges | Deaths | Readmissions</div>
        {!! ReportSvg::groupedBar($trendLabels, $trendSeries, 750, 300, true) !!}
    </div>
</div>

{{-- ============== PAGE 2 — DEATH LINE LIST (DE-IDENTIFIED) ============== --}}
<div class="page">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>Mortality Line List — {{ $title }}</h1>
        <div class="sub">Every death discharged in the period</div>
    </div>

    <table class="data">
        <thead>
            <tr><th>MRN</th><th class="r">Age</th><th class="r">LOS</th><th>Location</th><th>Primary Dx</th><th>Consultant</th></tr>
        </thead>
        <tbody>
            @forelse ($deaths as $i => $d)
                <tr class="{{ $i % 2 ? 'even' : '' }}">
                    <td>{{ $d['mrn'] }}</td>
                    <td class="r">{{ $d['age'] ?? '—' }}</td>
                    <td class="r">{{ $d['los'] ?? '—' }}</td>
                    <td>{{ $d['location'] ?? '—' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($d['dx'], 44) }}</td>
                    <td>{{ $d['consultant'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No deaths recorded in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="deident">De-identified: MRN is the clinical identifier; patient names are never included.</p>
</div>

{{-- ============== PAGE 3 — READMISSION LINE LIST (DE-IDENTIFIED) ============== --}}
<div class="page-last">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>Readmission Line List — {{ $title }}</h1>
        <div class="sub">Every ≤{{ $readmitWindow }}-day readmission admitted in the period</div>
    </div>

    <table class="data">
        <thead>
            <tr><th>MRN</th><th class="r">Age</th><th>Admit Date</th><th class="r">Gap (days)</th><th>Consultant</th></tr>
        </thead>
        <tbody>
            @forelse ($readmits as $i => $r)
                <tr class="{{ $i % 2 ? 'even' : '' }}">
                    <td>{{ $r['mrn'] }}</td>
                    <td class="r">{{ $r['age'] ?? '—' }}</td>
                    <td>{{ $r['admit_date'] }}</td>
                    <td class="r">{{ $r['gap_days'] }}</td>
                    <td>{{ $r['consultant'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No readmissions recorded in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="deident">De-identified: MRN is the clinical identifier; patient names are never included.</p>

    <div class="foot">DMC Internal Medicine · M&M Governance Pack · Confidential — de-identified clinical data · Generated {{ $generatedAt }}</div>
</div>
</body>
</html>
