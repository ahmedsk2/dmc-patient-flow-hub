<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
@php
    use App\Support\ReportSvg;
    $intervalLabel = ['day' => 'Daily', 'quarter' => 'Quarterly'][$interval] ?? 'Monthly';
    // per-consultant LOS hBar rows (zeros included, like the screen / annual booklet)
    $losRows = collect($perConsultant)->map(fn ($c) => ['name' => $c['name'], 'value' => (float) $c['avgLos']])->all();
@endphp
<style>
    @page { margin: 18pt 22pt; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1e2a2e; font-size: 10px; margin: 0; }
    .page { page-break-after: always; }
    .page-last { page-break-after: avoid; }
    .head { border-bottom: 3px solid #009ca6; padding-bottom: 8px; margin-bottom: 12px; }
    .head h1 { font-size: 18px; margin: 0; color: #00565e; }
    .head .sub { color: #5b6a6e; font-size: 11px; margin-top: 2px; }
    .head .org { float: right; text-align: right; color: #5b6a6e; font-size: 9px; }
    .kpis { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .kpis td { width: 12.5%; text-align: center; padding: 8px 4px; background: #f1f6f6; border: 2px solid #fff; }
    .kpis .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; color: #5b6a6e; }
    .kpis .val { font-size: 15px; font-weight: bold; color: #009ca6; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: #00565e; margin: 12px 0 5px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #00565e; color: #fff; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 3px 5px; border-bottom: 1px solid #e3eaea; font-size: 9px; }
    table.data tr.even td { background: #f7fafa; }
    .panel { border: 2px solid #9aa1a6; background: #f7f8f7; padding: 10px 12px; margin-top: 8px; }
    .foot { margin-top: 14px; border-top: 1px solid #e3eaea; padding-top: 6px; text-align: center; color: #5b6a6e; font-size: 8px; }
</style>
</head>
<body>

{{-- =============== PAGE 1 — HEADLINE KPIs + KPI GRID =============== --}}
<div class="page">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>DMC Internal Medicine</h1>
        <div class="sub">Statistics — {{ $from }} to {{ $to }} · {{ $intervalLabel }} interval</div>
    </div>

    <table class="kpis">
        <tr>
            <td><div class="label">Admissions</div><div class="val">{{ number_format($kpis['admissions']) }}</div></td>
            <td><div class="label">Discharges</div><div class="val">{{ number_format($kpis['discharges']) }}</div></td>
            <td><div class="label">ICU adm</div><div class="val">{{ number_format($kpis['icuAdmissions']) }}</div></td>
            <td><div class="label">Mortality</div><div class="val">{{ $kpis['deaths'] }} ({{ $kpis['mortalityRate'] }}%)</div></td>
            <td><div class="label">Avg LOS</div><div class="val">{{ $kpis['avgLos'] }}d</div></td>
            <td><div class="label">Consultations</div><div class="val">{{ number_format($kpis['consultations']) }}</div></td>
            <td><div class="label">Sign-offs</div><div class="val">{{ number_format($kpis['signoffs']) }}</div></td>
            <td><div class="label">≤{{ $readmitWindow }}d readmits</div><div class="val">{{ number_format($kpis['readmissions']) }}</div></td>
        </tr>
    </table>

    <h2>{{ $intervalLabel }} KPI grid</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Period</th><th class="r">Adm</th><th class="r">Disch</th><th class="r">ICU adm</th>
                <th class="r">→ICU</th><th class="r">ICU mort</th><th class="r">Ward mort</th>
                <th class="r">Readm</th><th class="r">Consults</th><th class="r">Sign-offs</th><th class="r">Avg LOS</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($kpiGrid as $i => $r)
                <tr class="{{ $i % 2 ? 'even' : '' }}">
                    <td>{{ $r['label'] }}</td>
                    <td class="r">{{ $r['admissions'] }}</td>
                    <td class="r">{{ $r['discharges'] }}</td>
                    <td class="r">{{ $r['icu'] }}</td>
                    <td class="r">{{ $r['transToIcu'] }}</td>
                    <td class="r">{{ $r['icuDeaths'] }}</td>
                    <td class="r">{{ $r['wardDeaths'] }}</td>
                    <td class="r">{{ $r['readmits'] }}</td>
                    <td class="r">{{ $r['consultations'] }}</td>
                    <td class="r">{{ $r['signoffs'] }}</td>
                    <td class="r">{{ number_format($r['avgLos'], 1) }}d</td>
                </tr>
            @empty
                <tr><td colspan="11">No activity in {{ $from }} – {{ $to }}.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- =============== PAGE 2 — PER CONSULTANT TABLE + LOS BARS =============== --}}
<div class="page-last">
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>DMC Internal Medicine</h1>
        <div class="sub">Statistics — per consultant · {{ $from }} to {{ $to }}</div>
    </div>

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 58%; vertical-align: top; padding-right: 12px;">
                <h2>By consultant</h2>
                <table class="data">
                    <thead>
                        <tr>
                            <th>Consultant</th><th class="r">Adm</th><th class="r">Disch</th>
                            <th class="r">Avg LOS</th><th class="r">Readm</th><th class="r">Consults</th><th class="r">Sign-offs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($perConsultant as $i => $c)
                            <tr class="{{ $i % 2 ? 'even' : '' }}">
                                <td>{{ $c['name'] }}</td>
                                <td class="r">{{ $c['admissions'] }}</td>
                                <td class="r">{{ $c['discharges'] }}</td>
                                <td class="r">{{ number_format($c['avgLos'], 1) }}d</td>
                                <td class="r">{{ $c['readmits'] }}</td>
                                <td class="r">{{ $c['consultations'] }}</td>
                                <td class="r">{{ $c['signoffs'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7">No consultant activity in range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td style="width: 42%; vertical-align: top;">
                <div class="panel">
                    {!! ReportSvg::hBar($losRows, 310, max(180, count($losRows) * 18 + 30), '#2986cc', 'Per Consultant Avg Ward LOS') !!}
                </div>
            </td>
        </tr>
    </table>

    <div class="foot">DMC Internal Medicine · Patient-Flow Hub · Confidential — contains aggregate clinical data · Generated {{ $generatedAt }}</div>
</div>
</body>
</html>
