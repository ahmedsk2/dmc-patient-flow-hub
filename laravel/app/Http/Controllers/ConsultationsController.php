<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConsultationsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status');
        $status = $filters['status'] ?? 'active';
        $reasons = ConsultationReason::pluck('name', 'id');

        $consultations = Consultation::query()
            ->with('consultant:id,full_name,name')
            ->when($status === 'active', fn ($q) => $q->whereNull('signoff_date'))
            ->when($status === 'signed', fn ($q) => $q->whereNotNull('signoff_date'))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('patient_name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Consultation $c) => [
                'id' => $c->id,
                'name' => $c->patient_name ?? 'Unknown',
                'mrn' => $c->mrn,
                'age' => $c->age,
                'bed' => $c->bed,
                'location' => $c->current_location,
                'from' => $c->consultation_from,
                'to' => $c->to_service,
                'consultant' => $c->consultant?->full_name ?? $c->consultant?->name ?? '—',
                'date' => optional($c->consultation_date)->toDateString(),
                'signoff' => optional($c->signoff_date)->toDateString(),
                'reasons' => collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->values(),
                'other' => $c->other_indication,
            ]);

        return Inertia::render('Consultations/Index', [
            'consultations' => $consultations,
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $status],
            'stats' => [
                'active' => Consultation::whereNull('signoff_date')->count(),
                'total' => Consultation::count(),
            ],
        ]);
    }
}
