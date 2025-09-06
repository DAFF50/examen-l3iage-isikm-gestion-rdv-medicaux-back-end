<?php
// app/Http/Controllers/SpecialtyController.php

namespace App\Http\Controllers;

use App\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class SpecialtyController extends Controller
{
    /**
     * Lister toutes les spécialités actives
     */
    public function index(Request $request)
    {
        try {
            $query = Specialty::active()->withCount('doctors');

            // Filtres
            if ($request->has('search')) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            }

            // Tri
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $specialties = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $specialties
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des spécialités'
            ], 500);
        }
    }

    /**
     * Lister toutes les spécialités (pour admin)
     */
    public function adminIndex(Request $request)
    {
        try {
            $query = Specialty::withCount('doctors');

            // Filtres
            if ($request->has('search')) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            // Tri
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $specialties = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $specialties
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des spécialités'
            ], 500);
        }
    }

    /**
     * Créer une nouvelle spécialité
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:specialties,name',
            'description' => 'nullable|string',
            'consultation_fee' => 'required|numeric|min:0',
            'icon' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:1024',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only(['name', 'description', 'consultation_fee', 'is_active']);

            // Gestion de l'icône
            if ($request->hasFile('icon')) {
                $iconPath = $request->file('icon')->store('specialties', 'public');
                $data['icon'] = $iconPath;
            }

            $specialty = Specialty::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Spécialité créée avec succès',
                'data' => $specialty->load('doctors:id,user_id,specialty_id')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la spécialité',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher une spécialité
     */
    public function show($id)
    {
        try {
            $specialty = Specialty::with(['doctors.user:id,name,email'])
                ->withCount('doctors')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $specialty
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Spécialité non trouvée'
            ], 404);
        }
    }

    /**
     * Mettre à jour une spécialité
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255|unique:specialties,name,' . $id,
            'description' => 'nullable|string',
            'consultation_fee' => 'numeric|min:0',
            'icon' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:1024',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $specialty = Specialty::findOrFail($id);
            $data = $request->only(['name', 'description', 'consultation_fee', 'is_active']);

            // Gestion de l'icône
            if ($request->hasFile('icon')) {
                // Supprimer l'ancienne icône
                if ($specialty->icon) {
                    Storage::delete('public/' . $specialty->icon);
                }

                $iconPath = $request->file('icon')->store('specialties', 'public');
                $data['icon'] = $iconPath;
            }

            $specialty->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Spécialité mise à jour avec succès',
                'data' => $specialty->load('doctors:id,user_id,specialty_id')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une spécialité
     */
    public function destroy($id)
    {
        try {
            $specialty = Specialty::findOrFail($id);

            // Vérifier s'il y a des docteurs associés
            if ($specialty->doctors()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer une spécialité ayant des médecins associés'
                ], 400);
            }

            // Supprimer l'icône
            if ($specialty->icon) {
                Storage::delete('public/' . $specialty->icon);
            }

            $specialty->delete();

            return response()->json([
                'success' => true,
                'message' => 'Spécialité supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression'
            ], 500);
        }
    }

    /**
     * Activer/Désactiver une spécialité
     */
    public function toggleStatus($id)
    {
        try {
            $specialty = Specialty::findOrFail($id);
            $specialty->update(['is_active' => !$specialty->is_active]);

            return response()->json([
                'success' => true,
                'message' => $specialty->is_active ? 'Spécialité activée' : 'Spécialité désactivée',
                'data' => $specialty
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut'
            ], 500);
        }
    }

    /**
     * Récupérer les spécialités les plus populaires
     */
    public function popular(Request $request)
    {
        try {
            $limit = $request->get('limit', 5);

            $popularSpecialties = Specialty::active()
                ->withCount(['doctors as total_appointments' => function($query) {
                    $query->join('appointments', 'doctors.id', '=', 'appointments.doctor_id')
                        ->where('appointments.status', '!=', 'cancelled');
                }])
                ->orderBy('total_appointments', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $popularSpecialties
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des spécialités populaires'
            ], 500);
        }
    }

    /**
     * Statistiques d'une spécialité
     */
    public function statistics($id)
    {
        try {
            $specialty = Specialty::withCount([
                'doctors',
                'doctors as verified_doctors_count' => function($query) {
                    $query->where('is_verified', true);
                }
            ])->findOrFail($id);

            // Nombre de RDV par mois pour cette spécialité
            $monthlyAppointments = \DB::table('appointments')
                ->join('doctors', 'appointments.doctor_id', '=', 'doctors.id')
                ->where('doctors.specialty_id', $id)
                ->where('appointments.created_at', '>=', now()->subMonths(12))
                ->selectRaw('MONTH(appointments.created_at) as month, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            // Revenus générés
            $totalRevenue = \DB::table('appointments')
                ->join('doctors', 'appointments.doctor_id', '=', 'doctors.id')
                ->where('doctors.specialty_id', $id)
                ->where('appointments.payment_status', 'paid')
                ->sum('appointments.amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'specialty' => $specialty,
                    'monthly_appointments' => $monthlyAppointments,
                    'total_revenue' => $totalRevenue,
                    'average_consultation_fee' => $specialty->doctors()->avg('consultation_fee')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }
}
