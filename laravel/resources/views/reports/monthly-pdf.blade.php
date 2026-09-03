<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
@php
    use App\Support\ReportSvg;
    // plain absolute path — dompdf resolves local files directly (chroot = base_path covers public/)
    $img = fn (string $f) => public_path('images/' . $f);
@endphp
<style>
    @page { margin: 18pt 22pt; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1e2a2e; font-size: 10px; margin: 0; }
    .page { page-break-after: always; }
    .page-last { page-break-after: avoid; }
    .banner { width: 100%; }
    .month-title { text-align: center; color: #004aab; font-size: 16px; font-weight: 600; margin: 6px 0 2px; }
    /* legacy counter cards (statistics/a4-monthly.php .counter.blue with the orange house accent) */
    table.cards { width: 100%; border-collapse: separate; border-spacing: 10px 4px; }
    .counter { border: 3px solid #2986cc; border-radius: 14px; background: #f3f3f3; text-align: center; padding: 12px 4px 9px; }
    .counter .val { font-size: 16px; font-weight: bold; color: #555; }
    .counter h3 { font-size: 8.5px; font-weight: bold; color: #2986cc; text-transform: uppercase; margin: 6px 0 0; letter-spacing: 0.3px; }
    .counter .accent { width: 34px; border-bottom: 3px solid #f27f21; margin: 5px auto 0; }
    .panel { border: 2px solid #9aa1a6; background: #f7f8f7; padding: 8px 10px; margin-top: 6px; }
    .chart-title { text-align: center; font-size: 11px; font-weight: 500; color: #2f4f4f; margin: 6px 0 2px; }
    .foot { margin-top: 8px; text-align: center; color: #5b6a6e; font-size: 8px; }
    /* DATA-CLASSIFICATION.md §4/§6: every export carries its level — a running footer on EVERY page */
    .classification-foot { position: fixed; bottom: 4pt; left: 0; right: 0; text-align: center; font-size: 8pt; color: #9aa1a6; }
</style>
</head>
<body>

<div class="classification-foot">CONFIDENTIAL — Internal use / خاص — للاستخدام الداخلي</div>

@if (empty($months))
    <div class="page-last">
        <img class="banner" src="{{ $img('reportheader.png') }}" alt="">
        <div style="text-align: center; margin-top: 140pt;">
            <span style="color: #004aab; font-size: 22px; font-weight: 600;">Internal Medicine Department Performance Report For {{ $year }}</span>
            <p style="color: #5b6a6e; font-size: 12px; margin-top: 24pt;">No elapsed months in {{ $year }} yet — nothing to report.</p>
        </div>
        <div style="text-align: center; margin-top: 120pt; color: #5b6a6e; font-size: 10px;">
            Eastern Health Cluster · تجمع الشرقية الصحي<br>Generated {{ $generatedAt }}
        </div>
    </div>
@endif

@foreach ($months as $mi => $m)
    @php
        $daily = [
            ['label' => 'Admissions',               'color' => ReportSvg::SERIES_COLORS[0], 'data' => $m['days']['admissions']],
            ['label' => 'Discharges',               'color' => ReportSvg::SERIES_COLORS[1], 'data' => $m['days']['discharges']],
            ['label' => 'New Consultations',        'color' => ReportSvg::SERIES_COLORS[2], 'data' => $m['days']['consultations']],
            ['label' => 'Signed Off Consultations', 'color' => ReportSvg::SERIES_COLORS[3], 'data' => $m['days']['signoffs']],
        ];
        $isLastMonth = $mi === count($months) - 1;
    @endphp

    {{-- ---------- month page (a): counter cards + daily overview ---------- --}}
    <div class="page">
        <img class="banner" src="{{ $img('reportheader1.png') }}" alt="">
        <div class="month-title">Internal Medicine Performance Report — {{ $year }}/{{ $m['m'] }} ({{ $m['name'] }})</div>
        <div class="panel">
            {{-- legacy a4-monthly card row incl. the census-based Total Patients / Long Stay
                 counters — both read "Pending" until the month has fully elapsed (J1-14) --}}
            <table class="cards">
                <tr>
                    <td style="width: 15%;"><div class="counter">
                        <div class="val">{{ $m['cards']['census'] ?? 'Pending' }}</div>
                        <h3>Total Patients</h3><div class="accent"></div>
                    </div></td>
                    <td style="width: 14%;"><div class="counter">
                        <div class="val">{{ $m['cards']['admissions'] }}</div>
                        <h3>Admissions</h3><div class="accent"></div>
                    </div></td>
                    <td style="width: 14%;"><div class="counter">
                        <div class="val">{{ $m['cards']['discharges'] }}</div>
                        <h3>Discharges</h3><div class="accent"></div>
                    </div></td>
                    <td style="width: 14%;"><div class="counter">
                        <div class="val">{{ $m['cards']['deaths'] }}</div>
                        <h3>Mortality</h3><div class="accent"></div>
                    </div></td>
                    <td style="width: 14%;"><div class="counter">
                        <div class="val">{{ $m['cards']['weekend'] }} ({{ number_format($m['cards']['weekendPct'], 2) }}%)</div>
                        <h3>Weekend Discharge</h3><div class="accent"></div>
                    </div></td>
                    <td style="width: 14%;"><div class="counter">
                        <div class="val">{{ $m['cards']['readmits'] }}</div>
                        <h3>72 hrs Readmissions</h3><div class="accent"></div>
                    </div></td>
                    <td style="width: 15%;"><div class="counter">
                        <div class="val">{{ $m['cards']['longStay'] === null ? 'Pending' : $m['cards']['longStay'] . ' (' . number_format($m['cards']['longStayPct'], 2) . '%)' }}</div>
                        <h3>Long Stay</h3><div class="accent"></div>
                    </div></td>
                </tr>
            </table>

            <div class="chart-title">Daily Overview: Admission | Discharge | Consultations</div>
            {!! ReportSvg::groupedBar($m['days']['labels'], $daily, 750, 290, false) !!}
        </div>
    </div>

    {{-- ---------- month page (b): LOS cards + destination doughnut + consultant LOS ---------- --}}
    <div class="{{ $isLastMonth ? 'page-last' : 'page' }}">
        <img class="banner" src="{{ $img('reportheader1.png') }}" alt="">
        <div class="month-title">{{ $year }}/{{ $m['m'] }} ({{ $m['name'] }}) — Length of Stay &amp; Disposition</div>
        <div class="panel">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 58%; vertical-align: top;">
                        <table class="cards">
                            <tr>
                                <td style="width: 33%;"><div class="counter">
                                    <div class="val">{{ number_format($m['los']['clinical'], 2) }}</div>
                                    <h3>Clinical LOS</h3><div class="accent"></div>
                                </div></td>
                                <td style="width: 33%;"><div class="counter">
                                    <div class="val">{{ number_format($m['los']['physical'], 2) }}</div>
                                    <h3>Physical LOS</h3><div class="accent"></div>
                                </div></td>
                                <td style="width: 33%;"><div class="counter">
                                    <div class="val">{{ number_format($m['los']['icu'], 2) }}</div>
                                    <h3>ICU LOS</h3><div class="accent"></div>
                                </div></td>
                            </tr>
                        </table>
                        <div style="margin-top: 10px; text-align: center;">
                            {!! ReportSvg::doughnut($m['destinations'], 320, 280, 'Discharge / Transfer in ' . $year . '/' . $m['m']) !!}
                        </div>
                    </td>
                    <td style="width: 42%; vertical-align: top;">
                        {!! ReportSvg::hBar($m['consultantLos'], 310, 420, '#2986cc', 'Per Consultant Physical LOS') !!}
                    </td>
                </tr>
            </table>
        </div>
        <div class="foot">DMC Internal Medicine · Patient-Flow Hub · Confidential — contains aggregate clinical data · Generated {{ $generatedAt }}</div>
    </div>
@endforeach
</body>
</html>
