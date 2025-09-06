<?php
// app/Http/Controllers/DoctorController.php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\User;
use App\Models\Specialty;
use App\Models\TimeSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DoctorController extends Controller
{
    /**
     * Lister tous les docteurs avec filtres
     */
    public function index(Request $request)
    {
        try {
            $query = Doctor::with(['user:id,name,email,phone', 'specialty:id,name'])
                ->verified();

            // Filtres
            if ($request->has('specialty_id') && $request->specialty_id) {
                $query->where('specialty_id', $request->specialty_id);
            }

            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('user', function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('clinic_name', 'like', "%{$search}%");
            }

            if ($request->has('city') && $request->city) {
                $query->where('clinic_address', 'like', "%{$request->city}%");
            }

            if ($request->has('accepts_online_payment')) {
                $query->where('accepts_online_payment', $request->accepts_online_payment);
            }

            if ($request->has('min_fee') && $request->min_fee) {
                $query->where('consultation_fee', '>=', $request->min_fee);
            }

            if ($request->has('max_fee') && $request->max_fee) {
                $query->where('consultation_fee', '<=', $request->max_fee);
            }

            // Disponibilité pour une date donnée
            if ($request->has('available_date')) {
                $date = Carbon::parse($request->available_date);
                $query->whereHas('timeSlots', function($q) use ($date) {
                    $q->where('date', $date->format('Y-m-d'))
                        ->where('status', 'available');
                });
            }

            // Tri
            $sortBy = $request->get('sort_by', 'rating');
            $sortOrder = $request->get('sort_order', 'desc');

            if ($sortBy === 'rating') {
                $query->orderBy('rating', $sortOrder)
                    ->orderBy('total_reviews', 'desc');
            } elseif ($sortBy === 'experience') {
                $query->orderBy('experience_years', $sortOrder);
            } elseif ($sortBy === 'fee') {
                $query->orderBy('consultation_fee', $sortOrder);
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 12);
            $doctors = $query->paginate($perPage);

            // Ajouter des données supplémentaires
            $doctors->getCollection()->transform(function ($doctor) {
                $doctor->total_appointments = $doctor->appointments()->count();
                $doctor->next_available_slot = $this->getNextAvailableSlot($doctor);
                return $doctor;
            });

            return response()->json([
                'success' => true,
                'data' => $doctors
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des médecins',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher un docteur avec ses créneaux disponibles
     */
    public function show(Request $request, $id)
    {
        try {
            $doctor = Doctor::with([
                'user:id,name,email,phone,profile_image,bio',
                'specialty:id,name,description'
            ])->findOrFail($id);

            // Créneaux disponibles pour les 30 prochains jours
            $startDate = $request->get('start_date', now()->format('Y-m-d'));
            $endDate = $request->get('end_date', now()->addDays(30)->format('Y-m-d'));

            $availableSlots = $this->getAvailableSlots($doctor, $startDate, $endDate);

            // Statistiques du docteur
            $stats = [
                'total_appointments' => $doctor->appointments()->count(),
                'completed_appointments' => $doctor->appointments()->where('status', 'completed')->count(),
                'rating' => $doctor->rating,
                'total_reviews' => $doctor->total_reviews,
                'response_time' => '2 heures', // À calculer selon vos besoins
                'success_rate' => $doctor->appointments()->count() > 0
                    ? round(($doctor->appointments()->where('status', 'completed')->count() / $doctor->appointments()->count()) * 100)
                    : 0
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'doctor' => $doctor,
                    'available_slots' => $availableSlots,
                    'stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Médecin non trouvé'
            ], 404);
        }
    }

    /**
     * Créer un nouveau docteur
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:20',
            'specialty_id' => 'required|exists:specialties,id',
            'license_number' => 'required|string|unique:doctors,license_number',
            'experience_years' => 'required|integer|min:0|max:50',
            'consultation_fee' => 'required|numeric|min:0',
            'clinic_name' => 'required|string|max:255',
            'clinic_address' => 'required|string|max:500',
            'working_days' => 'required|array|min:1',
            'working_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'working_start_time' => 'required|date_format:H:i',
            'working_end_time' => 'required|date_format:H:i|after:working_start_time',
            'appointment_duration' => 'required|integer|min:15|max:180',
            'qualifications' => 'nullable|string',
            'bio' => 'nullable|string|max:1000',
            'accepts_online_payment' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            \DB::beginTransaction();

            // Créer l'utilisateur
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'user_type' => 'doctor',
                'bio' => $request->bio
            ]);

            // Assigner le rôle docteur
            $user->assignRole('doctor');

            // Créer le profil docteur
            $doctor = Doctor::create([
                'user_id' => $user->id,
                'specialty_id' => $request->specialty_id,
                'license_number' => $request->license_number,
                'experience_years' => $request->experience_years,
                'consultation_fee' => $request->consultation_fee,
                'clinic_name' => $request->clinic_name,
                'clinic_address' => $request->clinic_address,
                'working_days' => $request->working_days,
                'working_start_time' => $request->working_start_time,
                'working_end_time' => $request->working_end_time,
                'appointment_duration' => $request->appointment_duration,
                'qualifications' => $request->qualifications,
                'accepts_online_payment' => $request->accepts_online_payment ?? true,
            ]);

            // Générer les créneaux pour les 30 prochains jours
            $this->generateTimeSlots($doctor, 30);

            \DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Médecin créé avec succès',
                'data' => $doctor->load(['user', 'specialty'])
            ], 201);

        } catch (\Exception $e) {
            \DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du médecin',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour un docteur
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'phone' => 'string|max:20',
            'specialty_id' => 'exists:specialties,id',
            'experience_years' => 'integer|min:0|max:50',
            'consultation_fee' => 'numeric|min:0',
            'clinic_name' => 'string|max:255',
            'clinic_address' => 'string|max:500',
            'working_days' => 'array|min:1',
            'working_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'working_start_time' => 'date_format:H:i',
            'working_end_time' => 'date_format:H:i|after:working_start_time',
            'appointment_duration' => 'integer|min:15|max:180',
            'qualifications' => 'nullable|string',
            'bio' => 'nullable|string|max:1000',
            'accepts_online_payment' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $doctor = Doctor::findOrFail($id);

            \DB::beginTransaction();

            // Mettre à jour l'utilisateur
            if ($request->has('name') || $request->has('phone') || $request->has('bio')) {
                $doctor->user->update($request->only(['name', 'phone', 'bio']));
            }

            // Mettre à jour le docteur
            $doctorData = $request->only([
                'specialty_id', 'experience_years', 'consultation_fee',
                'clinic_name', 'clinic_address', 'working_days',
                'working_start_time', 'working_end_time', 'appointment_duration',
                'qualifications', 'accepts_online_payment'
            ]);

            $doctor->update($doctorData);

            // Si les horaires ont changé, régénérer les créneaux futurs
            if ($request->has(['working_days', 'working_start_time', 'working_end_time', 'appointment_duration'])) {
                $this->regenerateTimeSlots($doctor);
            }

            \DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Médecin mis à jour avec succès',
                'data' => $doctor->load(['user', 'specialty'])
            ]);

        } catch (\Exception $e) {
            \DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifier/Dévérifier un docteur
     */
    public function toggleVerification($id)
    {
        try {
            $doctor = Doctor::findOrFail($id);
            $doctor->update(['is_verified' => !$doctor->is_verified]);

            return response()->json([
                'success' => true,
                'message' => $doctor->is_verified ? 'Médecin vérifié' : 'Vérification révoquée',
                'data' => $doctor->load(['user', 'specialty'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du statut'
            ], 500);
        }
    }

    /**
     * Dashboard médecin
     */
    public function dashboard(Request $request)
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil médecin non trouvé'
                ], 404);
            }

            $today = now();
            $thisMonth = $today->copy()->startOfMonth();

            // Statistiques
            $stats = [
                'today_appointments' => $doctor->appointments()
                    ->whereDate('appointment_date', $today)
                    ->where('status', '!=', 'cancelled')
                    ->count(),

                'pending_appointments' => $doctor->appointments()
                    ->where('status', 'pending')
                    ->count(),

                'monthly_revenue' => $doctor->appointments()
                    ->where('appointment_date', '>=', $thisMonth)
                    ->where('payment_status', 'paid')
                    ->sum('amount'),

                'total_patients' => $doctor->appointments()
                    ->distinct('patient_id')
                    ->count(),

                'completion_rate' => $doctor->appointments()->count() > 0
                    ? round(($doctor->appointments()->where('status', 'completed')->count() / $doctor->appointments()->count()) * 100, 1)
                    : 0
            ];

            // Prochains rendez-vous
            $upcomingAppointments = $doctor->appointments()
                ->with(['patient:id,name,phone', 'timeSlot'])
                ->where('status', 'confirmed')
                ->where('appointment_date', '>=', now())
                ->orderBy('appointment_date')
                ->limit(5)
                ->get();

            // Revenus mensuels (6 derniers mois)
            $monthlyRevenue = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $revenue = $doctor->appointments()
                    ->whereYear('appointment_date', $month->year)
                    ->whereMonth('appointment_date', $month->month)
                    ->where('payment_status', 'paid')
                    ->sum('amount');

                $monthlyRevenue[] = [
                    'month' => $month->format('M Y'),
                    'revenue' => $revenue
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'doctor' => $doctor->load(['user', 'specialty']),
                    'stats' => $stats,
                    'upcoming_appointments' => $upcomingAppointments,
                    'monthly_revenue' => $monthlyRevenue
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du dashboard'
            ], 500);
        }
    }

    /**
     * Méthodes auxiliaires privées
     */
    private function getNextAvailableSlot($doctor)
    {
        return $doctor->timeSlots()
            ->where('date', '>=', now()->format('Y-m-d'))
            ->where('status', 'available')
            ->orderBy('date')
            ->orderBy('start_time')
            ->first();
    }

    private function getAvailableSlots($doctor, $startDate, $endDate)
    {
        return $doctor->timeSlots()
            ->whereBetween('date', [$startDate, $endDate])
            ->where('status', 'available')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->groupBy('date');
    }

    private function generateTimeSlots($doctor, $days = 30)
    {
        $slots = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->addDays($i);
            $dayOfWeek = strtolower($date->format('l'));

            if (in_array($dayOfWeek, $doctor->working_days)) {
                $generatedSlots = $doctor->generateTimeSlots($date);

                foreach ($generatedSlots as $slot) {
                    $slots[] = [
                        'doctor_id' => $doctor->id,
                        'date' => $date->format('Y-m-d'),
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'status' => 'available',
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
            }
        }

        if (!empty($slots)) {
            TimeSlot::insert($slots);
        }
    }

    private function regenerateTimeSlots($doctor)
    {
        // Supprimer les créneaux futurs non réservés
        $doctor->timeSlots()
            ->where('date', '>', now()->format('Y-m-d'))
            ->where('status', 'available')
            ->delete();

        // Régénérer les créneaux
        $this->generateTimeSlots($doctor, 30);
    }
}
