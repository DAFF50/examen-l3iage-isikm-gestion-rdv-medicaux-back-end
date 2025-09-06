<?php
// app/Http/Controllers/TimeSlotController.php

namespace App\Http\Controllers;

use App\Models\TimeSlot;
use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class TimeSlotController extends Controller
{
    /**
     * Récupérer les créneaux disponibles pour un médecin
     */
    public function getAvailableSlots(Request $request, $doctorId)
    {
        $validator = Validator::make(array_merge($request->all(), ['doctor_id' => $doctorId]), [
            'doctor_id' => 'required|exists:doctors,id',
            'start_date' => 'nullable|date|after_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'limit_days' => 'nullable|integer|min:1|max:90'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Paramètres invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $doctor = Doctor::findOrFail($doctorId);

            $startDate = $request->get('start_date', now()->format('Y-m-d'));
            $limitDays = $request->get('limit_days', 30);
            $endDate = $request->get('end_date', now()->addDays($limitDays)->format('Y-m-d'));

            // Générer les créneaux manquants
            $this->generateMissingSlots($doctor, $startDate, $endDate);

            // Récupérer les créneaux disponibles
            $slots = TimeSlot::where('doctor_id', $doctorId)
                ->whereBetween('date', [$startDate, $endDate])
                ->where('status', 'available')
                ->orderBy('date')
                ->orderBy('start_time')
                ->get()
                ->groupBy('date');

            // Formater les données
            $formattedSlots = [];
            foreach ($slots as $date => $dateSlots) {
                $formattedSlots[] = [
                    'date' => $date,
                    'day_name' => Carbon::parse($date)->locale('fr')->isoFormat('dddd'),
                    'slots' => $dateSlots->map(function($slot) {
                        return [
                            'id' => $slot->id,
                            'start_time' => $slot->start_time->format('H:i'),
                            'end_time' => $slot->end_time->format('H:i'),
                            'available' => $slot->status === 'available'
                        ];
                    })
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'doctor' => $doctor->load('user:id,name', 'specialty:id,name'),
                    'available_slots' => $formattedSlots,
                    'total_slots' => $slots->flatten()->count(),
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des créneaux',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les créneaux d'un médecin (interface médecin)
     */
    public function doctorSlots(Request $request)
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil médecin non trouvé'
                ], 404);
            }

            $startDate = $request->get('start_date', now()->format('Y-m-d'));
            $endDate = $request->get('end_date', now()->addDays(30)->format('Y-m-d'));

            $slots = TimeSlot::where('doctor_id', $doctor->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->with('appointment.patient:id,name,phone')
                ->orderBy('date')
                ->orderBy('start_time')
                ->get()
                ->groupBy('date');

            $formattedSlots = [];
            foreach ($slots as $date => $dateSlots) {
                $formattedSlots[] = [
                    'date' => $date,
                    'day_name' => Carbon::parse($date)->locale('fr')->isoFormat('dddd'),
                    'slots' => $dateSlots->map(function($slot) {
                        return [
                            'id' => $slot->id,
                            'start_time' => $slot->start_time->format('H:i'),
                            'end_time' => $slot->end_time->format('H:i'),
                            'status' => $slot->status,
                            'appointment' => $slot->appointment ? [
                                'id' => $slot->appointment->id,
                                'patient_name' => $slot->appointment->patient->name,
                                'patient_phone' => $slot->appointment->patient->phone,
                                'status' => $slot->appointment->status
                            ] : null
                        ];
                    })
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $formattedSlots
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des créneaux'
            ], 500);
        }
    }

    /**
     * Générer des créneaux pour un médecin
     */
    public function generateSlots(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after:start_date',
            'overwrite' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $doctor = $request->user()->doctor;

            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $overwrite = $request->get('overwrite', false);

            if ($overwrite) {
                // Supprimer les créneaux existants non réservés
                TimeSlot::where('doctor_id', $doctor->id)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->where('status', 'available')
                    ->delete();
            }

            $generatedCount = $this->generateSlotsForPeriod($doctor, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => "Créneaux générés avec succès",
                'data' => [
                    'generated_slots' => $generatedCount,
                    'period' => [
                        'start' => $startDate->format('d/m/Y'),
                        'end' => $endDate->format('d/m/Y')
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération des créneaux'
            ], 500);
        }
    }

    /**
     * Bloquer un créneau
     */
    public function blockSlot(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $slot = TimeSlot::findOrFail($id);

            // Vérifier que c'est le médecin propriétaire
            if ($slot->doctor->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if ($slot->status === 'booked') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce créneau est déjà réservé'
                ], 400);
            }

            $slot->update([
                'status' => 'blocked',
                'metadata' => [
                    'blocked_reason' => $request->reason ?? 'Bloqué par le médecin',
                    'blocked_at' => now()
                ]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Créneau bloqué avec succès',
                'data' => $slot
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du blocage'
            ], 500);
        }
    }

    /**
     * Débloquer un créneau
     */
    public function unblockSlot(Request $request, $id)
    {
        try {
            $slot = TimeSlot::findOrFail($id);

            if ($slot->doctor->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if ($slot->status !== 'blocked') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce créneau n\'est pas bloqué'
                ], 400);
            }

            $slot->update(['status' => 'available']);

            return response()->json([
                'success' => true,
                'message' => 'Créneau débloqué avec succès',
                'data' => $slot
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du déblocage'
            ], 500);
        }
    }

    /**
     * Supprimer un créneau
     */
    public function deleteSlot(Request $request, $id)
    {
        try {
            $slot = TimeSlot::findOrFail($id);

            if ($slot->doctor->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if ($slot->status === 'booked') {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer un créneau réservé'
                ], 400);
            }

            $slot->delete();

            return response()->json([
                'success' => true,
                'message' => 'Créneau supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression'
            ], 500);
        }
    }

    /**
     * Créer un créneau personnalisé
     */
    public function createCustomSlot(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'in:available,blocked'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $doctor = $request->user()->doctor;

            // Vérifier qu'il n'y a pas de conflit
            $existingSlot = TimeSlot::where('doctor_id', $doctor->id)
                ->where('date', $request->date)
                ->where(function($q) use ($request) {
                    $q->where(function($q2) use ($request) {
                        $q2->where('start_time', '<=', $request->start_time)
                            ->where('end_time', '>', $request->start_time);
                    })->orWhere(function($q2) use ($request) {
                        $q2->where('start_time', '<', $request->end_time)
                            ->where('end_time', '>=', $request->end_time);
                    });
                })
                ->first();

            if ($existingSlot) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un créneau existe déjà sur cette période'
                ], 400);
            }

            $slot = TimeSlot::create([
                'doctor_id' => $doctor->id,
                'date' => $request->date,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'status' => $request->get('status', 'available')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Créneau créé avec succès',
                'data' => $slot
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création'
            ], 500);
        }
    }

    /**
     * Statistiques des créneaux
     */
    public function statistics(Request $request)
    {
        try {
            $doctor = $request->user()->doctor;

            $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->get('end_date', now()->endOfMonth()->format('Y-m-d'));

            $slots = TimeSlot::where('doctor_id', $doctor->id)
                ->whereBetween('date', [$startDate, $endDate]);

            $stats = [
                'total_slots' => $slots->count(),
                'available_slots' => $slots->clone()->where('status', 'available')->count(),
                'booked_slots' => $slots->clone()->where('status', 'booked')->count(),
                'blocked_slots' => $slots->clone()->where('status', 'blocked')->count(),
                'utilization_rate' => 0
            ];

            if ($stats['total_slots'] > 0) {
                $stats['utilization_rate'] = round(($stats['booked_slots'] / $stats['total_slots']) * 100, 1);
            }

            // Statistiques par jour de la semaine
            $weeklyStats = TimeSlot::where('doctor_id', $doctor->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->selectRaw('DAYOFWEEK(date) as day_of_week, status, COUNT(*) as count')
                ->groupBy('day_of_week', 'status')
                ->get()
                ->groupBy('day_of_week');

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'weekly_stats' => $weeklyStats,
                    'period' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Méthodes privées utilitaires
     */
    private function generateMissingSlots($doctor, $startDate, $endDate)
    {
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($current->lte($end)) {
            $dayOfWeek = strtolower($current->format('l'));

            if (in_array($dayOfWeek, $doctor->working_days)) {
                // Vérifier si des créneaux existent déjà pour cette date
                $existingSlots = TimeSlot::where('doctor_id', $doctor->id)
                    ->where('date', $current->format('Y-m-d'))
                    ->exists();

                if (!$existingSlots) {
                    $this->generateSlotsForDate($doctor, $current);
                }
            }

            $current->addDay();
        }
    }

    private function generateSlotsForDate($doctor, $date)
    {
        $slots = $doctor->generateTimeSlots($date);
        $slotsData = [];

        foreach ($slots as $slot) {
            $slotsData[] = [
                'doctor_id' => $doctor->id,
                'date' => $date->format('Y-m-d'),
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        if (!empty($slotsData)) {
            TimeSlot::insert($slotsData);
        }

        return count($slotsData);
    }

    private function generateSlotsForPeriod($doctor, $startDate, $endDate)
    {
        $totalGenerated = 0;
        $current = $startDate->copy();

        while ($current->lte($endDate)) {
            $dayOfWeek = strtolower($current->format('l'));

            if (in_array($dayOfWeek, $doctor->working_days)) {
                $generated = $this->generateSlotsForDate($doctor, $current);
                $totalGenerated += $generated;
            }

            $current->addDay();
        }

        return $totalGenerated;
    }
}
