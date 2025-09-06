<?php
// app/Http/Controllers/DashboardController.php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Doctor;
use App\Models\Appointment;
use App\Models\Specialty;
use App\Models\Payment;
use App\Models\Notification;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Dashboard principal selon le type d'utilisateur
     */
    public function index(Request $request)
    {
        $user = $request->user();

        switch ($user->user_type) {
            case 'admin':
                return $this->adminDashboard($request);
            case 'doctor':
                return $this->doctorDashboard($request);
            case 'patient':
                return $this->patientDashboard($request);
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Type d\'utilisateur non reconnu'
                ], 400);
        }
    }

    /**
     * Dashboard Patient
     */
    private function patientDashboard(Request $request)
    {
        try {
            $user = $request->user();

            // Statistiques générales
            $stats = [
                'total_appointments' => $user->patientAppointments()->count(),
                'upcoming_appointments' => $user->patientAppointments()
                    ->where('appointment_date', '>=', now())
                    ->where('status', 'confirmed')
                    ->count(),
                'completed_appointments' => $user->patientAppointments()
                    ->where('status', 'completed')
                    ->count(),
                'total_spent' => $user->payments()
                    ->where('status', 'completed')
                    ->sum('amount'),
                'pending_payments' => $user->payments()
                    ->where('status', 'pending')
                    ->count()
            ];

            // Prochain rendez-vous
            $nextAppointment = $user->patientAppointments()
                ->with(['doctor.user', 'doctor.specialty'])
                ->where('appointment_date', '>=', now())
                ->where('status', 'confirmed')
                ->orderBy('appointment_date')
                ->first();

            // Historique récent
            $recentAppointments = $user->patientAppointments()
                ->with(['doctor.user', 'doctor.specialty'])
                ->orderBy('appointment_date', 'desc')
                ->limit(5)
                ->get();

            // Spécialités fréquentées
            $frequentSpecialties = $user->patientAppointments()
                ->join('doctors', 'appointments.doctor_id', '=', 'doctors.id')
                ->join('specialties', 'doctors.specialty_id', '=', 'specialties.id')
                ->selectRaw('specialties.name, COUNT(*) as count')
                ->groupBy('specialties.id', 'specialties.name')
                ->orderBy('count', 'desc')
                ->limit(5)
                ->get();

            // Notifications non lues
            $unreadNotifications = $user->notifications()->unread()->limit(5)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_type' => 'patient',
                    'user' => $user,
                    'stats' => $stats,
                    'next_appointment' => $nextAppointment,
                    'recent_appointments' => $recentAppointments,
                    'frequent_specialties' => $frequentSpecialties,
                    'unread_notifications' => $unreadNotifications
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
     * Dashboard Médecin
     */
    private function doctorDashboard(Request $request)
    {
        try {
            $user = $request->user();
            $doctor = $user->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil médecin non trouvé'
                ], 404);
            }

            $today = now();
            $thisMonth = $today->copy()->startOfMonth();

            // Statistiques du médecin
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
                    : 0,
                'average_rating' => $doctor->rating,
                'total_reviews' => $doctor->total_reviews
            ];

            // Rendez-vous d'aujourd'hui
            $todayAppointments = $doctor->appointments()
                ->with(['patient:id,name,phone', 'timeSlot'])
                ->whereDate('appointment_date', $today)
                ->where('status', '!=', 'cancelled')
                ->orderBy('appointment_time')
                ->get();

            // Prochains rendez-vous
            $upcomingAppointments = $doctor->appointments()
                ->with(['patient:id,name,phone'])
                ->where('appointment_date', '>', $today)
                ->where('status', 'confirmed')
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

            // Créneaux disponibles aujourd'hui
            $availableSlots = $doctor->timeSlots()
                ->where('date', $today->format('Y-m-d'))
                ->where('status', 'available')
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_type' => 'doctor',
                    'user' => $user,
                    'doctor' => $doctor->load(['specialty']),
                    'stats' => $stats,
                    'today_appointments' => $todayAppointments,
                    'upcoming_appointments' => $upcomingAppointments,
                    'monthly_revenue' => $monthlyRevenue,
                    'available_slots_today' => $availableSlots
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
     * Dashboard Admin
     */
    private function adminDashboard(Request $request)
    {
        try {
            $today = now();
            $thisMonth = $today->copy()->startOfMonth();

            // Statistiques générales
            $stats = [
                'total_users' => User::count(),
                'total_patients' => User::where('user_type', 'patient')->count(),
                'total_doctors' => User::where('user_type', 'doctor')->count(),
                'verified_doctors' => Doctor::where('is_verified', true)->count(),
                'pending_doctors' => Doctor::where('is_verified', false)->count(),
                'total_specialties' => Specialty::count(),
                'active_specialties' => Specialty::where('is_active', true)->count(),

                'total_appointments' => Appointment::count(),
                'today_appointments' => Appointment::whereDate('appointment_date', $today)->count(),
                'pending_appointments' => Appointment::where('status', 'pending')->count(),
                'completed_appointments' => Appointment::where('status', 'completed')->count(),

                'total_revenue' => Payment::where('status', 'completed')->sum('amount'),
                'monthly_revenue' => Payment::where('status', 'completed')
                    ->where('paid_at', '>=', $thisMonth)
                    ->sum('amount'),
                'pending_payments' => Payment::where('status', 'pending')->count(),
                'failed_payments' => Payment::where('status', 'failed')->count(),
            ];

            // Utilisateurs récents
            $recentUsers = User::orderBy('created_at', 'desc')->limit(10)->get();

            // Médecins en attente de vérification
            $pendingDoctors = Doctor::with(['user', 'specialty'])
                ->where('is_verified', false)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            // Rendez-vous récents
            $recentAppointments = Appointment::with(['patient:id,name', 'doctor.user:id,name'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Statistiques des spécialités les plus demandées
            $popularSpecialties = Specialty::withCount([
                'doctors as total_appointments' => function($query) {
                    $query->join('appointments', 'doctors.id', '=', 'appointments.doctor_id')
                        ->where('appointments.status', '!=', 'cancelled');
                }
            ])
                ->orderBy('total_appointments', 'desc')
                ->limit(5)
                ->get();

            // Revenus par mois (12 derniers mois)
            $monthlyRevenue = [];
            for ($i = 11; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $revenue = Payment::where('status', 'completed')
                    ->whereYear('paid_at', $month->year)
                    ->whereMonth('paid_at', $month->month)
                    ->sum('amount');

                $monthlyRevenue[] = [
                    'month' => $month->format('M Y'),
                    'revenue' => $revenue
                ];
            }

            // Statistiques par type de paiement
            $paymentStats = Payment::where('status', 'completed')
                ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get();

            // Taux de croissance (comparaison avec le mois précédent)
            $lastMonth = $today->copy()->subMonth()->startOfMonth();
            $lastMonthEnd = $lastMonth->copy()->endOfMonth();

            $growthStats = [
                'users_growth' => $this->calculateGrowthRate(
                    User::where('created_at', '>=', $thisMonth)->count(),
                    User::whereBetween('created_at', [$lastMonth, $lastMonthEnd])->count()
                ),
                'appointments_growth' => $this->calculateGrowthRate(
                    Appointment::where('created_at', '>=', $thisMonth)->count(),
                    Appointment::whereBetween('created_at', [$lastMonth, $lastMonthEnd])->count()
                ),
                'revenue_growth' => $this->calculateGrowthRate(
                    Payment::where('status', 'completed')->where('paid_at', '>=', $thisMonth)->sum('amount'),
                    Payment::where('status', 'completed')->whereBetween('paid_at', [$lastMonth, $lastMonthEnd])->sum('amount')
                )
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'user_type' => 'admin',
                    'user' => $request->user(),
                    'stats' => $stats,
                    'growth_stats' => $growthStats,
                    'recent_users' => $recentUsers,
                    'pending_doctors' => $pendingDoctors,
                    'recent_appointments' => $recentAppointments,
                    'popular_specialties' => $popularSpecialties,
                    'monthly_revenue' => $monthlyRevenue,
                    'payment_stats' => $paymentStats
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du dashboard admin'
            ], 500);
        }
    }

    /**
     * Recherche globale
     */
    public function search(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'query' => 'required|string|min:2|max:100',
            'type' => 'nullable|in:all,doctors,specialties,appointments'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = $request->query;
            $type = $request->get('type', 'all');
            $results = [];

            if ($type === 'all' || $type === 'doctors') {
                $doctors = Doctor::with(['user', 'specialty'])
                    ->whereHas('user', function($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%");
                    })
                    ->orWhere('clinic_name', 'like', "%{$query}%")
                    ->where('is_verified', true)
                    ->limit(5)
                    ->get();

                $results['doctors'] = $doctors->map(function($doctor) {
                    return [
                        'id' => $doctor->id,
                        'name' => $doctor->user->name,
                        'specialty' => $doctor->specialty->name,
                        'clinic_name' => $doctor->clinic_name,
                        'rating' => $doctor->rating,
                        'type' => 'doctor'
                    ];
                });
            }

            if ($type === 'all' || $type === 'specialties') {
                $specialties = Specialty::where('name', 'like', "%{$query}%")
                    ->where('is_active', true)
                    ->withCount('doctors')
                    ->limit(5)
                    ->get();

                $results['specialties'] = $specialties->map(function($specialty) {
                    return [
                        'id' => $specialty->id,
                        'name' => $specialty->name,
                        'description' => $specialty->description,
                        'doctors_count' => $specialty->doctors_count,
                        'type' => 'specialty'
                    ];
                });
            }

            if ($type === 'all' || $type === 'appointments') {
                $user = $request->user();

                if ($user->user_type === 'patient') {
                    $appointments = $user->patientAppointments()
                        ->with(['doctor.user', 'doctor.specialty'])
                        ->whereHas('doctor.user', function($q) use ($query) {
                            $q->where('name', 'like', "%{$query}%");
                        })
                        ->orWhereHas('doctor.specialty', function($q) use ($query) {
                            $q->where('name', 'like', "%{$query}%");
                        })
                        ->orWhere('appointment_number', 'like', "%{$query}%")
                        ->limit(5)
                        ->get();

                    $results['appointments'] = $appointments->map(function($appointment) {
                        return [
                            'id' => $appointment->id,
                            'appointment_number' => $appointment->appointment_number,
                            'doctor_name' => $appointment->doctor->user->name,
                            'specialty' => $appointment->doctor->specialty->name,
                            'date' => $appointment->appointment_date->format('d/m/Y H:i'),
                            'status' => $appointment->status,
                            'type' => 'appointment'
                        ];
                    });
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'query' => $query,
                    'results' => $results,
                    'total_results' => collect($results)->flatten()->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche'
            ], 500);
        }
    }

    /**
     * Statistiques utilisateur
     */
    public function userStatistics(Request $request)
    {
        try {
            $user = $request->user();
            $stats = [];

            switch ($user->user_type) {
                case 'patient':
                    $stats = [
                        'total_appointments' => $user->patientAppointments()->count(),
                        'completed_appointments' => $user->patientAppointments()->where('status', 'completed')->count(),
                        'cancelled_appointments' => $user->patientAppointments()->where('status', 'cancelled')->count(),
                        'total_spent' => $user->payments()->where('status', 'completed')->sum('amount'),
                        'favorite_specialty' => $this->getFavoriteSpecialty($user),
                        'account_age_days' => $user->created_at->diffInDays(now()),
                        'last_appointment' => $user->patientAppointments()->latest('appointment_date')->first()?->appointment_date
                    ];
                    break;

                case 'doctor':
                    $doctor = $user->doctor;
                    $stats = [
                        'total_appointments' => $doctor->appointments()->count(),
                        'completed_appointments' => $doctor->appointments()->where('status', 'completed')->count(),
                        'total_revenue' => $doctor->appointments()->where('payment_status', 'paid')->sum('amount'),
                        'average_rating' => $doctor->rating,
                        'total_reviews' => $doctor->total_reviews,
                        'patients_served' => $doctor->appointments()->distinct('patient_id')->count(),
                        'account_age_days' => $user->created_at->diffInDays(now()),
                        'is_verified' => $doctor->is_verified
                    ];
                    break;

                case 'admin':
                    $stats = [
                        'total_users_managed' => User::count(),
                        'total_appointments_managed' => Appointment::count(),
                        'total_revenue_managed' => Payment::where('status', 'completed')->sum('amount'),
                        'account_age_days' => $user->created_at->diffInDays(now())
                    ];
                    break;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user_type' => $user->user_type,
                    'stats' => $stats
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
     * Méthodes utilitaires privées
     */
    private function calculateGrowthRate($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function getFavoriteSpecialty($user)
    {
        return $user->patientAppointments()
            ->join('doctors', 'appointments.doctor_id', '=', 'doctors.id')
            ->join('specialties', 'doctors.specialty_id', '=', 'specialties.id')
            ->selectRaw('specialties.name, COUNT(*) as count')
            ->groupBy('specialties.id', 'specialties.name')
            ->orderBy('count', 'desc')
            ->first()?->name;
    }
}
