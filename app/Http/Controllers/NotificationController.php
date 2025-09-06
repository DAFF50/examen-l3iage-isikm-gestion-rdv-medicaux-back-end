<?php
// app/Http/Controllers/NotificationController.php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NotificationController extends Controller
{
    /**
     * Lister les notifications de l'utilisateur
     */
    public function index(Request $request)
    {
        try {
            $query = Notification::where('user_id', $request->user()->id);

            // Filtres
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('is_read')) {
                $query->where('is_read', $request->is_read);
            }

            if ($request->has('channel')) {
                $query->where('channel', $request->channel);
            }

            // Tri
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = (int) $request->get('per_page', 15);
            $notifications = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $notifications
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@index : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notifications'
            ], 500);
        }
    }

    /**
     * Lister les notifications non lues
     */
    public function unread(Request $request)
    {
        try {
            $notifications = Notification::where('user_id', $request->user()->id)
                ->unread()
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'notifications' => $notifications,
                    'unread_count' => $notifications->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@unread : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notifications'
            ], 500);
        }
    }

    /**
     * Marquer une notification comme lue
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $notification = Notification::where('user_id', $request->user()->id)
                ->findOrFail($id);

            // Si la méthode markAsRead existe sur le modèle, l'utiliser ; sinon, mise à jour manuelle.
            if (method_exists($notification, 'markAsRead')) {
                $notification->markAsRead();
            } else {
                $notification->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification marquée comme lue',
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            Log::warning("Notification non trouvée ou accès refusé (user_id={$request->user()->id}, id={$id}) : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Notification non trouvée'
            ], 404);
        }
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $updated = Notification::where('user_id', $request->user()->id)
                ->unread()
                ->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Toutes les notifications ont été marquées comme lues',
                'data' => ['updated_count' => $updated]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@markAllAsRead : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour'
            ], 500);
        }
    }

    /**
     * Supprimer une notification
     */
    public function destroy(Request $request, $id)
    {
        try {
            $notification = Notification::where('user_id', $request->user()->id)
                ->findOrFail($id);

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            Log::warning("Suppression notification échouée (user_id={$request->user()->id}, id={$id}) : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Notification non trouvée'
            ], 404);
        }
    }

    /**
     * Supprimer toutes les notifications lues
     */
    public function deleteRead(Request $request)
    {
        try {
            $deleted = Notification::where('user_id', $request->user()->id)
                ->read()
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notifications lues supprimées',
                'data' => ['deleted_count' => $deleted]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@deleteRead : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression'
            ], 500);
        }
    }

    /**
     * Créer une notification système
     */
    public function createSystemNotification(Request $request)
    {
        // Seulement pour les admins
        if ($request->user()->user_type !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string|max:50',
            'channel' => 'in:database,email,sms',
            'data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $notifications = [];
            $channel = $request->get('channel', 'database');

            foreach ($request->user_ids as $userId) {
                $notification = Notification::createForUser(
                    $userId,
                    $request->type,
                    $request->title,
                    $request->message,
                    $request->data ?? [],
                    $channel
                );

                $notifications[] = $notification;

                // Envoyer par email si requis
                if ($channel === 'email') {
                    $this->sendEmailNotification($notification);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Notifications créées avec succès',
                'data' => ['created_count' => count($notifications)]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@createSystemNotification : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création des notifications'
            ], 500);
        }
    }

    /**
     * Diffuser une notification à tous les utilisateurs
     */
    public function broadcastNotification(Request $request)
    {
        // Seulement pour les admins
        if ($request->user()->user_type !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string|max:50',
            'user_types' => 'nullable|array',
            'user_types.*' => 'in:patient,doctor,admin',
            'channel' => 'in:database,email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = User::query();

            // Filtrer par type d'utilisateur si spécifié
            if ($request->has('user_types')) {
                $query->whereIn('user_type', $request->user_types);
            }

            $users = $query->get(['id', 'email', 'name']);
            $createdCount = 0;
            $channel = $request->get('channel', 'database');

            foreach ($users as $user) {
                $notification = Notification::createForUser(
                    $user->id,
                    $request->type,
                    $request->title,
                    $request->message,
                    ['broadcast' => true],
                    $channel
                );

                if ($channel === 'email') {
                    $this->sendEmailNotification($notification, $user);
                }

                $createdCount++;
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification diffusée avec succès',
                'data' => [
                    'recipients_count' => $createdCount,
                    'user_types' => $request->user_types ?? ['all']
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@broadcastNotification : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la diffusion'
            ], 500);
        }
    }

    /**
     * Statistiques des notifications (admin)
     */
    public function statistics(Request $request)
    {
        // Seulement pour les admins
        if ($request->user()->user_type !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        try {
            $stats = [
                'total_notifications' => Notification::count(),
                'unread_notifications' => Notification::unread()->count(),
                'notifications_today' => Notification::whereDate('created_at', today())->count(),
                'notifications_this_week' => Notification::where('created_at', '>=', now()->startOfWeek())->count(),
            ];

            // Statistiques par type
            $statsByType = Notification::selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->orderBy('count', 'desc')
                ->get();

            // Statistiques par canal
            $statsByChannel = Notification::selectRaw('channel, COUNT(*) as count')
                ->groupBy('channel')
                ->get();

            // Notifications les plus récentes
            $recentNotifications = Notification::with('user:id,name,email')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'by_type' => $statsByType,
                    'by_channel' => $statsByChannel,
                    'recent_notifications' => $recentNotifications
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@statistics : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Configuration des préférences de notification
     */
    public function updatePreferences(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email_notifications' => 'boolean',
            'sms_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'notification_types' => 'array',
            'notification_types.*' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();

            $preferences = [
                'email_notifications' => (bool) $request->get('email_notifications', true),
                'sms_notifications' => (bool) $request->get('sms_notifications', false),
                'push_notifications' => (bool) $request->get('push_notifications', true),
                'notification_types' => $request->get('notification_types', [
                    'appointment_confirmed',
                    'appointment_reminder',
                    'payment_success',
                    'appointment_cancelled'
                ])
            ];

            // Sauvegarde dans un champ JSON (ou une table dédiée selon ton modèle)
            $user->update([
                'notification_preferences' => $preferences
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Préférences mises à jour avec succès',
                'data' => $preferences
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@updatePreferences : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des préférences'
            ], 500);
        }
    }

    /**
     * Test d'envoi d'email
     */
    public function testEmail(Request $request)
    {
        try {
            $user = $request->user();

            $notification = Notification::createForUser(
                $user->id,
                'test',
                'Test de notification email',
                'Ceci est un test d\'envoi d\'email depuis la plateforme médicale.',
                ['test' => true],
                'email'
            );

            $this->sendEmailNotification($notification, $user);

            return response()->json([
                'success' => true,
                'message' => 'Email de test envoyé avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur NotificationController@testEmail : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi de l\'email de test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Envoyer une notification par email
     *
     * - $notification : instance de App\Models\Notification
     * - $user : facultatif, instance de App\Models\User pour éviter une requête supplémentaire
     *
     * Le code utilise une Mailable `App\Mail\SystemNotificationMail` si elle existe,
     * sinon il envoie un email brut en fallback.
     */
    private function sendEmailNotification($notification, $user = null)
    {
        try {
            if (!$user) {
                $user = User::find($notification->user_id ?? null);
                if (!$user) {
                    Log::warning("sendEmailNotification: utilisateur introuvable pour notification id=" . ($notification->id ?? 'N/A'));
                    return false;
                }
            }

            if (empty($user->email)) {
                Log::warning("sendEmailNotification: utilisateur #{$user->id} sans email, notification id={$notification->id}");
                return false;
            }

            // Si une Mailable existe, l'utiliser (meilleur pour templates HTML)
            if (class_exists(\App\Mail\SystemNotificationMail::class)) {
                Mail::to($user->email)->queue(new \App\Mail\SystemNotificationMail($notification, $user));
            } else {
                // Fallback: envoi simple en texte brut
                $subject = $notification->title ?? 'Nouvelle notification';
                $body = $notification->message ?? json_encode($notification->data ?? []);

                Mail::raw($body, function ($message) use ($user, $subject) {
                    $message->to($user->email)->subject($subject);
                });
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Erreur sendEmailNotification : ' . $e->getMessage(), [
                'notification_id' => $notification->id ?? null,
                'user_id' => $user->id ?? null
            ]);
            return false;
        }
    }
}
