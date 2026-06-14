<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditFilterRequest;
use App\Models\AuditLog;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 — Item 1: admin-only audit-log viewer. Paginated + filterable (actor, action,
 * entity, date range, IP, category), with CSV/XLSX export sharing ONE writeExport pass (same
 * single-source pattern as RegistryController, so the two formats can never drift). Item 5
 * surfaces the hash-chain "integrity verified through {date}" indicator.
 */
class AuditController extends Controller
{
    /** PHI-read actions (Item 3) get their own viewer category so break-glass events stand out. */
    private const PHI_READ_ACTIONS = ['registry.search', 'registry.export', 'registry.export_xlsx', 'registry.open'];

    public function index(AuditFilterRequest $request): Response
    {
        $logs = $this->filtered($request)
            ->with('actor:id,full_name,name')
            ->latest('created_at')->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn (AuditLog $row) => [
                'id' => $row->id,
                'actor_id' => $row->actor_id,
                'actor_name' => $row->actor_name ?? $row->actor?->full_name ?? $row->actor?->name,
                'action' => $row->action,
                'category' => $this->category($row->action),
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'details' => $row->details,   // already array-cast
                'ip' => $row->ip,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only(['actor_id', 'action', 'entity_type', 'entity_id', 'category', 'from', 'to', 'ip']),
            // option lists — distinct values actually present, capped (the viewer is admin-only)
            'actors' => AuditLog::query()->whereNotNull('actor_id')
                ->select('actor_id', 'actor_name')->distinct()->orderBy('actor_name')->limit(100)
                ->get()->map(fn ($r) => ['id' => $r->actor_id, 'name' => $r->actor_name])->values(),
            'entityTypes' => AuditLog::query()->whereNotNull('entity_type')
                ->distinct()->orderBy('entity_type')->limit(100)->pluck('entity_type'),
            'categories' => $this->presentCategories(),
            // Item 5 — last row whose hash is stamped (NOT a full chain walk; that's audit:verify)
            'integrityThrough' => optional(
                AuditLog::whereNotNull('row_hash')->latest('id')->value('created_at')
            )?->toIso8601String(),
        ]);
    }

    /** Shared query builder for index + export — applies every filter via ->when() chains. */
    private function filtered(AuditFilterRequest $request)
    {
        return AuditLog::query()
            ->when($request->input('actor_id'), fn ($q, $v) => $q->where('actor_id', $v))
            ->when($request->input('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->input('entity_type'), fn ($q, $v) => $q->where('entity_type', $v))
            ->when($request->input('entity_id'), fn ($q, $v) => $q->where('entity_id', $v))
            ->when($request->input('ip'), fn ($q, $v) => $q->where('ip', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            // category filter: match any action whose prefix is the chosen category (PHI-read is the
            // explicit set of break-glass actions, not a single dotted prefix)
            ->when($request->input('category'), function ($q, $cat) {
                if ($cat === 'phi_read') {
                    return $q->whereIn('action', self::PHI_READ_ACTIONS);
                }

                return $q->where('action', 'like', $cat . '.%');
            });
    }

    /** Derive the viewer category from an action: PHI reads are grouped; else the dotted prefix. */
    private function category(string $action): string
    {
        if (in_array($action, self::PHI_READ_ACTIONS, true)) {
            return 'phi_read';
        }

        return explode('.', $action)[0];
    }

    /** Unique categories present in the table (for the filter dropdown), PHI-read surfaced first. */
    private function presentCategories(): array
    {
        $actions = AuditLog::query()->distinct()->orderBy('action')->limit(200)->pluck('action');
        $cats = $actions->map(fn ($a) => $this->category($a))->unique()->values()->all();
        // stable, with phi_read pinned to the front when present
        sort($cats);
        if (($i = array_search('phi_read', $cats, true)) !== false) {
            unset($cats[$i]);
            array_unshift($cats, 'phi_read');
        }

        return array_values($cats);
    }

    /* ---------- Exports (CSV + XLSX through ONE writeExport, like RegistryController) ---------- */

    /** Legacy export filename convention — Audit-Export-DD-MM-YYYY (RegistryController parity). */
    private function exportFilename(string $ext): string
    {
        return 'Audit-Export-' . now()->format('d-m-Y') . '.' . $ext;
    }

    public function export(AuditFilterRequest $request): StreamedResponse
    {
        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            $this->writeExport($request, fn (array $row) => fputcsv($out, array_map(self::csvSafe(...), $row)));
            fclose($out);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    public function exportXlsx(AuditFilterRequest $request): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aud') . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($tmp);
        $this->writeExport($request, fn (array $row) => $writer->addRow(Row::fromValues(
            array_map(fn ($v) => $v ?? '', $row))));
        $writer->close();

        return response()->download($tmp, $this->exportFilename('xlsx'))->deleteFileAfterSend();
    }

    /**
     * Neutralise spreadsheet formula injection in CSV cells starting with = + - @ (Excel would
     * evaluate them) — RegistryController parity. CSV only; the XLSX writer emits typed strings.
     */
    private static function csvSafe(mixed $v): mixed
    {
        return preg_match('/^[=+\-@]/', (string) ($v ?? '')) ? "'" . $v : $v;
    }

    /**
     * Header + rows through $write — shared by CSV and XLSX so they can never drift. Audit columns
     * carry no PHI directly (PHI lives in details JSON, exported as the raw JSON string for
     * downstream parsing). Same filters as index, chunked (no pagination).
     */
    private function writeExport(AuditFilterRequest $request, callable $write): void
    {
        $write(['ID', 'Date/Time', 'Actor', 'Action', 'Entity Type', 'Entity ID', 'Details (JSON)', 'IP']);

        $this->filtered($request)->orderBy('id')->chunk(500, function ($chunk) use ($write) {
            foreach ($chunk as $row) {
                $write([
                    $row->id,
                    optional($row->created_at)->toDateTimeString(),
                    $row->actor_name,
                    $row->action,
                    $row->entity_type,
                    $row->entity_id,
                    $row->details === null ? '' : json_encode($row->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $row->ip,
                ]);
            }
        });
    }
}
