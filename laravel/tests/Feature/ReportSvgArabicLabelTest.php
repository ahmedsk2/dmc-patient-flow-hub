<?php

namespace Tests\Feature;

use App\Support\ArabicShaper;
use App\Support\ReportSvg;
use Barryvdh\DomPDF\Facade\Pdf;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * I18N-06, the chart half. The per-consultant bar charts in the report booklets are SVG images
 * embedded as data URIs, and their row labels are SVG <text> — a different rendering path from the
 * ordinary HTML text the other Arabic tests cover. It was reported on 2026-09-22 that a non-Latin
 * label there could come out as a literal "?", so this pins the label end to end: render the chart
 * through dompdf and read the text back out of the produced PDF.
 *
 * The page deliberately carries NO other Arabic, so the chart cannot be passing on the strength of
 * glyphs some earlier HTML text already pulled into the font — the label has to stand on its own.
 *
 * dompdf writes this text identity-encoded as UTF-16BE inside its content streams, which is why the
 * helper below decodes rather than greps.
 */
#[Group('pdf')]
class ReportSvgArabicLabelTest extends TestCase
{
    private const RAW_NAME = 'د. محمد عبدالله';

    /** Every visible text run in a dompdf-produced PDF, decoded. */
    private function pdfText(string $pdf): string
    {
        $out = '';
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);
        foreach ($streams[1] as $stream) {
            $data = @gzuncompress($stream);
            if ($data === false) {
                $data = @gzinflate(substr($stream, 2));
            }
            if ($data === false) {
                $data = $stream;
            }
            if (! str_contains($data, ' Tf')) {
                continue;                       // not a content stream with text in it
            }
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $data, $runs);
            foreach ($runs[1] as $run) {
                $bytes = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', static fn ($m) => preg_match('/^[0-7]{1,3}$/', $m[1])
                    ? chr(octdec($m[1]))
                    : $m[1], $run);
                $out .= (string) @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE')."\n";
            }
        }

        return $out;
    }

    public function test_an_arabic_chart_label_reaches_the_pdf_as_glyphs_not_a_question_mark(): void
    {
        $shaped = ArabicShaper::shape(self::RAW_NAME);
        $chart = ReportSvg::hBar(
            [['name' => $shaped, 'value' => 7.5], ['name' => 'Dr Latin Name', 'value' => 4.25]],
            380, 60, '#2986cc', 'Avg Ward LOS'
        );

        $pdf = Pdf::loadHTML('<html><body style="font-family: DejaVu Sans"><table><tr>'
            .'<td>This page carries no other Arabic.</td><td>'.$chart.'</td></tr></table></body></html>')
            ->setPaper('a4', 'landscape')
            ->output();

        $this->assertStringStartsWith('%PDF', $pdf);
        $text = $this->pdfText($pdf);

        $this->assertStringContainsString($shaped, $text,
            'the shaped Arabic label must appear in the PDF text drawn from the chart');
        $this->assertStringNotContainsString(self::RAW_NAME, $text,
            'the raw unshaped form must not be what was drawn');
        // the failure this test exists for: the glyphs replaced by a placeholder
        $this->assertStringNotContainsString('?', $text,
            'a "?" in the drawn text means the label glyphs were dropped');
        // the chart's own Latin text still renders, so we know we read the right content stream
        $this->assertStringContainsString('Dr Latin Name', $text);
        $this->assertStringContainsString('Avg Ward LOS', $text);
    }
}
