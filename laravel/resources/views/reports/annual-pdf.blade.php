<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 16mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1e2a2e; font-size: 11px; margin: 0; }
    .head { border-bottom: 3px solid #009ca6; padding-bottom: 8px; margin-bottom: 14px; }
    .head h1 { font-size: 20px; margin: 0; color: #00565e; }
    .head .sub { color: #5b6a6e; font-size: 12px; margin-top: 2px; }
    .head .org { float: right; text-align: right; color: #5b6a6e; font-size: 10px; }
    .kpis { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .kpis td { width: 16.6%; text-align: center; padding: 8px 4px; background: #f1f6f6; border: 2px solid #fff; }
    .kpis .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #5b6a6e; }
    .kpis .val { font-size: 17px; font-weight: bold; color: #009ca6; }
    h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.6px; color: #00565e; margin: 14px 0 6px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #00565e; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 5px 8px; border-bottom: 1px solid #e3eaea; }
    table.data tr:nth-child(even) td { background: #f7fafa; }
    table.data tr.total td { background: #d4f0ef; font-weight: bold; color: #00565e; border-top: 2px solid #009ca6; }
    .cols { width: 100%; border-collapse: collapse; margin-top: 6px; }
    .cols > tbody > tr > td { vertical-align: top; width: 50%; padding: 0; }
    .cols td.left { padding-right: 10px; }
    .foot { margin-top: 20px; border-top: 1px solid #e3eaea; padding-top: 8px; text-align: center; color: #5b6a6e; font-size: 9px; }
</style>
</head>
<body>
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>DMC Internal Medicine</h1>
        <div class="sub">Annual Activity Report — {{ $year }}</div>
    </div>

    <table class="kpis">
        <tr>
            <td><div class="label">Admissions</div><div class="val">{{ number_format($totals['admissions']) }}</div></td>
            <td><div class="label">Discharges</div><div class="val">{{ number_format($totals['discharges']) }}</div></td>
            <td><div class="label">ICU</div><div class="val">{{ number_format($totals['icu']) }}</div></td>
            <td><div class="label">Mortality</div><div class="val">{{ number_format($totals['deaths']) }}</div></td>
            <td><div class="label">Mortality %</div><div class="val">{{ $totals['mortalityRate'] }}%</div></td>
            <td><div class="label">Avg LOS</div><div class="val">{{ $avgLos }}d</div></td>
        </tr>
    </table>

    <h2>Monthly breakdown</h2>
    <table class="data">
        <thead>
            <tr><th>Month</th><th class="r">Admissions</th><th class="r">Discharges</th><th class="r">ICU</th><th class="r">Mortality</th></tr>
        </thead>
        <tbody>
            @foreach ($months as $m)
                <tr>
                    <td>{{ $m['label'] }}</td>
                    <td class="r">{{ $m['admissions'] }}</td>
                    <td class="r">{{ $m['discharges'] }}</td>
                    <td class="r">{{ $m['icu'] }}</td>
                    <td class="r">{{ $m['deaths'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td class="r">{{ $totals['admissions'] }}</td>
                <td class="r">{{ $totals['discharges'] }}</td>
                <td class="r">{{ $totals['icu'] }}</td>
                <td class="r">{{ $totals['deaths'] }}</td>
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
                            <tr><td>{{ \Illuminate\Support\Str::limit($d['name'], 34) }}</td><td class="r">{{ $d['count'] }}</td></tr>
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

    <div class="foot">DMC Internal Medicine · Patient-Flow Hub · Confidential — contains aggregate clinical data</div>
</body>
</html>
