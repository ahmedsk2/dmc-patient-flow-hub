<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientsController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = Setting::current();
        $filters = $request->only('search', 'location');

        $admissions = Admission::query()
            ->whereNull('discharge_date')                       // active census
            ->with(['patient:id,mrn,name,gender,age', 'consultant:id,full_name,name'])
            ->withCount('diagnoses')
            ->when($filters['location'] ?? null, fn ($q, $loc) => $q->where('current_location', $loc))
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->whereHas('patient', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%"));
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Admission $a) use ($settings) {
                $los = $a->lengthOfStay();
                return [
                    'id' => $a->id,
                    'name' => $a->patient?->name ?? 'Unknown',
                    'mrn' => $a->patient?->mrn,
                    'gender' => $a->patient?->gender,
                    'age' => $a->patient?->age,
                    'bed' => $a->bed,
                    'location' => $a->current_location,
                    'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? 'Unassigned',
                    'consultant_id' => $a->consultant_id,
                    'admit_date' => optional($a->admit_date)->toDateString(),
                    'los' => $los,
                    'los_band' => $los === null ? null : ($los < $settings->short_los ? 'short' : ($los > $settings->long_los ? 'long' : 'mid')),
                    'dx_count' => $a->diagnoses_count,
                    'is_longterm' => $a->is_longterm,
                    'is_new' => $a->is_new_assignment,
                    'medically_discharged' => $a->medical_discharge_date !== null,
                ];
            });

        return Inertia::render('Patients/Index', [
            'admissions' => $admissions,
            'filters' => $filters,
            'consultants' => User::where('role', User::ROLE_CONSULTANT)->where('active', 1)
                ->orderBy('full_name')->get(['id', 'full_name', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name ?: $u->name]),
            'stats' => [
                'total' => Admission::active()->count(),
                'ward' => Admission::active()->nonIcu()->count(),
                'icu' => Admission::active()->icu()->count(),
            ],
        ]);
    }
}
