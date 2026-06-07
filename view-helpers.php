<?php
// view-helpers.php — small, dependency-free, side-effect-free presentational helpers.
// Safe to include anywhere (only defines functions). Loaded centrally via guard.php; also
// included directly by the few pages that don't go through guard.php (e.g. active-list.php).

if (!function_exists('los_band_badge')) {
    /**
     * Accessibility (U1 / WCAG 1.4.1 "Use of Color"): the length-of-stay band on the patient
     * cards/rows is shown with a green/yellow/red background. Colour alone is not an accessible
     * indicator (≈8% of men have a colour-vision deficiency, and it matters in a clinical tool).
     * This returns a small TEXT label naming the band, with a high-contrast colour matching the
     * cell background, so the band is conveyed by text — not by colour alone.
     *
     * Bands mirror the existing render logic exactly:
     *   LOS < short_los              -> "Short stay"   (green cell  #d4edda)
     *   LOS > long_los               -> "Long stay"    (red cell    #f8d7da)
     *   short_los <= LOS <= long_los -> "Average stay" (yellow cell #fff3cd)
     *
     * @param int|float|string $los       Length of stay (days)
     * @param int|float        $shortlos  short_los threshold
     * @param int|float        $longlos   long_los threshold
     * @return string HTML <span> (already escaped) — safe to echo into the colored cell.
     */
    function los_band_badge($los, $shortlos, $longlos) {
        $los = (float) $los;
        if ($los < (float) $shortlos) {
            $label = 'Short stay';   $fg = '#155724'; // dark green (AA on #d4edda)
        } elseif ($los > (float) $longlos) {
            $label = 'Long stay';    $fg = '#721c24'; // dark red   (AA on #f8d7da)
        } else {
            $label = 'Average stay'; $fg = '#856404'; // dark amber (AA on #fff3cd)
        }
        return "<span class='los-band' style='display:block;font-size:.72em;font-weight:700;"
             . "line-height:1.1;color:$fg;'>"
             . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
             . "</span>";
    }
}

if (!function_exists('h')) {
    /**
     * Null-safe HTML escape for echoing a value into HTML text/attribute context.
     * Equivalent to htmlspecialchars($v, ENT_QUOTES, 'UTF-8') but tolerates null —
     * PHP 8.1+ deprecates passing null to htmlspecialchars(). Use this for new output
     * sinks, especially nullable DB columns (DISDATE / med_DISDATE / MORTALITY / DISTO /
     * trans_discharge / delay / signoff_date, etc.), so they never raise E_DEPRECATED.
     *
     * @param string|int|float|null $v
     * @return string escaped output, safe to echo
     */
    function h($v) {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
