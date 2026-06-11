<?php

namespace App\Support;

/**
 * Server-side SVG chart builder for the dompdf report booklets (dompdf cannot run Chart.js, so
 * the legacy charts are re-rendered as static SVG). dompdf has NO inline-<svg> support — SVG
 * only renders through <img>, so every chart is returned as an <img src="data:image/svg+xml...">
 * sized to its viewBox. Geometry only uses primitives that php-svg-lib reliably rasterises:
 * rect / line-segment paths / text — doughnut arcs are polygon-approximated rather than `A` arcs.
 *
 * Colors mirror the legacy Chart.js datasets in statistics/a4.php / a4-monthly.php.
 */
final class ReportSvg
{
    /** Legacy 4-series palette: Admissions / Discharges / New Consultations / Signed Off. */
    public const SERIES_COLORS = ['#2986cc', '#cc2986', '#4bc0c0', '#ffcd56'];

    /** Legacy doughnut palette (7 destination buckets). */
    public const DONUT_COLORS = ['#ff6384', '#36a2eb', '#ffcd56', '#24a973', '#ef3e5b', '#4b256d', '#3f647e'];

    /**
     * Grouped vertical bar chart with optional per-bar value labels (the legacy datalabels).
     *
     * @param string[] $labels   x-axis group labels
     * @param array    $series   [['label' => string, 'color' => '#hex', 'data' => number[]], ...]
     */
    public static function groupedBar(array $labels, array $series, int $width, int $height, bool $values = true): string
    {
        $padL = 30; $padR = 6; $padT = $values ? 16 : 10; $padB = 30; // bottom: x labels + legend
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;
        $baseY = $padT + $plotH;

        $maxV = 1;
        foreach ($series as $s) { foreach ($s['data'] as $v) { $maxV = max($maxV, (float) $v); } }
        $maxV = self::niceCeil($maxV);

        $svg = self::open($width, $height);
        // y gridlines + tick labels (4 divisions)
        for ($i = 0; $i <= 4; $i++) {
            $val = $maxV / 4 * $i;
            $y = round($baseY - $plotH / 4 * $i, 1);
            $svg .= '<path d="M' . $padL . ' ' . $y . ' L' . ($width - $padR) . ' ' . $y . '" stroke="#e3e8ee" stroke-width="0.6" fill="none"/>';
            $svg .= '<text x="' . ($padL - 3) . '" y="' . ($y + 2.5) . '" font-size="6.5" fill="#8a949c" text-anchor="end">' . self::fmt($val) . '</text>';
        }

        $nG = max(1, count($labels));
        $nS = max(1, count($series));
        $groupW = $plotW / $nG;
        $gap = min(8, $groupW * 0.22);
        $barW = max(1.2, ($groupW - $gap) / $nS);

        foreach (array_values($labels) as $g => $label) {
            $gx = $padL + $g * $groupW + $gap / 2;
            foreach (array_values($series) as $si => $s) {
                $v = (float) ($s['data'][$g] ?? 0);
                $h = $maxV > 0 ? round($v / $maxV * $plotH, 1) : 0;
                $x = round($gx + $si * $barW, 2);
                $svg .= '<rect x="' . $x . '" y="' . round($baseY - $h, 1) . '" width="' . round($barW * 0.92, 2) . '" height="' . $h . '" fill="' . $s['color'] . '"/>';
                if ($values) {
                    $svg .= '<text x="' . round($x + $barW * 0.46, 1) . '" y="' . round($baseY - $h - 1.5, 1) . '" font-size="5.5" fill="#4a5258" text-anchor="middle">' . self::fmt($v) . '</text>';
                }
            }
            $svg .= '<text x="' . round($gx + ($groupW - $gap) / 2, 1) . '" y="' . ($baseY + 8) . '" font-size="6.5" fill="#5b6a6e" text-anchor="middle">' . e($label) . '</text>';
        }

        // legend (single row under the x labels)
        $lx = $padL;
        $ly = $height - 6;
        foreach ($series as $s) {
            $svg .= '<rect x="' . $lx . '" y="' . ($ly - 6) . '" width="7" height="7" fill="' . $s['color'] . '"/>';
            $svg .= '<text x="' . ($lx + 10) . '" y="' . $ly . '" font-size="7" fill="#4a5258">' . e($s['label']) . '</text>';
            $lx += 10 + strlen($s['label']) * 4.2 + 14;
        }

        return self::wrap($svg . '</svg>', $width, $height);
    }

    /**
     * Horizontal bar chart (per-consultant LOS) — every label gets a row, zeros included.
     *
     * @param array $rows [['name' => string, 'value' => float], ...]
     */
    public static function hBar(array $rows, int $width, int $height, string $color = '#2986cc', string $title = ''): string
    {
        $padT = $title !== '' ? 16 : 6;
        $padB = 6; $padL = 118; $padR = 26;
        $plotW = $width - $padL - $padR;
        $n = max(1, count($rows));
        $rowH = min(20, ($height - $padT - $padB) / $n);
        $barH = max(3, $rowH * 0.62);

        $maxV = 1;
        foreach ($rows as $r) { $maxV = max($maxV, (float) $r['value']); }
        $maxV = self::niceCeil($maxV);

        $svg = self::open($width, $height);
        if ($title !== '') {
            $svg .= '<text x="' . ($width / 2) . '" y="10" font-size="8" font-weight="bold" fill="#37474f" text-anchor="middle">' . e($title) . '</text>';
        }
        foreach (array_values($rows) as $i => $r) {
            $y = $padT + $i * $rowH;
            $v = (float) $r['value'];
            $w = round($v / $maxV * $plotW, 1);
            $name = mb_strimwidth((string) $r['name'], 0, 26, '…');
            $svg .= '<text x="' . ($padL - 4) . '" y="' . round($y + $rowH / 2 + 2.4, 1) . '" font-size="6.5" fill="#4a5258" text-anchor="end">' . e($name) . '</text>';
            $svg .= '<rect x="' . $padL . '" y="' . round($y + ($rowH - $barH) / 2, 1) . '" width="' . max(0.5, $w) . '" height="' . round($barH, 1) . '" fill="' . $color . '"/>';
            $svg .= '<text x="' . ($padL + $w + 3) . '" y="' . round($y + $rowH / 2 + 2.4, 1) . '" font-size="6.5" fill="#4a5258">' . self::fmt($v, 2) . '</text>';
        }

        return self::wrap($svg . '</svg>', $width, $height);
    }

    /**
     * Doughnut with counts legend (the legacy Discharge/Transfer destination chart).
     * Wedges are polygon-approximated (php-svg-lib-safe). Legend shows "label (count)".
     *
     * @param array $slices label => count (legacy 7 fixed buckets; zero buckets are kept in the legend)
     */
    public static function doughnut(array $slices, int $width, int $height, string $title = ''): string
    {
        $total = array_sum($slices);
        $svg = self::open($width, $height);
        if ($title !== '') {
            $svg .= '<text x="' . ($width / 2) . '" y="10" font-size="8" font-weight="bold" fill="#37474f" text-anchor="middle">' . e($title) . '</text>';
        }

        // legend (top, like the legacy chart) — two columns
        $li = 0; $legendTop = $title !== '' ? 16 : 6;
        $colW = $width / 2;
        foreach ($slices as $label => $count) {
            $cx = ($li % 2) * $colW + 8;
            $cy = $legendTop + intdiv($li, 2) * 10;
            $color = self::DONUT_COLORS[$li % count(self::DONUT_COLORS)];
            $svg .= '<rect x="' . $cx . '" y="' . $cy . '" width="7" height="7" fill="' . $color . '"/>';
            $svg .= '<text x="' . ($cx + 10) . '" y="' . ($cy + 6) . '" font-size="6.5" fill="#4a5258">' . e($label . ' (' . $count . ')') . '</text>';
            $li++;
        }
        $legendH = $legendTop + intdiv($li + 1, 2) * 10 + 4;

        $cx = $width / 2;
        $cy = $legendH + ($height - $legendH) / 2;
        $R = max(10, min(($height - $legendH) / 2 - 6, $width / 3));
        $r = $R * 0.55;

        if ($total <= 0) {
            return self::wrap($svg . '<text x="' . $cx . '" y="' . $cy . '" font-size="8" fill="#8a949c" text-anchor="middle">No discharges in period</text></svg>', $width, $height);
        }

        $a0 = -M_PI / 2; // start at 12 o'clock
        $si = 0;
        foreach ($slices as $count) {
            $color = self::DONUT_COLORS[$si % count(self::DONUT_COLORS)];
            $si++;
            if ($count <= 0) { continue; }
            $a1 = $a0 + $count / $total * 2 * M_PI;
            $svg .= self::wedge($cx, $cy, $r, $R, $a0, $a1, $color);
            $a0 = $a1;
        }

        return self::wrap($svg . '</svg>', $width, $height);
    }

    /** Polygon-approximated annular wedge from angle a0 to a1 (radians). */
    private static function wedge(float $cx, float $cy, float $r, float $R, float $a0, float $a1, string $color): string
    {
        $steps = max(2, (int) ceil(($a1 - $a0) / 0.12));
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {          // outer arc, forward
            $a = $a0 + ($a1 - $a0) * $i / $steps;
            $pts[] = round($cx + $R * cos($a), 2) . ' ' . round($cy + $R * sin($a), 2);
        }
        for ($i = $steps; $i >= 0; $i--) {          // inner arc, back
            $a = $a0 + ($a1 - $a0) * $i / $steps;
            $pts[] = round($cx + $r * cos($a), 2) . ' ' . round($cy + $r * sin($a), 2);
        }

        return '<path d="M' . implode(' L', $pts) . ' Z" fill="' . $color . '"/>';
    }

    /**
     * dompdf renders SVG only via <img>: wrap the markup as a data-URI image sized to the
     * viewBox. Sized in pt (1 SVG unit = 1pt) — px would shrink to 75% at dompdf's 96 DPI.
     */
    private static function wrap(string $svg, int $w, int $h): string
    {
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
            . '" style="width: ' . $w . 'pt; height: ' . $h . 'pt;" alt="">';
    }

    private static function open(int $w, int $h): string
    {
        return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">';
    }

    /** Round an axis max up to a "nice" value so gridline labels are clean. */
    private static function niceCeil(float $v): float
    {
        if ($v <= 4) { return max(1, ceil($v)); }
        $mag = 10 ** floor(log10($v));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($v <= $m * $mag) { return (float) ($m * $mag); }
        }

        return (float) (10 * $mag);
    }

    private static function fmt(float $v, int $maxDp = 1): string
    {
        $s = number_format($v, $maxDp, '.', '');

        return rtrim(rtrim($s, '0'), '.');
    }
}
