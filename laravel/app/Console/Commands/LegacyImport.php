<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Transform the ORIGINAL renovated database (the `legacy` connection: dmc/dmc_prod) into the new
 * clean schema. Idempotent: truncates the new tables and re-derives everything. This is the user's
 * "migrate the original database easily" path.
 *
 *   php artisan legacy:import
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import {--fresh : wipe new tables first (default true)}';
    protected $description = 'Import & transform the original DMC database into the new clean schema';

    private $legacy;

    public function handle(): int
    {
        // Transforming the whole legacy DB (tens of thousands of episodes) materialises large result
        // sets; the CLI default of 128M is not enough. Raise the ceiling to 1G, but never LOWER an
        // admin's already-higher (or unlimited) setting.
        $this->ensureMemory('1024M');

        $this->legacy = DB::connection('legacy');
        $this->info('Importing from legacy connection: ' . $this->legacy->getDatabaseName());

        // Preserve explicit id=0 reference rows (legacy consultation_reason 'other'=0) instead of
        // letting an AUTO_INCREMENT column turn 0 into the next value.
        DB::statement("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");

        Schema::disableForeignKeyConstraints();
        // Handover + notification tables MUST be truncated whenever admissions are re-imported:
        // they reference admissions/users by id, and a fresh import re-seeds those ids — TRUNCATE
        // (with FK checks off) bypasses the cascade, so stale rows would otherwise survive and
        // point at the wrong (or missing) episodes after a new legacy dump is loaded.
        foreach (['handover_signatures', 'handover_revisions', 'handovers', 'notifications',
                  'admission_diagnoses', 'admissions', 'consultations', 'patients', 'icd10',
                  'specialties', 'consultation_reasons', 'tb_diagnoses', 'countries', 'settings'] as $t) {
            DB::table($t)->truncate();
        }
        DB::table('users')->whereNotNull('legacy_id')->delete();
        Schema::enableForeignKeyConstraints();

        $this->importReference();
        $userMap = $this->importUsers();
        $patientMap = $this->importPatients();
        $this->importAdmissions($userMap, $patientMap);
        $this->importConsultations($userMap, $patientMap);
        $this->importSettings();

        $this->newLine();
        $this->info('✔ Import complete.');
        $this->table(['Table', 'Rows'], collect(['users', 'patients', 'admissions', 'admission_diagnoses', 'consultations', 'icd10', 'specialties'])
            ->map(fn ($t) => [$t, number_format(DB::table($t)->count())])->all());
        return self::SUCCESS;
    }

    private function importReference(): void
    {
        // specialties (legacy speciality.specilaity; id 1 = Hospitalist => not a subspecialty)
        $rows = $this->legacy->table('speciality')->get()->map(fn ($r) => [
            'id' => $r->id, 'name' => trim($r->specilaity ?? 'Unknown'),
            'is_subspecialty' => ((int) $r->id !== 1), 'is_external' => false,
            'created_at' => now(), 'updated_at' => now(),
        ])->all();
        $this->insertBatched('specialties', $rows);

        // external/allied services (legacy other_specialities) into the SAME table, flagged
        // is_external. Legacy ids collide with speciality ids, so insert by NAME (skip any name
        // already present) and let auto-increment assign fresh ids. Idempotent: the truncate
        // above wipes both sets before each import.
        $taken = DB::table('specialties')->pluck('name')
            ->mapWithKeys(fn ($n) => [mb_strtolower(trim($n)) => true])->all();
        $external = [];
        foreach ($this->legacy->table('other_specialities')->get() as $r) {
            $name = trim($r->specilaity ?? '');
            if ($name === '' || isset($taken[mb_strtolower($name)])) { continue; }
            $taken[mb_strtolower($name)] = true;
            $external[] = ['name' => $name, 'is_subspecialty' => true, 'is_external' => true,
                'created_at' => now(), 'updated_at' => now()];
        }
        $this->insertBatched('specialties', $external);

        // icd10 (code + name)
        $this->legacy->table('icd10')->orderBy('autoid')->chunk(5000, function ($chunk) {
            $this->insertBatched('icd10', $chunk->map(fn ($r) => [
                'id' => $r->autoid, 'code' => trim((string) $r->id), 'name' => $r->name,
            ])->all());
        });

        $this->insertBatched('consultation_reasons', $this->legacy->table('consultation_reason')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->consultation_reason])->all());

        $this->insertBatched('tb_diagnoses', $this->legacy->table('tb_list')->get()
            ->map(fn ($r) => ['icd10_code' => trim((string) $r->dx_id)])->all());

        $this->insertBatched('countries', $this->legacy->table('countries')->get()
            ->map(fn ($r) => ['id' => $r->id ?? null, 'code' => $r->code ?? null, 'name' => $r->name])->all());

        $this->info('  reference tables imported');
    }

    private function importUsers(): array
    {
        $rows = [];
        $seen = [];        // legacy member_name is NOT unique (and the collation is case-insensitive);
                           // suffix the legacy id on collision so usernames stay unique + login still works.
        $seenEmail = [];   // users.email is UNIQUE (defense-in-depth). The lowest member_id keeps a
                           // shared address; later duplicates are nulled so the insert can't violate
                           // the index (~4 real dup-email pairs exist in prod). A nulled account still
                           // logs in by username — an admin can assign it a unique email later.
        foreach ($this->legacy->table('members')->orderBy('member_id')->get() as $m) {
            $base = trim((string) ($m->member_name ?: ('user' . $m->member_id)));
            $username = mb_substr($base, 0, 240);
            if (isset($seen[mb_strtolower($username)])) {
                $username = mb_substr($base, 0, 230) . '_' . $m->member_id;
            }
            $seen[mb_strtolower($username)] = true;

            $email = $m->member_email ?: null;   // '' / '0' -> null (matches the historical import)
            if ($email !== null) {
                // Fold to lowercase ASCII so the dedup key matches the users.email index collation
                // (utf8mb4_unicode_ci is accent-INSENSITIVE — 'josé' == 'jose'); a plain trim+lower
                // would let accent-variant duplicates both survive here and then collide at the index,
                // aborting the whole import.
                $key = mb_strtolower(Str::ascii(trim($email)));
                if ($key === '' || isset($seenEmail[$key])) {
                    $email = null;
                } else {
                    $seenEmail[$key] = true;
                }
            }

            $rows[] = [
                'username' => $username,
                'name' => $m->full_name ?: $m->member_name,
                'full_name' => $m->full_name,
                'email' => $email,
                'password' => $m->member_password,          // already a bcrypt hash — kept as-is
                'role' => (int) ($m->position ?? 5),
                'specialty_id' => ($m->specialty_id ?? 0) > 0 ? $m->specialty_id : null,
                'active' => (int) ($m->active ?? 0) === 1,
                'on_service' => (int) ($m->on_service ?? 0) === 1,
                'can_assign' => (int) ($m->assign_access ?? 0) === 1,
                'can_add' => (int) ($m->add_new_patient ?? 0) === 1,
                'can_manage' => (int) ($m->manage_patient ?? 0) === 1,
                'can_modify' => (int) ($m->modify_patient ?? 0) === 1,
                // MFA is re-enrolled fresh in the Laravel app (the secret column is encrypted at rest);
                // legacy secrets are intentionally NOT carried over.
                'mfa_secret' => null,
                'mfa_recovery_codes' => null,
                'mfa_enrolled_at' => null,
                'pass_exp_date' => $m->pass_exp_date ?? null,
                'legacy_id' => $m->member_id,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        $this->insertBatched('users', $rows);
        $map = DB::table('users')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        $this->info('  users imported: ' . count($map));
        return $map;
    }

    private function importPatients(): array
    {
        // one canonical patient per trimmed, non-blank MRN; demographics from the latest episode.
        $rows = $this->legacy->table('picupatients as p')
            ->join(DB::raw('(SELECT TRIM(MRN) tm, MAX(ID) mid FROM ' . $this->legacy->getDatabaseName() . '.picupatients WHERE MRN IS NOT NULL AND TRIM(MRN) <> "" GROUP BY tm) agg'),
                'p.ID', '=', 'agg.mid')
            ->selectRaw('agg.tm mrn, p.PNAME, p.gender, p.age, p.nationality')
            ->get();
        $batch = [];
        $seen = [];   // PHP trim() strips tab/newline that SQL TRIM() keeps, so two SQL-distinct
                      // MRN groups can normalise to the same key here — collapse them.
        foreach ($rows as $r) {
            $mrn = mb_substr(trim((string) $r->mrn), 0, 64);
            if ($mrn === '' || isset($seen[$mrn])) { continue; }
            $seen[$mrn] = true;
            $batch[] = [
                'mrn' => $mrn,
                'name' => $r->PNAME, 'gender' => $r->gender,
                'age' => $this->age($r->age),
                'nationality' => $this->nationality($r->nationality ?? null),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        $this->insertBatched('patients', $batch);
        $map = DB::table('patients')->pluck('id', 'mrn')->all();
        $this->info('  patients imported: ' . count($map));
        return $map;
    }

    private function importAdmissions(array $userMap, array $patientMap): void
    {
        $u = fn ($id) => ($id && isset($userMap[$id])) ? $userMap[$id] : null;
        $this->legacy->table('picupatients')->orderBy('ID')->chunk(1000, function ($chunk) use ($u, $patientMap) {
            $batch = [];
            foreach ($chunk as $p) {
                $mrn = mb_substr(trim((string) $p->MRN), 0, 64);
                $pid = $patientMap[$mrn] ?? null;
                if (! $pid) {
                    // Admission with no usable MRN — preserve the clinical record under a per-episode
                    // placeholder patient (don't drop real patients just because the MRN is blank).
                    $pid = DB::table('patients')->insertGetId([
                        'mrn' => 'NOMRN-' . $p->ID, 'name' => $p->PNAME, 'gender' => $p->gender,
                        'age' => $this->age($p->age),
                        'nationality' => $this->nationality($p->nationality ?? null),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                // Satisfy the chk_* date constraints (migration 2026_06_14_010006): NULL the impossible
                // date, keeping the row + admit_date. Order matches the migration's self-heal — fix the
                // admit-relative inversions first, then discharge-vs-medical on what survives.
                $admit = $this->date($p->ADMDATE);
                $med = $this->date($p->med_DISDATE);
                $dis = $this->date($p->DISDATE);
                if ($dis !== null && $admit !== null && $dis < $admit) { $dis = null; }
                if ($med !== null && $admit !== null && $med < $admit) { $med = null; }
                if ($dis !== null && $med !== null && $dis < $med) { $dis = null; }

                $batch[] = [
                    'patient_id' => $pid,
                    'bed' => $p->BED,
                    'admitted_from' => $p->ADMFROM,
                    'admit_date' => $admit,
                    'current_location' => $p->current_location,
                    'consultant_id' => $u($p->consultant_id),
                    'admitted_by' => $u($p->admitted_by),
                    'discharged_by' => $u($p->trans_discharge_by),
                    'medical_discharge_date' => $med,
                    'discharge_date' => $dis,
                    'discharge_to' => $p->DISTO,
                    'outcome' => $p->MORTALITY,
                    'delay_reason' => $p->delay,
                    'transfer_type' => $p->trans_discharge,
                    'is_longterm' => ($p->longterm ?? '') === 'longterm',
                    'is_new_assignment' => (string) ($p->newassign ?? '') === '1',
                    'assigned_on' => $assignedOn = $this->date($p->assigned_on ?? null),
                    // the board's 24h "New" badge keys on assigned_at — backfill new-flagged rows
                    // at MIDNIGHT of assigned_on so cutover-day badges survive the migration
                    'assigned_at' => ((string) ($p->newassign ?? '') === '1' && $assignedOn)
                        ? $assignedOn . ' 00:00:00' : null,
                    'legacy_id' => $p->ID,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $this->insertBatched('admissions', $batch);
        });
        $this->info('  admissions imported: ' . number_format(DB::table('admissions')->count()));

        // diagnoses: map legacy picupatients.ID -> new admission id, expand the JSON array
        $admMap = DB::table('admissions')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        $batch = [];
        $this->legacy->table('picupatients')->select('ID', 'admissiondiagnosis')->orderBy('ID')->chunk(2000, function ($chunk) use (&$batch, $admMap) {
            foreach ($chunk as $p) {
                $aid = $admMap[$p->ID] ?? null;
                if (! $aid) { continue; }
                $dx = json_decode($p->admissiondiagnosis ?? '', true);
                if (! is_array($dx)) { continue; }
                $seq = 1; $seen = [];   // de-dup codes within an admission (source arrays can repeat a code)
                foreach ($dx as $code) {
                    $code = trim((string) $code);
                    if ($code === '' || isset($seen[$code])) { continue; }
                    $seen[$code] = true;
                    $batch[] = ['admission_id' => $aid, 'seq' => $seq++, 'icd10_code' => $code, 'created_at' => now(), 'updated_at' => now()];
                }
                if (count($batch) >= 2000) { $this->insertBatched('admission_diagnoses', $batch); $batch = []; }
            }
        });
        if ($batch) { $this->insertBatched('admission_diagnoses', $batch); }
        $this->info('  diagnoses imported: ' . number_format(DB::table('admission_diagnoses')->count()));
    }

    private function importConsultations(array $userMap, array $patientMap): void
    {
        $u = fn ($id) => ($id && isset($userMap[$id])) ? $userMap[$id] : null;

        // legacy consultation_to_service held the NUMERIC speciality id for internal specialties
        // (the dropdown emitted ids) but the NAME for other/allied services — resolve numbers to
        // names at import time. speciality takes precedence on an id collision (legacy display
        // only ever looked numbers up in speciality); non-numeric values are kept as-is.
        $svcMap = [];
        foreach ($this->legacy->table('speciality')->get() as $r) {
            $svcMap[(int) $r->id] = trim((string) $r->specilaity);
        }
        foreach ($this->legacy->table('other_specialities')->get() as $r) {
            $svcMap[(int) $r->id] ??= trim((string) $r->specilaity);
        }

        $this->legacy->table('consultations')->orderBy('id')->chunk(1000, function ($chunk) use ($u, $patientMap, $svcMap) {
            $batch = [];
            foreach ($chunk as $c) {
                $mrn = mb_substr(trim((string) $c->MRN), 0, 64);
                $ind = json_decode($c->indication ?? '', true);
                $svc = trim((string) ($c->consultation_to_service ?? ''));
                if ($svc !== '' && ctype_digit($svc)) {
                    $svc = $svcMap[(int) $svc] ?? $svc;   // unmatched numbers self-heal via the data-fix migration
                }
                $batch[] = [
                    // keep MRN '0' (a real, if garbage, value) like the patient import does —
                    // `$mrn ?: null` would null '0' and unlink it from its patient (L1-schema)
                    'mrn' => $mrn === '' ? null : $mrn,
                    'patient_id' => $patientMap[$mrn] ?? null,
                    'patient_name' => $c->PNAME,
                    'age' => $this->age($c->age),
                    'bed' => $c->BED,
                    'current_location' => $c->current_location,
                    'consultation_date' => $this->date($c->consultation_date),
                    'consultation_from' => $c->consultation_from,
                    'indication' => is_array($ind) ? json_encode($ind) : null,
                    'other_indication' => $c->other_ind,
                    'to_service' => $svc ?: null,
                    'consultant_id' => $u($c->consultant_id),
                    'entered_by' => $u($c->entered_by_id),
                    'signoff_date' => $this->date($c->signoff_date),
                    'legacy_id' => $c->id,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $this->insertBatched('consultations', $batch);
        });
        $this->info('  consultations imported: ' . number_format(DB::table('consultations')->count()));
    }

    private function importSettings(): void
    {
        $s = $this->legacy->table('settings')->where('id', 0)->first();
        DB::table('settings')->insert([
            'id' => 1,
            'min_hospitalist' => (int) ($s->min_hospitalist ?? 6),
            'max_hospitalist' => (int) ($s->max_hospitalist ?? 30),
            'min_subs' => (int) ($s->min_subs ?? 7),
            'max_subs' => (int) ($s->max_subs ?? 7),
            'short_los' => (int) ($s->short_los ?? 5),
            'long_los' => (int) ($s->long_los ?? 11),
            'mfa_enforcement' => (int) ($s->mfa_enforcement ?? 0),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->info('  settings imported');
    }

    /** Legacy nationality strings are kept as-is (dirty values stay editable) — blank becomes NULL. */
    private function nationality($v): ?string
    {
        $n = trim((string) ($v ?? ''));

        return $n === '' ? null : mb_substr($n, 0, 191);
    }

    private function date($v): ?string
    {
        if (empty($v) || $v === '0000-00-00') { return null; }
        try { return \Carbon\Carbon::parse($v)->toDateString(); } catch (\Throwable $e) { return null; }
    }

    /**
     * A numeric age within the storable clinical range [0,150] (chk_age_range). Non-numeric values,
     * out-of-range ages, and MRNs mistakenly typed into the age field (which also overflow the
     * unsignedSmallInteger column) all become NULL — keep the row, drop the impossible datum.
     */
    private function age($v): ?int
    {
        if (! is_numeric($v)) { return null; }
        $age = (int) $v;

        return ($age >= 0 && $age <= 150) ? $age : null;
    }

    private function insertBatched(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            if ($chunk) { DB::table($table)->insert($chunk); }
        }
    }

    /** Raise memory_limit to $target only if the current limit is finite and smaller (never lower it). */
    private function ensureMemory(string $target): void
    {
        $current = trim((string) ini_get('memory_limit'));
        if ($current === '-1') { return; }                 // already unlimited
        if ($this->toBytes($current) < $this->toBytes($target)) {
            @ini_set('memory_limit', $target);
        }
    }

    private function toBytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
