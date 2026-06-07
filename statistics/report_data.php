<?php
/**
 * statistics/report_data.php — backward-compatible shim.
 *
 * The implementation now lives in the Phase-4 src/ layer: DMC\Reports\YearlyReport. This function
 * is kept so existing procedural callers (pdf-report.php) and the equivalence check
 * (tools/report_data_validate.php) work unchanged. New code should use the class directly:
 *
 *   require_once __DIR__ . '/../autoload.php';
 *   $data = (new \DMC\Reports\YearlyReport($mysqli))->data($year);
 */

require_once __DIR__ . '/../autoload.php';

if (!function_exists('dmc_yearly_report_data')) {
    function dmc_yearly_report_data(mysqli $mysqli, int $year): array
    {
        return (new \DMC\Reports\YearlyReport($mysqli))->data($year);
    }
}
