<?php
/**
 * pdf-report.php — server-side PDF of the yearly statistics (admin only).
 *
 *   pdf-report.php?y=2024
 *
 * Uses the vendored FPDF (vendor/fpdf) for real, downloadable PDF output (vs the browser-print
 * HTML report). The numbers come from statistics/report_data.php, which is proven to match
 * a4.php's figures exactly (tools/report_data_validate.php). No HTML is emitted before the PDF,
 * so the binary stream is clean.
 */
require_once __DIR__ . '/guard.php';
require_role([0]); // Admin only — same gate as the HTML stats reports
require __DIR__ . '/dbconnect.php';
require_once __DIR__ . '/statistics/report_data.php';
require_once __DIR__ . '/vendor/fpdf/fpdf.php';

$year = isset($_GET['y']) ? (int) $_GET['y'] : (int) date('Y');
$d = dmc_yearly_report_data($mysqli, $year);

$pdf = new FPDF('L', 'mm', 'A4'); // landscape, like the HTML report
$pdf->SetTitle('DMC Internal Medicine — ' . $year . ' Statistics');
$pdf->SetCreator('DMC Patient-Flow Hub');
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

// ---- title ----
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->Cell(0, 10, 'DMC Internal Medicine - ' . $year . ' Statistics', 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 5, 'Generated ' . date('Y-m-d H:i'), 0, 1, 'C');
$pdf->Ln(3);

// ---- monthly KPI table ----
$cols = [
    ['Month', 30, 'L'], ['Adm', 18, 'R'], ['Disch', 18, 'R'], ['->ICU', 18, 'R'],
    ['Consults', 22, 'R'], ['Sign-offs', 22, 'R'], ['Readmit', 20, 'R'],
    ['LOS', 20, 'R'], ['Med LOS', 22, 'R'], ['ICU LOS', 22, 'R'],
];
$header = function () use ($pdf, $cols) {
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(41, 134, 204);
    $pdf->SetTextColor(255);
    foreach ($cols as [$t, $w, $a]) {
        $pdf->Cell($w, 7, $t, 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(0);
};
$header();

$pdf->SetFont('Helvetica', '', 9);
$fill = false;
$tot = ['adm' => 0, 'dis' => 0, 'icu' => 0, 'con' => 0, 'sgn' => 0, 're' => 0];
for ($i = 0; $i < 12; $i++) {
    $pdf->SetFillColor(238, 242, 248);
    $row = [
        $d['months'][$i],
        (string) $d['admissions'][$i], (string) $d['discharges'][$i], (string) $d['toicu'][$i],
        (string) $d['newconsults'][$i], (string) $d['signedoff'][$i], (string) $d['readmission'][$i],
        (string) $d['LOS'][$i], (string) $d['m_LOS'][$i], (string) $d['icuLOS'][$i],
    ];
    foreach ($cols as $k => [$t, $w, $a]) {
        $pdf->Cell($w, 6, $row[$k], 1, 0, $a, $fill);
    }
    $pdf->Ln();
    $fill = !$fill;
    $tot['adm'] += $d['admissions'][$i]; $tot['dis'] += $d['discharges'][$i]; $tot['icu'] += $d['toicu'][$i];
    $tot['con'] += $d['newconsults'][$i]; $tot['sgn'] += $d['signedoff'][$i]; $tot['re'] += $d['readmission'][$i];
}
// totals row (counts only; LOS columns are averages, shown as '-')
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(220);
$totRow = ['Total', $tot['adm'], $tot['dis'], $tot['icu'], $tot['con'], $tot['sgn'], $tot['re'], '-', '-', '-'];
foreach ($cols as $k => [$t, $w, $a]) {
    $pdf->Cell($w, 7, (string) $totRow[$k], 1, 0, $a, true);
}
$pdf->Ln(12);

// ---- per-consultant average LOS ----
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Average Length of Stay by Consultant', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(41, 134, 204);
$pdf->SetTextColor(255);
$pdf->Cell(120, 7, 'Consultant', 1, 0, 'L', true);
$pdf->Cell(40, 7, 'Avg LOS (days)', 1, 1, 'R', true);
$pdf->SetTextColor(0);
$pdf->SetFont('Helvetica', '', 9);
$fill = false;
foreach ($d['consultantName'] as $i => $name) {
    $pdf->SetFillColor(238, 242, 248);
    $pdf->Cell(120, 6, 'Dr. ' . $name, 1, 0, 'L', $fill);
    $pdf->Cell(40, 6, (string) $d['consultantLOS'][$i], 1, 1, 'R', $fill);
    $fill = !$fill;
}
$pdf->Ln(8);

// ---- discharge / transfer destinations (year) ----
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Discharge / Transfer Destinations', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(41, 134, 204);
$pdf->SetTextColor(255);
$pdf->Cell(120, 7, 'Destination', 1, 0, 'L', true);
$pdf->Cell(40, 7, 'Count', 1, 1, 'R', true);
$pdf->SetTextColor(0);
$pdf->SetFont('Helvetica', '', 9);
$fill = false;
foreach ($d['destinations'] as $label => $count) {
    $pdf->SetFillColor(238, 242, 248);
    $pdf->Cell(120, 6, $label, 1, 0, 'L', $fill);
    $pdf->Cell(40, 6, (string) $count, 1, 1, 'R', $fill);
    $fill = !$fill;
}

$pdf->Output('I', 'DMC-statistics-' . $year . '.pdf');
