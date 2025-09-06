<?php
// routes/api.php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Importation de tous les contrôleurs
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TimeSlotController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\StatisticController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Health Check
Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Medical Appointment API',
        'version' => '1.0.0',
        'timestamp' => now()
    ]);
});

// Routes publiques (sans authentification)
Route::prefix('v1')->group(function () {

    // ===========================================
    // AUTHENTIFICATION
    // ===========================================
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // ===========================================
    // RESSOURCES PUBLIQUES
    // ===========================================

    // Spécialités publiques
    Route::prefix('specialties')->group(function () {
        Route::get('/', [SpecialtyController::class, 'index']);
        Route::get('popular', [SpecialtyController::class, 'popular']);
        Route::get('{id}', [SpecialtyController::class, 'show']);
    });

    // Médecins publiques
    Route::prefix('doctors')->group(function () {
        Route::get('/', [DoctorController::class, 'index']);
        Route::get('{id}', [DoctorController::class, 'show']);
        Route::get('{id}/available-slots', [TimeSlotController::class, 'getAvailableSlots']);
    });

    // Assistant IA (pour les visiteurs)
    Route::post('ai/chat', [AiAssistantController::class, 'chat'])->middleware('throttle:ai');

    // ===========================================
    // WEBHOOKS PAIEMENTS
    // ===========================================
    Route::prefix('webhooks')->group(function () {
        Route::post('stripe', [PaymentController::class, 'stripeWebhook']);
        Route::post('cinetpay', [PaymentController::class, 'cinetpayWebhook']);
    });
});

// ===========================================
// ROUTES PROTÉGÉES (AUTHENTIFICATION REQUISE)
// ===========================================
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    // ===========================================
    // PROFIL UTILISATEUR
    // ===========================================
    Route::prefix('auth')->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('change-password', [AuthController::class, 'changePassword']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
    });

    // ===========================================
    // DASHBOARD GÉNÉRAL
    // ===========================================
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('search', [DashboardController::class, 'search']);
    Route::get('user-statistics', [DashboardController::class, 'userStatistics']);

    // ===========================================
    // NOTIFICATIONS
    // ===========================================
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('unread', [NotificationController::class, 'unread']);
        Route::put('{id}/read', [NotificationController::class, 'markAsRead']);
        Route::put('mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('{id}', [NotificationController::class, 'destroy']);
    });

    // ===========================================
    // ASSISTANT IA AUTHENTIFIÉ
    // ===========================================
    Route::prefix('ai')->middleware('throttle:ai')->group(function () {
        Route::post('chat', [AiAssistantController::class, 'authenticatedChat']);
        Route::get('conversations', [AiAssistantController::class, 'getConversations']);
        Route::post('conversations/{id}/feedback', [AiAssistantController::class, 'giveFeedback']);
    });

    // ===========================================
    // PDF DOWNLOADS
    // ===========================================
    Route::prefix('pdf')->group(function () {
        Route::get('appointment/{id}', [PdfController::class, 'downloadAppointmentPdf']);
        Route::get('receipt/{id}', [PdfController::class, 'downloadPaymentReceipt']);
    });

    // ===========================================
    // ROUTES PATIENT
    // ===========================================
    Route::middleware(['check.user.type:patient'])->group(function () {

        // Rendez-vous patients
        Route::prefix('appointments')->group(function () {
            Route::get('/', [AppointmentController::class, 'patientAppointments']);
            Route::post('/', [AppointmentController::class, 'store']);
            Route::get('{id}', [AppointmentController::class, 'show']);
            Route::put('{id}/cancel', [AppointmentController::class, 'cancel']);
            Route::put('{id}/reschedule', [AppointmentController::class, 'reschedule']);
        });

        // Paiements patients
        Route::prefix('payments')->middleware('throttle:payments')->group(function () {
            Route::post('/', [PaymentController::class, 'processPayment']);
            Route::get('/', [PaymentController::class, 'patientPayments']);
            Route::get('{id}', [PaymentController::class, 'show']);
            Route::post('{id}/refund-request', [PaymentController::class, 'requestRefund']);
        });
    });

    // ===========================================
    // ROUTES DOCTEUR
    // ===========================================
    Route::middleware(['check.user.type:doctor'])->group(function () {

        // Dashboard médecin
        Route::get('doctor/dashboard', [DoctorController::class, 'dashboard']);

        // Profil médecin
        Route::prefix('doctor/profile')->group(function () {
            Route::get('/', [DoctorController::class, 'getProfile']);
            Route::put('/', [DoctorController::class, 'updateProfile']);
        });

        // Rendez-vous médecin
        Route::prefix('doctor/appointments')->group(function () {
            Route::get('/', [AppointmentController::class, 'doctorAppointments']);
            Route::get('today', [AppointmentController::class, 'todayAppointments']);
            Route::get('pending', [AppointmentController::class, 'pendingAppointments']);
            Route::put('{id}/confirm', [AppointmentController::class, 'confirm']);
            Route::put('{id}/reject', [AppointmentController::class, 'reject']);
            Route::put('{id}/complete', [AppointmentController::class, 'complete']);
            Route::put('{id}/no-show', [AppointmentController::class, 'markAsNoShow']);
            Route::put('{id}/notes', [AppointmentController::class, 'addNotes']);
            Route::put('{id}/prescription', [AppointmentController::class, 'addPrescription']);
        });

        // Créneaux horaires médecin
        Route::prefix('doctor/time-slots')->group(function () {
            Route::get('/', [TimeSlotController::class, 'doctorSlots']);
            Route::post('generate', [TimeSlotController::class, 'generateSlots']);
            Route::put('{id}/block', [TimeSlotController::class, 'blockSlot']);
            Route::put('{id}/unblock', [TimeSlotController::class, 'unblockSlot']);
            Route::delete('{id}', [TimeSlotController::class, 'deleteSlot']);
        });

        // Revenus médecin
        Route::prefix('doctor/revenue')->group(function () {
            Route::get('/', [PaymentController::class, 'doctorRevenue']);
            Route::get('monthly', [PaymentController::class, 'monthlyRevenue']);
            Route::get('statistics', [PaymentController::class, 'revenueStatistics']);
        });
    });

    // ===========================================
    // ROUTES ADMIN
    // ===========================================
    Route::middleware(['check.user.type:admin'])->prefix('admin')->group(function () {

        // Dashboard admin
        Route::get('dashboard', [AdminController::class, 'dashboard']);
        Route::get('statistics', [StatisticController::class, 'index']);

        // Gestion des utilisateurs
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('{id}', [UserController::class, 'show']);
            Route::put('{id}/activate', [UserController::class, 'activate']);
            Route::put('{id}/deactivate', [UserController::class, 'deactivate']);
            Route::delete('{id}', [UserController::class, 'destroy']);
            Route::get('export/csv', [UserController::class, 'exportCsv']);
        });

        // Gestion des spécialités
        Route::prefix('specialties')->group(function () {
            Route::get('/', [SpecialtyController::class, 'adminIndex']);
            Route::post('/', [SpecialtyController::class, 'store']);
            Route::put('{id}', [SpecialtyController::class, 'update']);
            Route::put('{id}/toggle-status', [SpecialtyController::class, 'toggleStatus']);
            Route::delete('{id}', [SpecialtyController::class, 'destroy']);
            Route::get('{id}/statistics', [SpecialtyController::class, 'statistics']);
        });

        // Gestion des médecins
        Route::prefix('doctors')->group(function () {
            Route::get('/', [AdminController::class, 'doctorsList']);
            Route::post('/', [DoctorController::class, 'store']);
            Route::put('{id}', [DoctorController::class, 'update']);
            Route::put('{id}/toggle-verification', [DoctorController::class, 'toggleVerification']);
            Route::delete('{id}', [AdminController::class, 'deleteDoctor']);
            Route::get('pending-verification', [AdminController::class, 'pendingVerification']);
        });

        // Gestion des rendez-vous
        Route::prefix('appointments')->group(function () {
            Route::get('/', [AdminController::class, 'appointmentsList']);
            Route::get('pending', [AdminController::class, 'pendingAppointments']);
            Route::get('statistics', [AdminController::class, 'appointmentsStatistics']);
            Route::get('{id}', [AppointmentController::class, 'adminShow']);
        });

        // Gestion des paiements
        Route::prefix('payments')->group(function () {
            Route::get('/', [AdminController::class, 'paymentsList']);
            Route::get('statistics', [AdminController::class, 'paymentsStatistics']);
            Route::put('{id}/refund', [PaymentController::class, 'processRefund']);
            Route::get('failed', [PaymentController::class, 'failedPayments']);
        });

        // Rapports et statistiques
        Route::prefix('reports')->group(function () {
            Route::get('revenue', [StatisticController::class, 'revenueReport']);
            Route::get('appointments', [StatisticController::class, 'appointmentsReport']);
            Route::get('doctors', [StatisticController::class, 'doctorsReport']);
            Route::get('patients', [StatisticController::class, 'patientsReport']);
            Route::get('export/revenue', [StatisticController::class, 'exportRevenueReport']);
        });

        // Configuration système
        Route::prefix('settings')->group(function () {
            Route::get('/', [AdminController::class, 'getSettings']);
            Route::put('/', [AdminController::class, 'updateSettings']);
        });

        // Notifications système
        Route::prefix('system')->group(function () {
            Route::post('broadcast', [AdminController::class, 'broadcastNotification']);
            Route::get('logs', [AdminController::class, 'getSystemLogs']);
        });
    });
});

// ===========================================
// ROUTES DE TEST (À SUPPRIMER EN PRODUCTION)
// ===========================================
Route::prefix('test')->middleware(['auth:sanctum'])->group(function () {
    Route::get('generate-pdf/{appointmentId}', [PdfController::class, 'testPdfGeneration']);
    Route::post('send-email', [NotificationController::class, 'testEmail']);
    Route::get('ai-test', [AiAssistantController::class, 'testAi']);
    Route::get('queue-test', function() {
        \App\Jobs\SendAppointmentConfirmation::dispatch(\App\Models\Appointment::first());
        return response()->json(['message' => 'Job dispatched']);
    });
});

// ===========================================
// ROUTE DE FALLBACK
// ===========================================
Route::fallback(function(){
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'available_endpoints' => [
            'GET /api/v1/specialties' => 'Liste des spécialités',
            'GET /api/v1/doctors' => 'Liste des médecins',
            'POST /api/v1/auth/register' => 'Inscription',
            'POST /api/v1/auth/login' => 'Connexion',
        ]
    ], 404);
});
