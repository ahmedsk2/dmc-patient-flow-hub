<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 16mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1e2a2e; font-size: 11px; margin: 0; }
    .head { border-bottom: 3px solid #009ca6; padding-bottom: 8px; margin-bottom: 14px; }
    .head h1 { font-size: 20px; margin: 0; color: #00565e; }
    .head .sub { color: #5b6a6e; font-size: 12px; margin-top: 2px; }
    .head .org { float: right; text-align: right; color: #5b6a6e; font-size: 10px; }
    .kpis { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .kpis td { width: 25%; text-align: center; padding: 8px 4px; background: #f1f6f6; border: 2px solid #fff; }
    .kpis .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #5b6a6e; }
    .kpis .val { font-size: 17px; font-weight: bold; color: #009ca6; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #00565e; color: #fff; text-align: left; padding: 5px 8px; font-size: 10px; text-transform: uppercase; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 3px 8px; border-bottom: 1px solid #e3eaea; }
    table.data tr.wknd td { background: #eaf6f4; }
    table.data tr.total td { background: #d4f0ef; font-weight: bold; color: #00565e; border-top: 2px solid #009ca6; }
    .foot { margin-top: 18px; border-top: 1px solid #e3eaea; padding-top: 8px; text-align: center; color: #5b6a6e; font-size: 9px; }
</style>
</head>
<body>
    <div class="head">
        <div class="org">Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}</div>
        <h1>DMC Internal Medicine</h1>
        <div class="sub">Monthly Activity Report — {{ $monthName }} {{ $year }}</div>
    </div>

    <table class="kpis">
        <tr>
            <td><div class="label">Admissions</div><div class="val">{{ number_format($totals['admissions']) }}</div></td>
            <td><div class="label">Discharges</div><div class="val">{{ number_format($totals['discharges']) }}</div></td>
            <td><div class="label">ICU</div><div class="val">{{ number_format($totals['icu']) }}</div></td>
            <td><div class="label">Mortality</div><div class="val">{{ number_format($totals['deaths']) }}</div></td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr><th>Day</th><th class="r">Admissions</th><th class="r">Discharges</th><th class="r">ICU</th><th class="r">Mortality</th></tr>
        </thead>
        <tbody>
            @foreach ($days as $d)
                <tr class="{{ in_array($d['weekday'], ['Sat', 'Sun']) ? 'wknd' : '' }}">
                    <td>{{ $d['weekday'] }} {{ $d['day'] }}</td>
                    <td class="r">{{ $d['admissions'] }}</td>
                    <td class="r">{{ $d['discharges'] }}</td>
                    <td class="r">{{ $d['icu'] }}</td>
                    <td class="r">{{ $d['deaths'] }}</td>
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

    <div class="foot">DMC Internal Medicine · Patient-Flow Hub · Confidential — contains aggregate clinical data</div>
</body>
</html>
