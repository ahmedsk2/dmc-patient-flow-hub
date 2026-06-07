<?php
/**
 * xlsx-writer.php — minimal, dependency-free .xlsx (Office Open XML) writer.
 *
 * Replaces the end-of-life PHPExcel (abandoned upstream; known CVEs) for the registry
 * Excel export. Scope is deliberately small — exactly what the export needs: one worksheet,
 * string/number cells, an optional bold header row, and approximate column auto-width.
 *
 * Why a bespoke writer rather than vendoring PhpSpreadsheet: this app has no Composer, and
 * PhpSpreadsheet pulls a multi-package dependency tree (markbaker/matrix, markbaker/complex,
 * psr/simple-cache, …) that is fragile to assemble and maintain by hand. For a plain tabular
 * export, ~150 lines built on PHP's bundled ext-zip is auditable, has zero third-party code,
 * and removes (rather than re-adds) attack surface.
 *
 * An .xlsx file is just a ZIP of XML parts. We emit the minimal valid set. All text is UTF-8
 * and XML-escaped; cells are written as inline strings (t="inlineStr") so we don't need a
 * shared-strings table. Requires the bundled `zip` extension.
 */

if (!class_exists('XlsxWriter')) {

class XlsxWriter
{
    /** @var array<int,array<int,string|int|float|null>> rows of cells */
    private $rows = [];
    /** @var int number of leading rows rendered bold (header) */
    private $headerRows = 0;

    public function addRow(array $cells): void { $this->rows[] = array_values($cells); }
    public function setHeaderRows(int $n): void { $this->headerRows = max(0, $n); }

    /** XML-escape for text nodes/attributes (UTF-8). */
    private function esc($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** 0-based column index -> Excel column name (0->A, 25->Z, 26->AA). */
    private function colName(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)) . $s;
        }
        return $s;
    }

    private function worksheetXml(): string
    {
        // Approximate auto-width: widest cell per column, clamped to a sane range.
        $widths = [];
        foreach ($this->rows as $r) {
            foreach ($r as $ci => $val) {
                $len = mb_strlen((string)$val, 'UTF-8');
                if (!isset($widths[$ci]) || $len > $widths[$ci]) { $widths[$ci] = $len; }
            }
        }
        $cols = '';
        if ($widths) {
            ksort($widths);
            $cols .= '<cols>';
            foreach ($widths as $ci => $w) {
                $width = min(max($w + 2, 8), 60); // clamp 8..60 chars
                $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        $data = '<sheetData>';
        foreach ($this->rows as $ri => $r) {
            $rowNum = $ri + 1;
            $bold = $ri < $this->headerRows;
            $data .= '<row r="' . $rowNum . '">';
            foreach ($r as $ci => $val) {
                $ref = $this->colName($ci) . $rowNum;
                $style = $bold ? ' s="1"' : '';
                if (is_int($val) || is_float($val)) {
                    $data .= '<c r="' . $ref . '"' . $style . '><v>' . $val . '</v></c>';
                } else {
                    $data .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                          . $this->esc($val) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $data .= '</sheetData>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $cols . $data . '</worksheet>';
    }

    private function stylesXml(): string
    {
        // Index 0 = default; index 1 = bold (used for the header row via s="1").
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    /** Build the .xlsx into a temp file and return its path (caller streams/unlinks it). */
    public function buildToTempFile(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to build .xlsx files.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) { throw new RuntimeException('Could not create a temp file for the export.'); }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the .xlsx archive for writing.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', $this->workbookXml());

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        if ($zip->close() !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not finalize the .xlsx archive.');
        }
        return $tmp;
    }

    /** Stream the workbook to the browser as a download, then delete the temp file. */
    public function download(string $fileName): void
    {
        $path = $this->buildToTempFile();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: max-age=0');
        readfile($path);
        @unlink($path);
    }
}

}
