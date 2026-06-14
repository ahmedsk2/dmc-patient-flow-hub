<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admission extends Model
{
    protected static function booted(): void
    {
        // Keep the dashboard heavy-tier cache fresh: bust on any admission write so a
        // clinician's own admit/assign/discharge shows up immediately, not after the TTL.
        static::saved(fn () => \App\Support\DashboardCache::bust());
        static::deleted(fn () => \App\Support\DashboardCache::bust());
    }

    /**
     * Canonical "not in ICU" predicate — raw SQL form for the DB::table() analytics
     * (Dashboard/Statistics/Reports). Eloquent callers use scopeNonIcu(), which encodes
     * the SAME rule. Single source of truth: a definition change here moves every metric.
     */
    public const NON_ICU_SQL = "(current_location <> 'ICU' OR current_location IS NULL)";

    /**
     * Legacy doughnut buckets, fixed order (statistics/a4.php "Discharge / Transfer to").
     * Shared by Reports (annual + monthly booklets) and the Statistics destination donut.
     */
    public const DEST_BUCKETS = ['Intensive Care (ICU)', 'Home', 'Mortuary', 'Other Facility', 'Absconded', 'LAMA'];

    /**
     * Map raw discharge_to counts into the fixed legacy buckets (everything else, including
     * blank, => To Other Speciality). The ONE bucketing definition — do not duplicate.
     */
    public static function bucketizeDestinations(array $byDest): array
    {
        $buckets = array_fill_keys([...self::DEST_BUCKETS, 'To Other Speciality'], 0);
        foreach ($byDest as $dest => $c) {
            $buckets[in_array($dest, self::DEST_BUCKETS, true) ? $dest : 'To Other Speciality'] += (int) $c;
        }

        return $buckets;
    }

    /**
     * transfer_type values that are REAL discharges — valid readmission anchors.
     * Ward<->ICU / specialty transfers are continuations of care, never readmission anchors.
     * NULL-typed closed episodes (historical rows discharged without a transfer_type) ALSO count
     * as anchors — legacy accepted them everywhere (J1-4, deliberate definition change).
     * Used identically by Statistics, Registry and the board badge (guarded by StatisticsValueTest).
     */
    public const REAL_DISCHARGE_TYPES = ['discharge from ward', 'discharge from ICU'];

    /**
     * The ONE readmission JOIN predicate (admissions a JOIN admissions prev): a new admission
     * anchored to a prior REAL discharge (typed real discharge OR NULL-typed historical close)
     * of the same patient within the configured window. Shared by Statistics (headline KPI,
     * grid, per-consultant, drill-down) and Reports so the definition cannot drift.
     * Guarded by StatisticsValueTest + GapWave2Test + Round5J1Test.
     */
    public static function readmissionJoin(int $window): \Closure
    {
        return function ($j) use ($window) {
            $j->on('prev.patient_id', '=', 'a.patient_id')
              ->whereColumn('prev.discharge_date', '<=', 'a.admit_date')
              ->whereRaw('DATEDIFF(a.admit_date, prev.discharge_date) BETWEEN 0 AND ?', [$window])
              ->whereColumn('prev.id', '<>', 'a.id')
              ->where(fn ($w) => $w->whereIn('prev.transfer_type', self::REAL_DISCHARGE_TYPES)
                  ->orWhereNull('prev.transfer_type'));
        };
    }

    /**
     * The ONE readmission whereExists predicate: a row on the `admissions` table that is anchored
     * to a prior REAL discharge (typed real discharge OR NULL-typed historical close) of the same
     * patient within $window days. Pass to ->whereExists(...). Encodes the SAME rule as
     * readmissionJoin (which is the JOIN form Statistics/Reports use) from REAL_DISCHARGE_TYPES +
     * DATEDIFF, so the board badge, the registry readmit72 filter and the registry row badge can
     * no longer drift. $alias names the "prev" self-reference (default 'prev' matches every
     * existing inline copy); pass a distinct alias only if two readmission sub-queries nest.
     * Guarded by Round11N1Test + StatisticsValueTest + GapWave2Test.
     */
    public static function readmissionExists(int $window, string $alias = 'prev'): \Closure
    {
        return fn ($s) => $s->selectRaw('1')
            ->from("admissions as {$alias}")
            ->whereColumn("{$alias}.patient_id", 'admissions.patient_id')
            ->whereColumn("{$alias}.id", '<>', 'admissions.id')
            ->whereColumn("{$alias}.discharge_date", '<=', 'admissions.admit_date')
            ->whereRaw("DATEDIFF(admissions.admit_date, {$alias}.discharge_date) BETWEEN 0 AND ?", [$window])
            ->where(fn ($w) => $w->whereIn("{$alias}.transfer_type", self::REAL_DISCHARGE_TYPES)
                ->orWhereNull("{$alias}.transfer_type"));
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'admit_date' => 'date',
            'discharge_date' => 'date',
            'medical_discharge_date' => 'date',
            'assigned_on' => 'date',
            'assigned_at' => 'datetime',
            'is_longterm' => 'boolean',
            'is_new_assignment' => 'boolean',
        ];
    }

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function consultant(): BelongsTo { return $this->belongsTo(User::class, 'consultant_id'); }
    public function admittedBy(): BelongsTo { return $this->belongsTo(User::class, 'admitted_by'); }
    public function dischargedBy(): BelongsTo { return $this->belongsTo(User::class, 'discharged_by'); }
    public function diagnoses(): HasMany { return $this->hasMany(AdmissionDiagnosis::class); }
    public function handover() { return $this->hasOne(Handover::class); }
    public function handoverSignatures(): HasMany { return $this->hasMany(HandoverSignature::class); }

    /** Currently admitted (file not closed). */
    public function scopeActive(Builder $q): Builder { return $q->whereNull('discharge_date'); }

    public function scopeNonIcu(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('current_location', '<>', 'ICU')->orWhereNull('current_location'));
    }

    public function scopeIcu(Builder $q): Builder { return $q->where('current_location', 'ICU'); }

    /**
     * Settings-driven LOS band (short/mid/long) — the ONE banding rule, shared by the board,
     * the Recent registry and the Registry results so the colors can never drift.
     */
    public static function losBand(?int $los, Setting $settings): ?string
    {
        if ($los === null) {
            return null;
        }

        return $los < $settings->short_los ? 'short' : ($los > $settings->long_los ? 'long' : 'mid');
    }

    /**
     * Length of stay in days: admit → physical discharge, or TODAY while the file is open.
     * A phase-1 medical discharge does NOT freeze it — legacy kept counting for the
     * "discharged still in" patients until the bed was actually vacated (B6).
     */
    public function lengthOfStay(): ?int
    {
        if (! $this->admit_date) {
            return null;
        }
        $end = $this->discharge_date ?? now();
        // Carbon 3 returns a float from diffInDays(); cast explicitly (whole days) to avoid the
        // implicit float->int deprecation that fired once per active patient on the board.
        return (int) $this->admit_date->diffInDays($end);
    }
}
