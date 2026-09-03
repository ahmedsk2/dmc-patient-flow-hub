/**
 * Shared Chart.js (MIT) option/data builders — the ONE idiom every dashboard chart uses, so the
 * three pages stay visually consistent and each page's chart code is a few lines. Faithful
 * reproductions of the ApexCharts looks they replace (2026-09-03):
 *   - bars: rounded corners (r6), ~55% column width, dashed horizontal grid, no vertical grid/axis
 *           border, axis-token label colours, legend off (single) or top-right (multi).
 *   - area: smooth filled line, top→bottom gradient fill, no legend (lines are direct-labelled +
 *           ChartFigure carries the table).
 *   - doughnut: 70% hole, 2px card-coloured slice borders, bottom legend, opt-in slice datalabels,
 *               optional centre total (centerTotalPlugin, passed per-chart).
 *
 * Every builder takes RESOLVED theme values (gridColor/axisColor/strokeColor from useChartTheme)
 * and the reduced-motion flag, so the options stay reactive when computed inside a page's
 * `computed(() => ...)`. Colours come from the theme `series` palette — never local hex.
 */

// Shared cartesian scale block: dashed y-grid, hidden x-grid/border, token-coloured ticks.
function cartesianScales({ gridColor, axisColor, horizontal = false, stacked = false }) {
    const value = {
        stacked,
        grid: { color: gridColor, borderDash: [4, 4], drawTicks: false },
        border: { display: false },
        ticks: { color: axisColor },
        beginAtZero: true,
    };
    const category = {
        stacked,
        grid: { display: false },
        border: { display: false },
        ticks: { color: axisColor },
    };
    // Vertical bars: x = category, y = value. Horizontal bars (indexAxis 'y'): swap.
    return horizontal ? { x: value, y: category } : { x: category, y: value };
}

/**
 * Bar-chart options. `horizontal` → indexAxis 'y'; `stacked` → stacked scales; `legend` is false
 * (hidden) or a { position, align } object for the top legend.
 */
export function barOptions({ gridColor, axisColor, animation, horizontal = false, stacked = false, legend = false }) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: horizontal ? 'y' : 'x',
        animation,
        scales: cartesianScales({ gridColor, axisColor, horizontal, stacked }),
        plugins: {
            legend: legend
                ? { display: true, position: legend.position || 'top', align: legend.align || 'end',
                    labels: { color: axisColor, boxWidth: 12, boxHeight: 12, font: { weight: '600' } } }
                : { display: false },
            tooltip: { enabled: true },
            datalabels: { display: false },
        },
    };
}

/** [{ name, data }] + colours → Chart.js datasets with the shared rounded-bar styling. */
export function groupedBarData(seriesArray, colors) {
    return {
        datasets: seriesArray.map((s, i) => ({
            label: s.name,
            data: s.data,
            backgroundColor: colors[i % colors.length],
            borderRadius: 6,
            borderSkipped: false,
            categoryPercentage: 1.0,
            barPercentage: 0.55,
            maxBarThickness: 48,
        })),
    };
}

/** Convenience for the common single-series bar: one label + values + one colour. */
export function barData(name, values, color) {
    return groupedBarData([{ name, data: values }], [color]);
}

/**
 * Doughnut options. `datalabel` (optional) = { formatter, color } turns on per-slice labels
 * (mirrors ApexCharts `dataLabels`). Legend sits at the bottom like the originals.
 */
export function doughnutOptions({ axisColor, animation, datalabel = null }) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        animation,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: axisColor, boxWidth: 14, boxHeight: 14, font: { size: 12, weight: '600' }, usePointStyle: true, pointStyle: 'circle' },
            },
            tooltip: { enabled: true },
            datalabels: datalabel
                ? { display: true, color: datalabel.color || '#ffffff', font: { weight: '700' }, formatter: datalabel.formatter }
                : { display: false },
        },
    };
}

/** values + slice colours (+ card-coloured gaps) → doughnut dataset. */
export function doughnutData(values, colors, strokeColor) {
    return {
        datasets: [{
            data: values,
            backgroundColor: colors,
            borderColor: strokeColor,
            borderWidth: 2,
        }],
    };
}

/**
 * Area (smooth filled line) options. No legend — the caller direct-labels the lines and ChartFigure
 * carries the table.
 */
export function areaOptions({ gridColor, axisColor, animation, legend = false }) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation,
        interaction: { intersect: false, mode: 'index' },
        scales: cartesianScales({ gridColor, axisColor }),
        plugins: {
            legend: legend
                ? { display: true, position: legend.position || 'top', align: legend.align || 'end',
                    labels: { color: axisColor, boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'line', font: { weight: '600' } } }
                : { display: false },
            tooltip: { enabled: true },
            datalabels: { display: false },
        },
    };
}

/**
 * [{ name, data, from, to, border }] → filled-line datasets. `from`/`to` are the gradient stop
 * colours (use areaGradient in a scriptable backgroundColor); `border` is the line colour.
 */
export function areaData(seriesArray) {
    return {
        datasets: seriesArray.map((s) => ({
            label: s.name,
            data: s.data,
            borderColor: s.border,
            backgroundColor: s.background, // scriptable areaGradient(context, from, to)
            borderWidth: 2.5,
            fill: 'origin',
            tension: 0.4,
            pointRadius: 0,
            pointHoverRadius: 4,
        })),
    };
}
