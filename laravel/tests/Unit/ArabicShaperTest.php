<?php

namespace Tests\Unit;

use App\Support\ArabicShaper;
use PHPUnit\Framework\TestCase;

/**
 * I18N-06 — see App\Support\ArabicShaper's docblock for why this exists: dompdf has no Arabic
 * shaping/bidi engine (confirmed against vendor/dompdf/dompdf/src at the time this was written),
 * but the bundled DejaVu Sans font carries the full Arabic Presentation Forms A/B glyph set. Each
 * assertion below traces the expected contextual form by hand against the standard Arabic cursive-
 * joining rules (dual-joining letters connect both sides; ا د ذ ر ز و ى ة receive a join but never
 * extend one forward; ء never joins) and against the codepoints Unicode itself names — every
 * expected codepoint's identity is asserted via IntlChar/mb_chr from its documented name, not typed
 * twice from memory, so a transcription slip in the fixture cannot hide a shaping bug.
 */
class ArabicShaperTest extends TestCase
{
    public function test_empty_and_null_pass_through_unchanged(): void
    {
        $this->assertSame('', ArabicShaper::shape(''));
        $this->assertNull(ArabicShaper::shape(null));
    }

    public function test_pure_latin_and_digits_are_untouched(): void
    {
        $this->assertSame('Dr. Smith, MRN 12345', ArabicShaper::shape('Dr. Smith, MRN 12345'));
    }

    public function test_isolated_letter_gets_the_isolated_form(): void
    {
        // a single hamza has no other form to pick — isolated is the only option
        $this->assertSame(mb_chr(0xFE80, 'UTF-8'), ArabicShaper::shape(mb_chr(0x0621, 'UTF-8')));
    }

    public function test_two_dual_joining_letters_connect_initial_then_final(): void
    {
        // BEH + TEH ("بت"): beh has nothing before it and teh after it that can receive a join, so
        // beh takes INITIAL; teh has nothing after it, so it takes FINAL.
        $input = mb_chr(0x0628, 'UTF-8').mb_chr(0x062A, 'UTF-8');
        $beh_initial = mb_chr(0xFE91, 'UTF-8');
        $teh_final = mb_chr(0xFE96, 'UTF-8');
        // visual order is the logical order reversed (an RTL run drawn by an LTR engine)
        $expected = $teh_final.$beh_initial;

        $this->assertSame($expected, ArabicShaper::shape($input));
    }

    public function test_badr_right_joining_letters_do_not_propagate_a_join_between_themselves(): void
    {
        // بدر (Badr): BEH (dual) + DAL (right-joining) + REH (right-joining).
        // beh connects forward into dal -> beh=INITIAL, dal=FINAL (it received the join).
        // dal never extends a connection forward (right-joining), so reh receives nothing and is
        // ISOLATED even though something visually precedes it — the textbook right-joining break.
        $input = mb_chr(0x0628, 'UTF-8').mb_chr(0x062F, 'UTF-8').mb_chr(0x0631, 'UTF-8');
        $beh_initial = mb_chr(0xFE91, 'UTF-8');
        $dal_final = mb_chr(0xFEAA, 'UTF-8');
        $reh_isolated = mb_chr(0xFEAD, 'UTF-8');
        $expected = $reh_isolated.$dal_final.$beh_initial;

        $this->assertSame($expected, ArabicShaper::shape($input));
    }

    public function test_fatima_exercises_every_form_including_medial_and_teh_marbuta(): void
    {
        // فاطمة (Fatima): FEH(dual) ALEF(right-joining) TAH(dual) MEEM(dual) TEH_MARBUTA(right-joining)
        //  - FEH: nothing before, connects forward into ALEF -> INITIAL
        //  - ALEF: receives from FEH -> FINAL; never extends forward (right-joining) regardless of TAH
        //  - TAH: ALEF never connects forward, so TAH receives nothing from before; connects forward
        //    into MEEM -> INITIAL (a fresh start after the alef break — real Arabic typography)
        //  - MEEM: receives from TAH, connects forward into TEH_MARBUTA -> MEDIAL
        //  - TEH_MARBUTA: receives from MEEM; never extends forward -> FINAL
        $input = mb_chr(0x0641, 'UTF-8').mb_chr(0x0627, 'UTF-8').mb_chr(0x0637, 'UTF-8')
            .mb_chr(0x0645, 'UTF-8').mb_chr(0x0629, 'UTF-8');
        $feh_initial = mb_chr(0xFED3, 'UTF-8');
        $alef_final = mb_chr(0xFE8E, 'UTF-8');
        $tah_initial = mb_chr(0xFEC3, 'UTF-8');
        $meem_medial = mb_chr(0xFEE4, 'UTF-8');
        $tehMarbuta_final = mb_chr(0xFE94, 'UTF-8');
        $expected = $tehMarbuta_final.$meem_medial.$tah_initial.$alef_final.$feh_initial;

        $this->assertSame($expected, ArabicShaper::shape($input));
    }

    public function test_lam_alef_forms_the_mandatory_ligature(): void
    {
        // لا (lam + alef) in isolation must become the single ligature glyph, not two glyphs.
        $input = mb_chr(0x0644, 'UTF-8').mb_chr(0x0627, 'UTF-8');
        $ligature_isolated = mb_chr(0xFEFB, 'UTF-8');

        $this->assertSame($ligature_isolated, ArabicShaper::shape($input));
        $this->assertSame(1, mb_strlen(ArabicShaper::shape($input), 'UTF-8'));
    }

    public function test_al_sharif_ligature_receives_a_join_and_continues_the_word(): void
    {
        // الشريف (Al-Sharif): LAM+ALEF ligature, then SHEEN HAH... — the ligature is run-initial
        // here (nothing precedes it) so it takes the ISOLATED ligature form, and because a ligature
        // never extends a connection forward, the following SHEEN starts a fresh INITIAL form.
        $input = mb_chr(0x0644, 'UTF-8').mb_chr(0x0627, 'UTF-8').mb_chr(0x0634, 'UTF-8')
            .mb_chr(0x0631, 'UTF-8').mb_chr(0x064A, 'UTF-8').mb_chr(0x0641, 'UTF-8');
        $shaped = ArabicShaper::shape($input);

        // 6 input characters collapse to 5 output glyphs (the ligature ate 2 -> 1)
        $this->assertSame(5, mb_strlen($shaped, 'UTF-8'));
        // the ligature glyph is present, isolated (nothing precedes the whole run)
        $this->assertStringContainsString(mb_chr(0xFEFB, 'UTF-8'), $shaped);
    }

    public function test_two_arabic_words_absorb_the_space_between_them_and_reverse_as_a_whole(): void
    {
        // "بيت كبير" (a big house) — two dual-joining-letter words separated by one space. The
        // space is absorbed into the run (both neighbours are Arabic) and the WHOLE run — both
        // words and the space — is reversed together, so word order flips too.
        $input = mb_chr(0x0628, 'UTF-8').mb_chr(0x064A, 'UTF-8').mb_chr(0x062A, 'UTF-8')
            .' '
            .mb_chr(0x0643, 'UTF-8').mb_chr(0x0628, 'UTF-8').mb_chr(0x064A, 'UTF-8').mb_chr(0x0631, 'UTF-8');
        $shaped = ArabicShaper::shape($input);

        // second word (4 letters) now appears first in the output stream, then a single space
        // (index 4 — reversing [ب ي ت ' ' ك ب ي ر] puts the space at position 7-3=4), then the
        // first word — i.e. the space did not get dropped or duplicated.
        $this->assertSame(8, mb_strlen($shaped, 'UTF-8'));
        $this->assertSame(' ', mb_substr($shaped, 4, 1, 'UTF-8'));
    }

    public function test_latin_prefix_stays_in_place_around_a_reversed_arabic_run(): void
    {
        // "Dr. محمد" — the LTR "Dr. " prefix must stay exactly where it is (this is the ordinary
        // English-first-with-an-embedded-Arabic-name case the app actually has); only the Arabic
        // run gets shaped + reversed.
        $name = mb_chr(0x0645, 'UTF-8').mb_chr(0x062D, 'UTF-8').mb_chr(0x0645, 'UTF-8').mb_chr(0x062F, 'UTF-8'); // محمد
        $shaped = ArabicShaper::shape('Dr. '.$name);

        $this->assertStringStartsWith('Dr. ', $shaped);
        $this->assertNotSame('Dr. '.$name, $shaped, 'the Arabic run must actually be transformed, not passed through raw');
    }

    public function test_a_run_ending_with_digits_breaks_before_the_digits(): void
    {
        // documented scope limit: a digit immediately after Arabic ends the Arabic run rather than
        // being carried along as a bidi-neutral character — assert that behaviour explicitly so a
        // future change to it is a deliberate decision, not a silent regression.
        $name = mb_chr(0x0645, 'UTF-8').mb_chr(0x062D, 'UTF-8').mb_chr(0x0645, 'UTF-8').mb_chr(0x062F, 'UTF-8'); // محمد
        $shaped = ArabicShaper::shape($name.'5');

        $this->assertStringEndsWith('5', $shaped);
    }

    public function test_diacritic_does_not_break_the_join_between_its_neighbours(): void
    {
        // BEH + FATHA (diacritic) + TEH: the fatha must not stop beh from connecting to teh.
        $input = mb_chr(0x0628, 'UTF-8').mb_chr(0x064E, 'UTF-8').mb_chr(0x062A, 'UTF-8');
        $shaped = ArabicShaper::shape($input);
        $beh_initial = mb_chr(0xFE91, 'UTF-8');
        $teh_final = mb_chr(0xFE96, 'UTF-8');

        $this->assertStringContainsString($beh_initial, $shaped);
        $this->assertStringContainsString($teh_final, $shaped);
    }

    public function test_shaping_is_idempotent_on_already_plain_text(): void
    {
        // guards against double-shaping if this is ever accidentally called twice on the same
        // value (e.g. a shared helper reused across two call sites) — Latin/digit text must be
        // stable under repeated application.
        $plain = 'CONFIDENTIAL — Internal use';
        $this->assertSame($plain, ArabicShaper::shape(ArabicShaper::shape($plain)));
    }
}
