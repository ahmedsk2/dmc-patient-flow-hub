<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Synthetic, NON-PHI demo data so the Dashboard + charts populate for visual review.
 *
 * Idempotent: truncates the demo/reference tables and removes only the demo consultants
 * (username LIKE 'demo\_%') on every run — the real admin/login accounts are left untouched.
 *
 * Every generated row is built to PASS the app's own data guards:
 *   - patients.mrn matches the StoreAdmissionRequest MRN policy (^\d{1,11}$) — synthetic 4000xxxx
 *   - patients.age in [0,150]  (CHECK chk_age_range)
 *   - admissions date ordering  discharge_date >= medical_discharge_date >= admit_date
 *     (CHECKs chk_discharge_gte_admit / chk_medical_discharge_gte_admit / chk_discharge_gte_medical)
 *   - current_location in {ER, Ward, ICU}; outcome in {Alive, Dead, LAMA}
 *   - every admission has >=1 diagnosis (noDx canary) and every diagnosis code exists in icd10
 *     (orphanDx canary + Item-5 ICD-10 enforcement)
 *   - at most one OPEN episode per patient (doubleOpen canary) — readmissions close the prior one
 *   - active non-long-term LOS <= long_los * dq_los_multiplier (overLos canary): active
 *     non-long-term episodes are all admitted within the last ~20 days
 *
 * NOTE: consultant names are fabricated. There is NO real patient or staff data here.
 */
class DemoSeeder extends Seeder
{
    /** ~40 common Internal-Medicine ICD-10 codes (last 3 are TB). code => description. */
    private const ICD10 = [
        ['I50.9', 'Heart failure, unspecified'],
        ['J18.9', 'Pneumonia, unspecified organism'],
        ['N17.9', 'Acute kidney failure, unspecified'],
        ['E11.9', 'Type 2 diabetes mellitus without complications'],
        ['J44.9', 'Chronic obstructive pulmonary disease, unspecified'],
        ['A41.9', 'Sepsis, unspecified organism'],
        ['I10', 'Essential (primary) hypertension'],
        ['K92.2', 'Gastrointestinal hemorrhage, unspecified'],
        ['J96.00', 'Acute respiratory failure, unspecified'],
        ['E87.6', 'Hypokalemia'],
        ['E86.0', 'Dehydration'],
        ['N39.0', 'Urinary tract infection, site not specified'],
        ['I48.91', 'Atrial fibrillation, unspecified'],
        ['I63.9', 'Cerebral infarction, unspecified'],
        ['K70.30', 'Alcoholic cirrhosis of liver without ascites'],
        ['K85.90', 'Acute pancreatitis without necrosis or infection, unspecified'],
        ['E11.65', 'Type 2 diabetes mellitus with hyperglycemia'],
        ['D64.9', 'Anemia, unspecified'],
        ['J45.909', 'Unspecified asthma, uncomplicated'],
        ['I21.4', 'Non-ST elevation (NSTEMI) myocardial infarction'],
        ['A09', 'Infectious gastroenteritis and colitis, unspecified'],
        ['R55', 'Syncope and collapse'],
        ['G40.909', 'Epilepsy, unspecified, not intractable, without status epilepticus'],
        ['K57.30', 'Diverticulosis of large intestine without perforation or abscess'],
        ['N18.6', 'End stage renal disease'],
        ['E27.40', 'Unspecified adrenocortical insufficiency'],
        ['I26.99', 'Other pulmonary embolism without acute cor pulmonale'],
        ['C34.90', 'Malignant neoplasm of unspecified part of unspecified bronchus or lung'],
        ['B18.2', 'Chronic viral hepatitis C'],
        ['M32.9', 'Systemic lupus erythematosus, unspecified'],
        ['E03.9', 'Hypothyroidism, unspecified'],
        ['R50.9', 'Fever, unspecified'],
        ['I95.9', 'Hypotension, unspecified'],
        ['J90', 'Pleural effusion, not elsewhere classified'],
        ['K72.90', 'Hepatic failure, unspecified without coma'],
        ['D69.6', 'Thrombocytopenia, unspecified'],
        ['E10.9', 'Type 1 diabetes mellitus without complications'],
        ['A15.0', 'Tuberculosis of lung'],
        ['A15.9', 'Respiratory tuberculosis unspecified'],
        ['A16.9', 'Respiratory tuberculosis unspecified, without mention of bacteriological or histological confirmation'],
    ];

    private const TB_CODES = ['A15.0', 'A15.9', 'A16.9'];

    /** specialty name => is_subspecialty. Hospitalist is id 1 (matches the legacy specialty_id = 1). */
    private const SPECIALTIES = [
        ['Hospitalist', false],
        ['Cardiology', true],
        ['Nephrology', true],
        ['Pulmonology', true],
        ['Gastroenterology', true],
        ['Endocrinology', true],
    ];

    private const CONSULTATION_REASONS = [
        'Cardiology opinion',
        'Nephrology / renal replacement',
        'Pulmonology / ventilation',
        'Gastroenterology / endoscopy',
        'Endocrine / glycemic control',
        'Infectious disease',
        'Neurology',
        'Surgical evaluation',
        'Palliative care',
        'Hematology',
    ];

    private const COUNTRIES = [
        ['SA', 'Saudi Arabia'], ['EG', 'Egypt'], ['IN', 'India'], ['PH', 'Philippines'],
        ['PK', 'Pakistan'], ['YE', 'Yemen'], ['SD', 'Sudan'], ['JO', 'Jordan'],
        ['SY', 'Syria'], ['BD', 'Bangladesh'],
    ];

    /** Fabricated consultant names — deliberately not real staff. */
    private const CONSULTANT_NAMES = [
        'Amir Halloran', 'Neda Castellano', 'Bram Okonkwo', 'Lyra Petrakis',
        'Cyrus Vandermeer', 'Isolde Farrow', 'Emeric Talbot', 'Rana Sundqvist',
        'Dario Kimura', 'Salma Renner', 'Viktor Almeida', 'Priya Nakamura',
        'Hugo Brandt', 'Delia Marchetti', 'Osman Yilmaz', 'Freya Lindqvist',
    ];

    private const FIRST_NAMES = [
        'Adam', 'Bilal', 'Carmen', 'Dalia', 'Elias', 'Farah', 'Gamal', 'Hana',
        'Idris', 'Jomana', 'Karim', 'Layla', 'Malik', 'Nour', 'Omar', 'Rania',
        'Sami', 'Tala', 'Usama', 'Widad', 'Yasmin', 'Zaid', 'Basma', 'Faris',
    ];

    private const LAST_NAMES = [
        'Ahmadi', 'Barakat', 'Chahine', 'Darwish', 'Elabbasi', 'Fahmy', 'Ghanem',
        'Habib', 'Iskander', 'Jaber', 'Khalil', 'Latif', 'Mansour', 'Nasser',
        'Othman', 'Qureshi', 'Rahman', 'Saad', 'Tawfik', 'Wahba', 'Zahra',
    ];

    public function run(): void
    {
        mt_srand(20260709);   // deterministic output across re-runs

        $today = Carbon::today();
        $now = Carbon::now();

        // ── 0. reset (idempotent) ────────────────────────────────────────────────────────────
        Schema::disableForeignKeyConstraints();
        foreach (['admission_diagnoses', 'admissions', 'consultation_followups', 'consultations', 'patients',
            'specialties', 'icd10', 'consultation_reasons', 'tb_diagnoses', 'countries'] as $t) {
            DB::table($t)->truncate();
        }
        Schema::enableForeignKeyConstraints();
        // remove only the demo consultants — never the real admin/login accounts
        DB::table('users')->where('username', 'like', 'demo\_%')->delete();

        // ── 1. settings: sensible bed capacity ───────────────────────────────────────────────
        DB::table('settings')->orderBy('id')->limit(1)->update([
            'ward_beds' => 50,
            'icu_beds' => 8,
            'updated_at' => $now,
        ]);

        // ── 2. reference tables ──────────────────────────────────────────────────────────────
        DB::table('specialties')->insert(array_map(fn ($s) => [
            'name' => $s[0], 'is_subspecialty' => $s[1] ? 1 : 0,
            'created_at' => $now, 'updated_at' => $now,
        ], self::SPECIALTIES));

        DB::table('icd10')->insert(array_map(fn ($c) => [
            'code' => $c[0], 'name' => $c[1],
        ], self::ICD10));

        DB::table('consultation_reasons')->insert(array_map(fn ($r) => ['name' => $r], self::CONSULTATION_REASONS));
        $reasonIds = range(1, count(self::CONSULTATION_REASONS));

        DB::table('tb_diagnoses')->insert(array_map(fn ($c) => ['icd10_code' => $c], self::TB_CODES));

        DB::table('countries')->insert(array_map(fn ($c) => ['code' => $c[0], 'name' => $c[1]], self::COUNTRIES));
        $nationalities = array_map(fn ($c) => $c[1], self::COUNTRIES);

        // ── 3. consultant users (role 3) ─────────────────────────────────────────────────────
        // First 6 are Hospitalists (specialty_id 1); the rest spread across subspecialties 2..6.
        $pwd = Hash::make('DemoPass123!');
        $consultants = [];   // [id => ['specialty_id'=>..,'on_service'=>..]]
        $userRows = [];
        foreach (self::CONSULTANT_NAMES as $i => $full) {
            $specialtyId = $i < 6 ? 1 : (($i - 6) % 5) + 2;   // 6 hospitalists, then Cardiology..Endocrine
            $onService = $i % 3 !== 2;                        // ~2/3 on service
            $userRows[] = [
                'username' => 'demo_consultant_' . ($i + 1),
                'name' => $full,
                'full_name' => $full,   // the board/consultant UI prepends the "Dr." honorific itself; seeding it here doubled it ("Dr. Dr. …")
                'role' => 3,                                   // ROLE_CONSULTANT
                'specialty_id' => $specialtyId,
                'active' => 1,
                'on_service' => $onService ? 1 : 0,
                'can_assign' => 1, 'can_add' => 1, 'can_manage' => 1, 'can_modify' => 1,
                'email' => 'demo.consultant' . ($i + 1) . '@example.test',
                'password' => $pwd,
                'pass_exp_date' => $today->copy()->addMonths(3)->toDateString(),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('users')->insert($userRows);
        $consultantIds = DB::table('users')->where('username', 'like', 'demo\_%')->orderBy('id')->pluck('id')->all();
        // map local index -> real id + attributes
        $hospitalistIds = [];
        $subspecialistIds = [];
        $onServiceIds = [];
        foreach ($consultantIds as $idx => $id) {
            $specialtyId = $idx < 6 ? 1 : (($idx - 6) % 5) + 2;
            $onService = $idx % 3 !== 2;
            $consultants[$id] = ['specialty_id' => $specialtyId, 'on_service' => $onService];
            if ($specialtyId === 1) {
                $hospitalistIds[] = $id;
            } else {
                $subspecialistIds[] = $id;
            }
            if ($onService) {
                $onServiceIds[] = $id;
            }
        }
        $adminId = (int) DB::table('users')->where('role', 0)->orderBy('id')->value('id');

        // weighted assignment pool: hospitalists 3x, subspecialists 1x → biggest slice is Hospitalist
        $assignPool = [];
        foreach ($hospitalistIds as $id) {
            $assignPool = array_merge($assignPool, [$id, $id, $id]);
        }
        $assignPool = array_merge($assignPool, $subspecialistIds);

        // ── 4. patients + admissions + diagnoses ─────────────────────────────────────────────
        $patientRows = [];   // pushed in ascending pid order → patients.id === pid on a truncated table
        $patientMeta = [];   // pid => [mrn, name, age]
        $admRows = [];       // pushed in order → admissions.id === (index + 1)
        $dxRows = [];
        $pid = 0;

        $commonCodes = array_slice(array_column(self::ICD10, 0), 0, count(self::ICD10) - count(self::TB_CODES));

        $newPatient = function () use (&$pid, &$patientRows, &$patientMeta, $now, $nationalities) {
            $pid++;
            $mrn = (string) (40000000 + $pid);
            $name = self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)] . ' '
                . self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];
            $age = mt_rand(18, 95);
            $patientRows[] = [
                'mrn' => $mrn, 'name' => $name, 'gender' => mt_rand(0, 1) ? 'Male' : 'Female',
                'age' => $age, 'nationality' => $nationalities[mt_rand(0, count($nationalities) - 1)],
                'created_at' => $now, 'updated_at' => $now,
            ];
            $patientMeta[$pid] = ['mrn' => $mrn, 'name' => $name, 'age' => $age];

            return $pid;
        };

        // biased-recent day picker: 0..$max days ago, weighted toward recent (exponent > 1)
        $recentDays = fn (int $max) => (int) floor(pow(mt_rand() / mt_getrandmax(), 1.8) * $max);
        // biased-small LOS 1..$max
        $smallLos = fn (int $max) => 1 + (int) floor(pow(mt_rand() / mt_getrandmax(), 2.2) * ($max - 1));
        $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];

        // push an admission spec, attaching 1-3 diagnoses (optionally a TB code)
        $addAdmission = function (array $row, bool $tb = false) use (&$admRows, &$dxRows, $commonCodes, $now) {
            $admRows[] = $row;
            $admissionId = count($admRows);   // sequential id on a truncated table
            $n = mt_rand(1, 100) <= 55 ? 1 : (mt_rand(1, 100) <= 70 ? 2 : 3);
            $codes = (array) array_rand(array_flip($commonCodes), min($n, count($commonCodes)));
            $codes = array_values($codes);
            if ($tb) {
                $codes[0] = self::TB_CODES[mt_rand(0, count(self::TB_CODES) - 1)];
            }
            foreach ($codes as $seq => $code) {
                $dxRows[] = [
                    'admission_id' => $admissionId, 'seq' => $seq + 1, 'icd10_code' => $code,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            return $admissionId;
        };

        $admFrom = ['ER', 'Clinic', 'OPD', 'Referral', 'Transfer', 'Direct'];

        // 4a. discharged primary admissions (anchors for readmissions)
        $DISCHARGED_WARD = 340;
        $DISCHARGED_ICU = 60;
        $wardDischargedAnchors = [];   // [pid => discharge_date string] for readmission anchoring
        $deadThisMonthTarget = 6;      // guarantee a few current-month deaths for the KPI/alert
        $deadThisMonth = 0;

        $makeDischarged = function (bool $icu) use (
            &$newPatient, &$addAdmission, &$wardDischargedAnchors, &$deadThisMonth, $deadThisMonthTarget,
            $today, $now, $recentDays, $smallLos, $pick, $assignPool, $admFrom
        ) {
            $daysAgo = max(2, $recentDays(360));
            $admit = $today->copy()->subDays($daysAgo);
            $los = min($smallLos(38), $daysAgo);      // discharge stays <= today
            $discharge = $admit->copy()->addDays($los);

            // outcome mix: mostly Alive, a slice Dead/LAMA
            $roll = mt_rand(1, 100);
            $forceDead = $deadThisMonth < $deadThisMonthTarget && $discharge->greaterThanOrEqualTo($today->copy()->startOfMonth()) && mt_rand(1, 100) <= 40;
            if ($forceDead || $roll <= 6) {
                $outcome = 'Dead';
                $dest = 'Mortuary';
                if ($discharge->greaterThanOrEqualTo($today->copy()->startOfMonth())) {
                    $deadThisMonth++;
                }
            } elseif ($roll <= 10) {
                $outcome = 'LAMA';
                $dest = 'LAMA';
            } else {
                $outcome = 'Alive';
                $dest = $pick(['Home', 'Home', 'Home', 'Other Facility']);
            }

            // ~25% carried a medical discharge (medically cleared) before the physical discharge
            $medical = null;
            if (mt_rand(1, 100) <= 25 && $los >= 2) {
                $medical = $admit->copy()->addDays((int) floor($los * 0.6))->toDateString();
            }

            $consultantId = $pick($assignPool);
            $pidLocal = $newPatient();
            $addAdmission([
                'patient_id' => $pidLocal,
                'bed' => ($icu ? 'ICU-' : 'W-') . mt_rand(1, 60),
                'admitted_from' => $icu ? 'ICU' : $pick($admFrom),
                'admit_date' => $admit->toDateString(),
                'current_location' => $icu ? 'ICU' : 'Ward',
                'consultant_id' => $consultantId,
                'admitted_by' => $consultantId,
                'discharged_by' => $consultantId,
                'medical_discharge_date' => $medical,
                'discharge_date' => $discharge->toDateString(),
                'discharge_to' => $icu && $outcome === 'Alive' ? 'Home' : $dest,
                'outcome' => $outcome,
                'transfer_type' => $icu ? 'discharge from ICU' : 'discharge from ward',
                'is_longterm' => 0,
                'is_new_assignment' => 0,
                'assigned_on' => $admit->toDateString(),
                'assigned_at' => $admit->copy()->addHours(mt_rand(1, 20)),
                'created_at' => $now, 'updated_at' => $now,
            ], tb: mt_rand(1, 100) <= 5);

            if (! $icu) {
                $wardDischargedAnchors[$pidLocal] = $discharge;
            }
        };

        for ($i = 0; $i < $DISCHARGED_WARD; $i++) {
            $makeDischarged(false);
        }
        for ($i = 0; $i < $DISCHARGED_ICU; $i++) {
            $makeDischarged(true);
        }

        // 4b. readmissions — a NEW closed episode for an existing ward-discharged patient,
        // admitted 0-3 days after that discharge (within the default readmission window), and
        // closed again so no patient ever has two open episodes.
        $READMITS = 40;
        $anchorPids = array_keys($wardDischargedAnchors);
        shuffle($anchorPids);
        foreach (array_slice($anchorPids, 0, $READMITS) as $pidLocal) {
            $prevDischarge = $wardDischargedAnchors[$pidLocal];
            $gap = mt_rand(0, 3);
            $admit = $prevDischarge->copy()->addDays($gap);
            if ($admit->greaterThan($today)) {
                $admit = $today->copy();
            }
            $maxLos = max(0, $today->diffInDays($admit));   // keep discharge <= today
            $los = min($smallLos(12), $maxLos);
            $discharge = $admit->copy()->addDays($los);
            $consultantId = $pick($assignPool);
            $addAdmission([
                'patient_id' => $pidLocal,
                'bed' => 'W-' . mt_rand(1, 60),
                'admitted_from' => $pick(['ER', 'Referral', 'Direct']),
                'admit_date' => $admit->toDateString(),
                'current_location' => 'Ward',
                'consultant_id' => $consultantId,
                'admitted_by' => $consultantId,
                'discharged_by' => $consultantId,
                'medical_discharge_date' => null,
                'discharge_date' => $discharge->toDateString(),
                'discharge_to' => 'Home',
                'outcome' => 'Alive',
                'transfer_type' => 'discharge from ward',
                'is_longterm' => 0,
                'is_new_assignment' => 0,
                'assigned_on' => $admit->toDateString(),
                'assigned_at' => $admit->copy()->addHours(mt_rand(1, 20)),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // 4c. active admissions (discharge_date NULL) — the live census
        $activeAssigned = function (bool $icu, bool $longterm, ?string $medicalDischarge, int $daysAgoMax, bool $newAssign, bool $tb = false)
        use (&$newPatient, &$addAdmission, $today, $now, $recentDays, $pick, $assignPool, $admFrom) {
            $daysAgo = $longterm ? mt_rand(30, 280) : max(1, $recentDays($daysAgoMax));
            $admit = $today->copy()->subDays($daysAgo);
            $medical = null;
            if ($medicalDischarge !== null) {
                // "boarding": cleared 1-6 days ago, still occupying the bed (>= admit, <= today)
                $clearedDaysAgo = min($daysAgo, mt_rand(1, 6));
                $medical = $today->copy()->subDays($clearedDaysAgo)->toDateString();
            }
            $consultantId = $pick($assignPool);
            $pidLocal = $newPatient();
            $addAdmission([
                'patient_id' => $pidLocal,
                'bed' => ($icu ? 'ICU-' : 'W-') . mt_rand(1, 60),
                'admitted_from' => $icu ? 'ICU' : $pick($admFrom),
                'admit_date' => $admit->toDateString(),
                'current_location' => $icu ? 'ICU' : 'Ward',
                'consultant_id' => $consultantId,
                'admitted_by' => $consultantId,
                'discharged_by' => null,
                'medical_discharge_date' => $medical,
                'discharge_date' => null,
                'discharge_to' => null,
                'outcome' => null,
                'transfer_type' => null,
                'is_longterm' => $longterm ? 1 : 0,
                'is_new_assignment' => $newAssign ? 1 : 0,
                'assigned_on' => $admit->toDateString(),
                'assigned_at' => $newAssign ? $today->copy()->subHours(mt_rand(1, 22)) : $admit->copy()->addHours(mt_rand(1, 20)),
                'created_at' => $now, 'updated_at' => $now,
            ], tb: $tb);
        };

        // 23 normal ward actives (first few forced into the current week + some brand-new + a few TB)
        for ($i = 0; $i < 23; $i++) {
            $newAssign = $i < 8;                 // ~8 assigned in the last 24h → consultantBoard "new"
            $tb = $i < 3;                        // >=3 active ward TB → donutTb / board tb column
            $activeAssigned(icu: false, longterm: false, medicalDischarge: null,
                daysAgoMax: $i < 12 ? 6 : 20, newAssign: $newAssign, tb: $tb);
        }
        // 6 ICU actives
        for ($i = 0; $i < 6; $i++) {
            $activeAssigned(icu: true, longterm: false, medicalDischarge: null, daysAgoMax: 18, newAssign: $i < 2);
        }
        // 4 long-term actives (older admits, exempt from the stale-episode canary)
        for ($i = 0; $i < 4; $i++) {
            $activeAssigned(icu: false, longterm: true, medicalDischarge: null, daysAgoMax: 20, newAssign: false);
        }
        // 6 boarding actives (medically cleared, bed still occupied) → boardingCount / worklist
        for ($i = 0; $i < 6; $i++) {
            $activeAssigned(icu: false, longterm: false, medicalDischarge: 'set', daysAgoMax: 18, newAssign: false);
        }
        // 3 unassigned actives (New Admissions queue) — consultant_id NULL
        for ($i = 0; $i < 3; $i++) {
            $daysAgo = mt_rand(0, 3);
            $admit = $today->copy()->subDays($daysAgo);
            $pidLocal = $newPatient();
            $addAdmission([
                'patient_id' => $pidLocal,
                'bed' => 'W-' . mt_rand(1, 60),
                'admitted_from' => $admFrom[mt_rand(0, count($admFrom) - 1)],
                'admit_date' => $admit->toDateString(),
                'current_location' => mt_rand(0, 1) ? 'Ward' : 'ER',
                'consultant_id' => null,
                'admitted_by' => $adminId ?: null,
                'discharged_by' => null,
                'medical_discharge_date' => null,
                'discharge_date' => null, 'discharge_to' => null, 'outcome' => null, 'transfer_type' => null,
                'is_longterm' => 0, 'is_new_assignment' => 0,
                'assigned_on' => null, 'assigned_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // insert patients FIRST (FK), then admissions, then diagnoses — chunked, order preserved
        foreach (array_chunk($patientRows, 200) as $chunk) {
            DB::table('patients')->insert($chunk);
        }
        foreach (array_chunk($admRows, 200) as $chunk) {
            DB::table('admissions')->insert($chunk);
        }
        foreach (array_chunk($dxRows, 300) as $chunk) {
            DB::table('admission_diagnoses')->insert($chunk);
        }

        // ── 5. consultations (~180) spread across the last 6 months ──────────────────────────
        $consultFrom = array_map(fn ($s) => $s[0], self::SPECIALTIES);
        $toServices = ['Cardiology', 'Nephrology', 'Pulmonology', 'Gastroenterology', 'Endocrinology',
            'Infectious Disease', 'Neurology', 'General Surgery'];
        $consultRows = [];
        $CONSULTS = 180;
        for ($i = 0; $i < $CONSULTS; $i++) {
            $daysAgo = $recentDays(180);
            $cDate = $today->copy()->subDays($daysAgo);
            $signed = mt_rand(1, 100) > 30;   // ~70% signed off
            $signoff = null;
            if ($signed) {
                $signDay = $cDate->copy()->addDays(mt_rand(0, 6));
                if ($signDay->greaterThan($today)) {
                    $signDay = $today->copy();
                }
                $signoff = $signDay->toDateString();
            }
            $pidLocal = mt_rand(1, $pid);
            $meta = $patientMeta[$pidLocal];
            $indication = (array) array_rand(array_flip($reasonIds), mt_rand(1, 2));
            $consultRows[] = [
                'mrn' => $meta['mrn'],
                'patient_id' => $pidLocal,
                'patient_name' => $meta['name'],
                'age' => $meta['age'],
                'bed' => 'W-' . mt_rand(1, 60),
                'current_location' => $pick(['Ward', 'Ward', 'ICU', 'ER']),
                'consultation_date' => $cDate->toDateString(),
                'consultation_from' => $pick($consultFrom),
                'indication' => json_encode(array_values($indication)),
                'other_indication' => null,
                'to_service' => $pick($toServices),
                'consultant_id' => $pick($consultantIds),
                'entered_by' => $pick($consultantIds),
                'signoff_date' => $signoff,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($consultRows, 200) as $chunk) {
            DB::table('consultations')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'DemoSeeder: %d consultants, %d patients, %d admissions, %d diagnoses, %d consultations.',
            count($consultantIds), count($patientRows), count($admRows), count($dxRows), count($consultRows)
        ));
    }
}
