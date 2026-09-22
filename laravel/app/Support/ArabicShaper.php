<?php

namespace App\Support;

/**
 * I18N-06: dompdf has no Arabic text-shaping or bidi engine — confirmed empirically for this
 * commit: `grep -ri bidi vendor/dompdf/dompdf/src` finds only a CSS `unicode-bidi` property stub
 * (Css/Style.php:894) that is never consulted by the text layout code, and a scratch render of
 * "محمد عبدالله" with the project's dompdf + `font-family: DejaVu Sans` produced every glyph in
 * isolated form, undrawn joins, and left-to-right character order (the raw logical order, not the
 * right-to-left visual order a reader expects). The bundled font is NOT the problem: inspecting
 * vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf's cmap shows full coverage of Arabic Presentation
 * Forms A and B (contextual isolated/initial/medial/final shapes and the four lam-alef ligatures,
 * U+FE70–U+FEFF / U+FB50–U+FDFF). So this is a shaping problem, not a missing-glyph problem: feed
 * dompdf text that is already in the correct contextual forms and visual order, and the existing
 * bundled font renders it correctly, with no new dependency and no font download.
 *
 * What this class does: given a string that may mix Arabic script with Latin letters, digits and
 * punctuation, it
 *   1. splits the string into maximal Arabic runs (a run may absorb a single space between two
 *      Arabic words) versus everything else, leaving non-Arabic runs untouched and in place —
 *      correct for the LTR-paragraph, embedded-RTL-name case this app actually has (English UI,
 *      occasional Arabic staff/patient name or free-text field);
 *   2. within each Arabic run, walks the letters in logical (reading) order and picks the
 *      contextual presentation form (isolated / initial / medial / final) per the standard Arabic
 *      cursive-joining rules, using the letter's own joining capability (dual-joining letters
 *      connect on both sides; ا د ذ ر ز و ى behave as right-joining only, so they may receive a
 *      connection but never extend one forward; ء is non-joining) and special-cases lam+alef into
 *      the single ligature glyph (mandatory in Arabic typography, not decorative);
 *   3. reverses the shaped run so dompdf's left-to-right draw order produces the correct
 *      right-to-left visual result (derivable directly from "characters are read in the opposite
 *      order they are drawn" — verified by hand for multi-word runs and confirmed against the
 *      probe PDF; see ArabicShaperTest for the worked traces).
 *
 * Deliberate scope limits (documented rather than silently wrong):
 *   - this is NOT a full UAX #9 bidi implementation. A digit or Latin character embedded INSIDE an
 *     Arabic phrase (e.g. "غرفة 5") ends the Arabic run at that character instead of being carried
 *     along as a direction-neutral run per the full bidi algorithm. That is fine for what this
 *     helper is actually applied to — consultant/staff names, ICD-10 diagnosis names, destination
 *     labels — none of which mix digits into the middle of an Arabic phrase in practice.
 *   - Arabic diacritics (tashkeel) are treated as transparent to joining (they do not break a
 *     connection between the letters around them) but are otherwise passed through unshaped; rare
 *     in names/labels, and dompdf has no mark-positioning (GPOS) support to place them correctly
 *     over their base letter regardless of what this class does.
 *   - purely decorative ligatures (e.g. the standalone "Allah" glyph) are not applied; only the
 *     lam-alef ligature, which is mandatory (not a style choice), is produced.
 *
 * Known gap outside this class's control: the "Per Consultant …LOS" horizontal-bar charts built
 * by App\Support\ReportSvg::hBar() print the consultant name INSIDE an embedded
 * <img src="data:image/svg+xml;base64,…"> chart. This class still shapes that name correctly (see
 * the *-pdf.blade.php call sites and their tests — the shaped Unicode text is verified present in
 * the SVG markup itself), but a dompdf + php-svg-lib rendering defect, verified empirically and
 * reproducible independent of shaping (raw Arabic hits it too), corrupts the glyph to a literal
 * "?" specifically for that SVG-embedded occurrence when it shares a dompdf table-cell layout with
 * other chart images or table text on the same page — plain HTML text (every table/heading in
 * these templates, including the SAME name elsewhere on the page) is unaffected. A same-page
 * "warm-up" render of the same text before the chart was tried and does not survive dompdf's
 * table-cell reflow, so no template-only fix exists; a real fix needs either a dompdf/php-svg-lib
 * version investigation or reworking ReportSvg::hBar() to print row labels as plain HTML next to
 * the bars instead of inside the SVG's own <text> — see the session's reported findings.
 */
final class ArabicShaper
{
    private const ISOLATED = 0;

    private const INITIAL = 1;

    private const MEDIAL = 2;

    private const FINAL = 3;

    private const LAM = 0x0644;

    /**
     * Base Arabic letter codepoint => [isolated, initial, medial, final] Arabic Presentation
     * Forms B codepoint, or null where that form does not exist (right-joining letters have no
     * initial/medial form; the standalone hamza has none but isolated). Values verified against
     * Unicode character names (U+FE80..U+FEF4) and against the bundled DejaVu Sans cmap.
     */
    private const FORMS = [
        0x0621 => [0xFE80, null, null, null],       // HAMZA (non-joining)
        0x0622 => [0xFE81, null, null, 0xFE82],       // ALEF WITH MADDA ABOVE (right-joining)
        0x0623 => [0xFE83, null, null, 0xFE84],       // ALEF WITH HAMZA ABOVE (right-joining)
        0x0624 => [0xFE85, null, null, 0xFE86],       // WAW WITH HAMZA ABOVE (right-joining)
        0x0625 => [0xFE87, null, null, 0xFE88],       // ALEF WITH HAMZA BELOW (right-joining)
        0x0626 => [0xFE89, 0xFE8B, 0xFE8C, 0xFE8A],       // YEH WITH HAMZA ABOVE (dual)
        0x0627 => [0xFE8D, null, null, 0xFE8E],       // ALEF (right-joining)
        0x0628 => [0xFE8F, 0xFE91, 0xFE92, 0xFE90],       // BEH (dual)
        0x0629 => [0xFE93, null, null, 0xFE94],       // TEH MARBUTA (right-joining)
        0x062A => [0xFE95, 0xFE97, 0xFE98, 0xFE96],       // TEH (dual)
        0x062B => [0xFE99, 0xFE9B, 0xFE9C, 0xFE9A],       // THEH (dual)
        0x062C => [0xFE9D, 0xFE9F, 0xFEA0, 0xFE9E],       // JEEM (dual)
        0x062D => [0xFEA1, 0xFEA3, 0xFEA4, 0xFEA2],       // HAH (dual)
        0x062E => [0xFEA5, 0xFEA7, 0xFEA8, 0xFEA6],       // KHAH (dual)
        0x062F => [0xFEA9, null, null, 0xFEAA],       // DAL (right-joining)
        0x0630 => [0xFEAB, null, null, 0xFEAC],       // THAL (right-joining)
        0x0631 => [0xFEAD, null, null, 0xFEAE],       // REH (right-joining)
        0x0632 => [0xFEAF, null, null, 0xFEB0],       // ZAIN (right-joining)
        0x0633 => [0xFEB1, 0xFEB3, 0xFEB4, 0xFEB2],       // SEEN (dual)
        0x0634 => [0xFEB5, 0xFEB7, 0xFEB8, 0xFEB6],       // SHEEN (dual)
        0x0635 => [0xFEB9, 0xFEBB, 0xFEBC, 0xFEBA],       // SAD (dual)
        0x0636 => [0xFEBD, 0xFEBF, 0xFEC0, 0xFEBE],       // DAD (dual)
        0x0637 => [0xFEC1, 0xFEC3, 0xFEC4, 0xFEC2],       // TAH (dual)
        0x0638 => [0xFEC5, 0xFEC7, 0xFEC8, 0xFEC6],       // ZAH (dual)
        0x0639 => [0xFEC9, 0xFECB, 0xFECC, 0xFECA],       // AIN (dual)
        0x063A => [0xFECD, 0xFECF, 0xFED0, 0xFECE],       // GHAIN (dual)
        0x0641 => [0xFED1, 0xFED3, 0xFED4, 0xFED2],       // FEH (dual)
        0x0642 => [0xFED5, 0xFED7, 0xFED8, 0xFED6],       // QAF (dual)
        0x0643 => [0xFED9, 0xFEDB, 0xFEDC, 0xFEDA],       // KAF (dual)
        self::LAM => [0xFEDD, 0xFEDF, 0xFEE0, 0xFEDE],       // LAM (dual)
        0x0645 => [0xFEE1, 0xFEE3, 0xFEE4, 0xFEE2],       // MEEM (dual)
        0x0646 => [0xFEE5, 0xFEE7, 0xFEE8, 0xFEE6],       // NOON (dual)
        0x0647 => [0xFEE9, 0xFEEB, 0xFEEC, 0xFEEA],       // HEH (dual)
        0x0648 => [0xFEED, null, null, 0xFEEE],       // WAW (right-joining)
        0x0649 => [0xFEEF, null, null, 0xFEF0],       // ALEF MAKSURA (right-joining)
        0x064A => [0xFEF1, 0xFEF3, 0xFEF4, 0xFEF2],       // YEH (dual)
    ];

    /**
     * LAM immediately followed by one of these alef-family codepoints becomes a single ligature
     * glyph — mandatory in Arabic typography. [isolated, final]; the ligature never has an
     * initial/medial form (an alef never extends a connection forward).
     */
    private const LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6], // LAM + ALEF WITH MADDA ABOVE
        0x0623 => [0xFEF7, 0xFEF8], // LAM + ALEF WITH HAMZA ABOVE
        0x0625 => [0xFEF9, 0xFEFA], // LAM + ALEF WITH HAMZA BELOW
        0x0627 => [0xFEFB, 0xFEFC], // LAM + ALEF
    ];

    /** A run in progress may cross a single embedded space; render() decides which. */
    public static function shape(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $chars = mb_str_split($text, 1, 'UTF-8');
        $n = count($chars);
        $classes = array_map(self::classify(...), $chars);

        $out = '';
        $i = 0;
        while ($i < $n) {
            if ($classes[$i] !== 'A') {
                $out .= $chars[$i];
                $i++;

                continue;
            }

            // extend the Arabic run through letters/diacritics, and through a run of spaces only
            // when Arabic resumes after them (so a space before a Latin/digit run ends the run,
            // but the space between two Arabic words stays inside it and gets reordered with it)
            $j = $i;
            while ($j < $n) {
                if ($classes[$j] === 'A' || $classes[$j] === 'T') {
                    $j++;

                    continue;
                }
                if ($classes[$j] === 'S') {
                    $k = $j;
                    while ($k < $n && ($classes[$k] === 'S' || $classes[$k] === 'T')) {
                        $k++;
                    }
                    if ($k < $n && $classes[$k] === 'A') {
                        $j = $k;

                        continue;
                    }
                }
                break;
            }

            $out .= self::shapeRun(array_slice($chars, $i, $j - $i));
            $i = $j;
        }

        return $out;
    }

    private static function classify(string $char): string
    {
        $cp = mb_ord($char, 'UTF-8');
        if ($cp === 0x0020) {
            return 'S';
        }
        if (isset(self::FORMS[$cp])) {
            return 'A';
        }
        if (self::isTransparent($cp)) {
            return 'T';
        }

        return 'O';
    }

    /** Arabic combining marks (tashkeel/Quranic annotation) — do not break a cursive join. */
    private static function isTransparent(int $cp): bool
    {
        return ($cp >= 0x064B && $cp <= 0x065F)
            || $cp === 0x0670
            || ($cp >= 0x06D6 && $cp <= 0x06ED && $cp !== 0x06DD && $cp !== 0x06DE);
    }

    /** @param  string[]  $runChars  one Arabic run (letters, absorbed spaces, diacritics) */
    private static function shapeRun(array $runChars): string
    {
        $tokens = [];
        $n = count($runChars);
        $i = 0;
        while ($i < $n) {
            $cp = mb_ord($runChars[$i], 'UTF-8');
            if ($cp === self::LAM && $i + 1 < $n) {
                $nextCp = mb_ord($runChars[$i + 1], 'UTF-8');
                if (isset(self::LAM_ALEF[$nextCp])) {
                    $tokens[] = ['lig', $nextCp];
                    $i += 2;

                    continue;
                }
            }
            if (isset(self::FORMS[$cp])) {
                $tokens[] = ['ltr', $cp];
            } elseif (self::isTransparent($cp)) {
                $tokens[] = ['trans', $cp];
            } else {
                $tokens[] = ['other', $cp]; // the absorbed inter-word space
            }
            $i++;
        }

        $outCps = [];
        $m = count($tokens);
        for ($t = 0; $t < $m; $t++) {
            [$type, $cp] = $tokens[$t];
            if ($type === 'trans' || $type === 'other') {
                $outCps[] = $cp;

                continue;
            }

            $prevConnects = self::prevConnects($tokens, $t);
            if ($type === 'lig') {
                [$iso, $fin] = self::LAM_ALEF[$cp];
                $outCps[] = $prevConnects ? $fin : $iso;

                continue;
            }

            $forms = self::FORMS[$cp];
            $nextConnects = self::nextConnects($tokens, $t);
            if ($prevConnects && $nextConnects && $forms[self::MEDIAL] !== null) {
                $outCps[] = $forms[self::MEDIAL];
            } elseif ($prevConnects && $forms[self::FINAL] !== null) {
                $outCps[] = $forms[self::FINAL];
            } elseif ($nextConnects && $forms[self::INITIAL] !== null) {
                $outCps[] = $forms[self::INITIAL];
            } else {
                $outCps[] = $forms[self::ISOLATED];
            }
        }

        // an RTL run drawn by an LTR-only layout engine must be handed over back-to-front —
        // reversing the whole shaped run (ligatures stay a single unit) is what makes the first
        // glyph dompdf draws end up on the visual left, matching normal RTL reading order.
        $outCps = array_reverse($outCps);

        return implode('', array_map(static fn (int $cp): string => mb_chr($cp, 'UTF-8'), $outCps));
    }

    /** Does the nearest preceding (non-diacritic) token extend a cursive join into this one? */
    private static function prevConnects(array $tokens, int $index): bool
    {
        for ($k = $index - 1; $k >= 0; $k--) {
            [$type, $cp] = $tokens[$k];
            if ($type === 'trans') {
                continue;
            }
            if ($type === 'ltr') {
                return self::FORMS[$cp][self::INITIAL] !== null; // that letter is dual-joining
            }

            return false; // a lam-alef ligature or the absorbed space never extends forward
        }

        return false;
    }

    /** Does this letter (dual-joining) extend a cursive join into the next joinable token? */
    private static function nextConnects(array $tokens, int $index): bool
    {
        $cp = $tokens[$index][1];
        if (self::FORMS[$cp][self::INITIAL] === null) {
            return false;
        }

        for ($k = $index + 1; $k < count($tokens); $k++) {
            [$type, $ncp] = $tokens[$k];
            if ($type === 'trans') {
                continue;
            }
            if ($type === 'ltr') {
                return self::FORMS[$ncp][self::FINAL] !== null; // that letter can receive a join
            }
            if ($type === 'lig') {
                return true; // a lam-alef ligature can always receive a join
            }

            return false; // the absorbed space
        }

        return false;
    }
}
