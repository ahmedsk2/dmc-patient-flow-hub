<?php

namespace DMC\Reports;

use mysqli;

/**
 * DMC\Reports\YearlyReport — yearly statistics aggregates for the unit.
 *
 * The first slice extracted behind the Phase-4 src/ layer. It encapsulates the per-month /
 * per-consultant aggregates the reports need, computed with grouped GROUP BY queries whose metric
 * DEFINITIONS mirror the HTML A4 report (statistics/a4.php) exactly. The legacy helper
 * statistics/report_data.php::dmc_yearly_report_data() now delegates here, so existing callers
 * (pdf-report.php) and the equivalence check (tools/report_data_validate.php, proving these
 * figures match a4.php for 2023 & 2024) are unchanged.
 *
 * a4.php itself is intentionally NOT rewired — its HTML output stays golden-master-locked.
 */
class YearlyReport
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /** Returns the full report payload (arrays ordered January..December). */
    public function data(int $year): array
    {
        $yStart = sprintf('%04d-01-01', $year);
        $yEnd   = sprintf('%04d-12-31', $year);
        $nonIcu = " AND (current_location != 'ICU' or current_location is null)";

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = date('F', mktime(0, 0, 0, $m, 10, $year));
        }

        $adm      = $this->countByMonth('picupatients', 'ADMDATE', $yStart, $yEnd, $nonIcu);
        $dis      = $this->countByMonth('picupatients', 'DISDATE', $yStart, $yEnd, $nonIcu);
        $toicu    = $this->countByMonth('picupatients', 'DISDATE', $yStart, $yEnd, " AND DISTO = 'Intensive Care (ICU)'");
        $consults = $this->countByMonth('consultations', 'consultation_date', $yStart, $yEnd, '');
        $signoffs = $this->countByMonth('consultations', 'signoff_date', $yStart, $yEnd, '');

        $los    = $this->losByMonth('DISDATE', $yStart, $yEnd, $nonIcu);
        $mLos   = $this->losByMonth('med_DISDATE', $yStart, $yEnd, $nonIcu);
        $icuLos = $this->losByMonth('DISDATE', $yStart, $yEnd, " AND current_location ='ICU'");

        $readmission = $this->readmissionByMonth($yStart, $yEnd);

        [$consultantName, $consultantLOS] = $this->consultantLos($yStart, $yEnd, $nonIcu);
        $dish = $this->destinations($yStart, $yEnd, $nonIcu);

        return [
            'year'           => $year,
            'months'         => $months,
            'admissions'     => array_values($adm),
            'discharges'     => array_values($dis),
            'toicu'          => array_values($toicu),
            'newconsults'    => array_values($consults),
            'signedoff'      => array_values($signoffs),
            'readmission'    => array_values($readmission),
            'LOS'            => array_values($los),
            'm_LOS'          => array_values($mLos),
            'icuLOS'         => array_values($icuLos),
            'consultantName' => $consultantName,
            'consultantLOS'  => $consultantLOS,
            'destinations'   => $dish,
        ];
    }

    /** GROUP BY calendar month over [start,end] (inclusive), $extra appended to WHERE. -> [1..12 => int]. */
    private function countByMonth(string $table, string $col, string $start, string $end, string $extra = ''): array
    {
        $sql = "SELECT MONTH($col) m, COUNT(*) c FROM $table WHERE $col BETWEEN ? AND ?$extra GROUP BY m";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $r = $stmt->get_result();
        $out = array_fill(1, 12, 0);
        while ($row = $r->fetch_assoc()) {
            $out[(int) $row['m']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Average (endCol - ADMDATE) in days per month, computed in PHP exactly as a4.php does
     * (abs(strtotime diff)/86400; Asia/Riyadh has no DST so these are exact integer days).
     * Returns [1..12 => "x.xx" string when avg>0, else int 0].
     */
    private function losByMonth(string $endCol, string $start, string $end, string $extra): array
    {
        $sql = "SELECT MONTH(DISDATE) m, ADMDATE, $endCol AS endd FROM picupatients
                WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ?$extra";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $r = $stmt->get_result();
        $buckets = array_fill(1, 12, []);
        while ($row = $r->fetch_assoc()) {
            $buckets[(int) $row['m']][] = abs(strtotime($row['ADMDATE']) - strtotime($row['endd'])) / 86400;
        }
        $out = array_fill(1, 12, 0);
        foreach ($buckets as $m => $losList) {
            if (count($losList) > 0) {
                $avg = array_sum($losList) / count($losList);
                if ($avg > 0) {
                    $out[$m] = number_format($avg, 2, '.', '');
                }
            }
        }
        return $out;
    }

    /** 72h readmissions per month via one EXISTS query (same predicate a4.php's collapsed query uses). */
    private function readmissionByMonth(string $yStart, string $yEnd): array
    {
        $out = array_fill(1, 12, 0);
        $sql = "SELECT MONTH(a.ADMDATE) m, COUNT(*) c FROM picupatients a
                WHERE a.ADMDATE BETWEEN ? AND ?
                  AND EXISTS (SELECT 1 FROM picupatients p
                              WHERE p.DISDATE + INTERVAL 3 DAY >= a.ADMDATE AND p.ID < a.ID AND p.MRN = a.MRN
                                AND (p.trans_discharge = 'discharge from ICU' or p.trans_discharge='discharge from ward' or p.trans_discharge IS NULL))
                GROUP BY m";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ss', $yStart, $yEnd);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $out[(int) $row['m']] = (int) $row['c'];
        }
        return $out;
    }

    /** Per-consultant (position 3, active 1) average LOS for the year. -> [names[], los[]]. */
    private function consultantLos(string $yStart, string $yEnd, string $nonIcu): array
    {
        $names = [];
        $losOut = [];
        $consultants = $this->db->query("SELECT * FROM members WHERE position = '3' AND active = 1")->fetch_all(MYSQLI_ASSOC);
        $stmt = $this->db->prepare("SELECT ADMDATE, DISDATE FROM picupatients
            WHERE DISDATE IS NOT NULL AND consultant_id = ? AND DISDATE BETWEEN ? AND ?$nonIcu");
        foreach ($consultants as $c) {
            $cid = (int) $c['member_id'];
            $stmt->bind_param('iss', $cid, $yStart, $yEnd);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $days = [];
            foreach ($rows as $d) {
                $days[] = abs(strtotime($d['ADMDATE']) - strtotime($d['DISDATE'])) / 86400;
            }
            $avg = count($days) ? array_sum($days) / count($days) : 0;
            $names[]  = $c['full_name'];
            $losOut[] = $avg > 0 ? number_format($avg, 2, '.', '') : 0;
        }
        return [$names, $losOut];
    }

    /** Discharge / transfer destinations for the year (matches a4.php's category map + reverse). */
    private function destinations(string $yStart, string $yEnd, string $nonIcu): array
    {
        $dish = ['To Other Specilaity' => 0];
        $stmt = $this->db->prepare("SELECT DISTO, COUNT(*) c FROM picupatients
            WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ?$nonIcu GROUP BY DISTO");
        $stmt->bind_param('ss', $yStart, $yEnd);
        $stmt->execute();
        $r = $stmt->get_result();
        $known = ['Intensive Care (ICU)', 'Home', 'Mortuary', 'Other Facility', 'Absconded', 'LAMA'];
        while ($row = $r->fetch_assoc()) {
            if (in_array($row['DISTO'], $known, true)) {
                $dish[$row['DISTO']] = (int) $row['c'];
            } else {
                $dish['To Other Specilaity'] += (int) $row['c'];
            }
        }
        return array_reverse($dish); // a4.php reverses $dishtransnumbers before output (match it)
    }
}
