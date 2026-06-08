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
        $this->legacy = DB::connection('legacy');
        $this->info('Importing from legacy connection: ' . $this->legacy->getDatabaseName());

        // Preserve explicit id=0 reference rows (legacy consultation_reason 'other'=0) instead of
        // letting an AUTO_INCREMENT column turn 0 into the next value.
        DB::statement("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");

        Schema::disableForeignKeyConstraints();
        foreach (['admission_diagnoses', 'admissions', 'consultations', 'patients', 'icd10',
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
            'is_subspecialty' => ((int) $r->id !== 1), 'created_at' => now(), 'updated_at' => now(),
        ])->all();
        $this->insertBatched('specialties', $rows);

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
        $seen = [];   // legacy member_name is NOT unique (and the collation is case-insensitive);
                      // suffix the legacy id on collision so usernames stay unique + login still works.
        foreach ($this->legacy->table('members')->get() as $m) {
            $base = trim((string) ($m->member_name ?: ('user' . $m->member_id)));
            $username = mb_substr($base, 0, 240);
            if (isset($seen[mb_strtolower($username)])) {
                $username = mb_substr($base, 0, 230) . '_' . $m->member_id;
            }
            $seen[mb_strtolower($username)] = true;
            $rows[] = [
                'username' => $username,
                'name' => $m->full_name ?: $m->member_name,
                'full_name' => $m->full_name,
                'email' => $m->member_email ?: null,
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
            ->selectRaw('agg.tm mrn, p.PNAME, p.gender, p.age')
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
                'age' => is_numeric($r->age) ? (int) $r->age : null,
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
                        'age' => is_numeric($p->age) ? (int) $p->age : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $batch[] = [
                    'patient_id' => $pid,
                    'bed' => $p->BED,
                    'admitted_from' => $p->ADMFROM,
                    'admit_date' => $this->date($p->ADMDATE),
                    'current_location' => $p->current_location,
                    'consultant_id' => $u($p->consultant_id),
                    'admitted_by' => $u($p->admitted_by),
                    'discharged_by' => $u($p->trans_discharge_by),
                    'medical_discharge_date' => $this->date($p->med_DISDATE),
                    'discharge_date' => $this->date($p->DISDATE),
                    'discharge_to' => $p->DISTO,
                    'outcome' => $p->MORTALITY,
                    'delay_reason' => $p->delay,
                    'transfer_type' => $p->trans_discharge,
                    'is_longterm' => ($p->longterm ?? '') === 'longterm',
                    'is_new_assignment' => (string) ($p->newassign ?? '') === '1',
                    'assigned_on' => $this->date($p->assigned_on ?? null),
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
                $seq = 1;
                foreach ($dx as $code) {
                    $code = trim((string) $code);
                    if ($code === '') { continue; }
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
        $this->legacy->table('consultations')->orderBy('id')->chunk(1000, function ($chunk) use ($u, $patientMap) {
            $batch = [];
            foreach ($chunk as $c) {
                $mrn = mb_substr(trim((string) $c->MRN), 0, 64);
                $ind = json_decode($c->indication ?? '', true);
                $batch[] = [
                    'mrn' => $mrn ?: null,
                    'patient_id' => $patientMap[$mrn] ?? null,
                    'patient_name' => $c->PNAME,
                    'age' => is_numeric($c->age) ? (int) $c->age : null,
                    'bed' => $c->BED,
                    'current_location' => $c->current_location,
                    'consultation_date' => $this->date($c->consultation_date),
                    'consultation_from' => $c->consultation_from,
                    'indication' => is_array($ind) ? json_encode($ind) : null,
                    'other_indication' => $c->other_ind,
                    'to_service' => $c->consultation_to_service,
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

    private function date($v): ?string
    {
        if (empty($v) || $v === '0000-00-00') { return null; }
        try { return \Carbon\Carbon::parse($v)->toDateString(); } catch (\Throwable $e) { return null; }
    }

    private function insertBatched(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            if ($chunk) { DB::table($table)->insert($chunk); }
        }
    }
}
