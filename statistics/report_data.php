<?php
/**
 * statistics/report_data.php — shared yearly-report aggregates.
 *
 * dmc_yearly_report_data($mysqli, $year) returns the same per-month / per-consultant numbers the
 * HTML A4 report (a4.php) computes, but via grouped GROUP BY queries (not 12 per-metric loops).
 * The metric DEFINITIONS mirror a4.php exactly so the figures are identical — proven by
 * tools/report_data_validate.php, which diffs these arrays against a4.php's own JSON output.
 *
 * Used by pdf-report.php (server-side PDF). a4.php itself is left untouched (its HTML output is
 * golden-master-locked); this is an additive, independently-validated computation.
 *
 * Returned arrays are ordered January..December. LOS values mirror a4.php's quirk: a string
 * number_format(x,2) when > 0, else the integer 0.
 */

if (!function_exists('dmc_yearly_report_data')) {

    /** GROUP BY calendar month over [start,end] (inclusive), $extra appended to WHERE. -> [1..12 => int]. */
    function _dmc_count_by_month(mysqli $mysqli, string $table, string $col, string $start, string $end, string $extra = ''): array
    {
        $sql = "SELECT MONTH($col) m, COUNT(*) c FROM $table WHERE $col BETWEEN ? AND ?$extra GROUP BY m";
        $stmt = $mysqli->prepare($sql);
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
     * Average (col2 - ADMDATE) in days per month, computed in PHP exactly as a4.php does
     * (abs(strtotime diff)/86400; Asia/Riyadh has no DST so these are exact integer days).
     * $disCol is the discharge date that both bounds the month AND is the LOS end (DISDATE or
     * med_DISDATE). Returns [1..12 => "x.xx" string when avg>0, else int 0].
     */
    function _dmc_los_by_month(mysqli $mysqli, string $endCol, string $start, string $end, string $extra): array
    {
        $sql = "SELECT MONTH(DISDATE) m, ADMDATE, $endCol AS endd FROM picupatients
                WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ?$extra";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $r = $stmt->get_result();
        $buckets = array_fill(1, 12, []);
        while ($row = $r->fetch_assoc()) {
            $buckets[(int) $row['m']][] = abs(strtotime($row['ADMDATE']) - strtotime($row['endd'])) / 86400;
        }
        $out = array_fill(1, 12, 0);
        foreach ($buckets as $m => $los) {
            if (count($los) > 0) {
                $avg = array_sum($los) / count($los);
                if ($avg > 0) {
                    $out[$m] = number_format($avg, 2, '.', '');
                }
            }
        }
        return $out;
    }

    function dmc_yearly_report_data(mysqli $mysqli, int $year): array
    {
        $yStart = sprintf('%04d-01-01', $year);
        $yEnd   = sprintf('%04d-12-31', $year);
        $nonIcu = " AND (current_location != 'ICU' or current_location is null)";

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = date('F', mktime(0, 0, 0, $m, 10, $year));
        }

        // Per-month counts (definitions mirror a4.php).
        $adm = _dmc_count_by_month($mysqli, 'picupatients', 'ADMDATE', $yStart, $yEnd, $nonIcu);
        $dis = _dmc_count_by_month($mysqli, 'picupatients', 'DISDATE', $yStart, $yEnd, $nonIcu);
        $toicu = _dmc_count_by_month($mysqli, 'picupatients', 'DISDATE', $yStart, $yEnd, " AND DISTO = 'Intensive Care (ICU)'");
        $consults = _dmc_count_by_month($mysqli, 'consultations', 'consultation_date', $yStart, $yEnd, '');
        $signoffs = _dmc_count_by_month($mysqli, 'consultations', 'signoff_date', $yStart, $yEnd, '');

        // Per-month LOS (physical = ADM..DIS non-ICU; medical = ADM..med_DISDATE non-ICU; ICU = ADM..DIS in ICU).
        $los   = _dmc_los_by_month($mysqli, 'DISDATE', $yStart, $yEnd, $nonIcu);
        $mLos  = _dmc_los_by_month($mysqli, 'med_DISDATE', $yStart, $yEnd, $nonIcu);
        $icuLos = _dmc_los_by_month($mysqli, 'DISDATE', $yStart, $yEnd, " AND current_location ='ICU'");

        // Readmissions per month: one EXISTS query (same predicate a4.php's collapsed query uses).
        $readmission = array_fill(1, 12, 0);
        $rq = "SELECT MONTH(a.ADMDATE) m, COUNT(*) c FROM picupatients a
               WHERE a.ADMDATE BETWEEN ? AND ?
                 AND EXISTS (SELECT 1 FROM picupatients p
                             WHERE p.DISDATE + INTERVAL 3 DAY >= a.ADMDATE AND p.ID < a.ID AND p.MRN = a.MRN
                               AND (p.trans_discharge = 'discharge from ICU' or p.trans_discharge='discharge from ward' or p.trans_discharge IS NULL))
               GROUP BY m";
        $stmt = $mysqli->prepare($rq);
        $stmt->bind_param('ss', $yStart, $yEnd);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $readmission[(int) $row['m']] = (int) $row['c'];
        }

        // Per-consultant LOS for the year (position=3, active=1), avg ADM..DIS non-ICU.
        $consultantName = [];
        $consultantLOS  = [];
        $cs = $mysqli->query("SELECT * FROM members WHERE position = '3' AND active = 1");
        $consultants = $cs->fetch_all(MYSQLI_ASSOC);
        $losStmt = $mysqli->prepare("SELECT ADMDATE, DISDATE FROM picupatients
            WHERE DISDATE IS NOT NULL AND consultant_id = ? AND DISDATE BETWEEN ? AND ?$nonIcu");
        foreach ($consultants as $c) {
            $cid = (int) $c['member_id'];
            $losStmt->bind_param('iss', $cid, $yStart, $yEnd);
            $losStmt->execute();
            $rows = $losStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $days = [];
            foreach ($rows as $d) {
                $days[] = abs(strtotime($d['ADMDATE']) - strtotime($d['DISDATE'])) / 86400;
            }
            $avg = count($days) ? array_sum($days) / count($days) : 0;
            $consultantName[] = $c['full_name'];
            $consultantLOS[]  = $avg > 0 ? number_format($avg, 2, '.', '') : 0;
        }

        // Discharge destinations for the year (GROUP BY DISTO -> the same category map a4.php builds).
        $dish = ['To Other Specilaity' => 0];
        $dq = $mysqli->prepare("SELECT DISTO, COUNT(*) c FROM picupatients
            WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ?$nonIcu GROUP BY DISTO");
        $dq->bind_param('ss', $yStart, $yEnd);
        $dq->execute();
        $dr = $dq->get_result();
        $known = ['Intensive Care (ICU)', 'Home', 'Mortuary', 'Other Facility', 'Absconded', 'LAMA'];
        while ($row = $dr->fetch_assoc()) {
            if (in_array($row['DISTO'], $known, true)) {
                $dish[$row['DISTO']] = (int) $row['c'];
            } else {
                $dish['To Other Specilaity'] += (int) $row['c'];
            }
        }
        $dish = array_reverse($dish); // a4.php reverses $dishtransnumbers before output (match it)

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
}
