<?php
// app/Http/Controllers/PaymentController.php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Appointment;
use App\Jobs\SendAppointmentConfirmation;
use App\Jobs\GenerateAppointmentPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Configuration Stripe
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Traiter un paiement
     */
    public function processPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appointment_id' => 'required|exists:appointments,id',
            'payment_method' => 'required|in:stripe,cinetpay',
            'amount' => 'required|numeric|min:0',
            'currency' => 'string|size:3',
            'payment_method_id' => 'required_if:payment_method,stripe|string',
            'return_url' => 'url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $appointment = Appointment::findOrFail($request->appointment_id);

            // Vérifier que c'est bien le patient du RDV
            if ($appointment->patient_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            // Vérifier que le paiement n'a pas déjà été effectué
            if ($appointment->payment_status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce rendez-vous a déjà été payé'
                ], 400);
            }

            \DB::beginTransaction();

            // Créer l'enregistrement de paiement
            $payment = Payment::create([
                'appointment_id' => $appointment->id,
                'user_id' => $request->user()->id,
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'XOF',
                'payment_method' => $request->payment_method,
                'status' => 'pending',
            ]);

            // Traitement selon le mode de paiement
            if ($request->payment_method === 'stripe') {
                $result = $this->processStripePayment($payment, $request);
            } elseif ($request->payment_method === 'cinetpay') {
                $result = $this->processCinetPayPayment($payment, $request);
            }

            if ($result['success']) {
                \DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement initié avec succès',
                    'data' => [
                        'payment' => $payment->fresh(),
                        'payment_details' => $result['data']
                    ]
                ]);
            } else {
                \DB::rollback();

                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

        } catch (\Exception $e) {
            \DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du paiement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Traitement Stripe
     */
    private function processStripePayment($payment, $request)
    {
        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $payment->amount * 100, // Montant en centimes
                'currency' => strtolower($payment->currency),
                'payment_method' => $request->payment_method_id,
                'confirmation_method' => 'manual',
                'confirm' => true,
                'return_url' => $request->return_url ?? config('app.frontend_url') . '/payment/success',
                'metadata' => [
                    'appointment_id' => $payment->appointment_id,
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id
                ]
            ]);

            // Mettre à jour le paiement avec les informations Stripe
            $payment->update([
                'gateway_transaction_id' => $paymentIntent->id,
                'gateway_response' => $paymentIntent->toArray(),
                'status' => $this->mapStripeStatus($paymentIntent->status)
            ]);

            // Si paiement réussi immédiatement
            if ($paymentIntent->status === 'succeeded') {
                $this->handleSuccessfulPayment($payment);
            }

            return [
                'success' => true,
                'data' => [
                    'client_secret' => $paymentIntent->client_secret,
                    'status' => $paymentIntent->status,
                    'next_action' => $paymentIntent->next_action
                ]
            ];

        } catch (\Exception $e) {
            $payment->markAsFailed($e->getMessage());

            return [
                'success' => false,
                'message' => 'Erreur Stripe: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Traitement CinetPay
     */
    private function processCinetPayPayment($payment, $request)
    {
        try {
            $data = [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'transaction_id' => $payment->transaction_id,
                'description' => 'Paiement consultation médicale',
                'return_url' => $request->return_url ?? config('app.frontend_url') . '/payment/success',
                'notify_url' => route('api.webhooks.cinetpay'),
                'customer_name' => $payment->user->name,
                'customer_email' => $payment->user->email,
            ];

            // Appel API CinetPay
            $response = \Http::post('https://api-checkout.cinetpay.com/v2/payment', array_merge($data, [
                'apikey' => config('services.cinetpay.api_key'),
                'site_id' => config('services.cinetpay.site_id'),
            ]));

            if ($response->successful()) {
                $responseData = $response->json();

                $payment->update([
                    'gateway_transaction_id' => $responseData['data']['transaction_id'],
                    'gateway_response' => $responseData,
                    'status' => 'processing'
                ]);

                return [
                    'success' => true,
                    'data' => [
                        'payment_url' => $responseData['data']['payment_url'],
                        'transaction_id' => $responseData['data']['transaction_id']
                    ]
                ];
            } else {
                throw new \Exception('Erreur API CinetPay');
            }

        } catch (\Exception $e) {
            $payment->markAsFailed($e->getMessage());

            return [
                'success' => false,
                'message' => 'Erreur CinetPay: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Webhook Stripe
     */
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = config('services.stripe.webhook_secret');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);

            switch ($event['type']) {
                case 'payment_intent.succeeded':
                    $this->handleStripeSuccess($event['data']['object']);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handleStripeFailure($event['data']['object']);
                    break;
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Webhook CinetPay
     */
    public function cinetpayWebhook(Request $request)
    {
        try {
            $data = $request->all();

            // Vérifier la signature
            $expectedSignature = hash_hmac('sha256', $request->getContent(), config('services.cinetpay.secret_key'));
            if (!hash_equals($expectedSignature, $request->header('X-CinetPay-Signature'))) {
                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $payment = Payment::where('gateway_transaction_id', $data['cpm_trans_id'])->first();

            if (!$payment) {
                return response()->json(['error' => 'Payment not found'], 404);
            }

            if ($data['cpm_result'] == '00') {
                $this->handleSuccessfulPayment($payment);
            } else {
                $payment->markAsFailed($data['cpm_error_message'] ?? 'Paiement échoué');
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Gérer un paiement réussi
     */
    private function handleSuccessfulPayment($payment)
    {
        $payment->markAsCompleted();

        // Mettre à jour le rendez-vous
        $appointment = $payment->appointment;
        $appointment->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'confirmed_at' => now()
        ]);

        // Marquer le créneau comme réservé
        $appointment->timeSlot?->update(['status' => 'booked']);

        // Envoyer la confirmation et générer le PDF
        SendAppointmentConfirmation::dispatch($appointment);
        GenerateAppointmentPdf::dispatch($appointment);
    }

    /**
     * Gérer un échec Stripe
     */
    private function handleStripeSuccess($paymentIntent)
    {
        $payment = Payment::where('gateway_transaction_id', $paymentIntent['id'])->first();
        if ($payment) {
            $this->handleSuccessfulPayment($payment);
        }
    }

    private function handleStripeFailure($paymentIntent)
    {
        $payment = Payment::where('gateway_transaction_id', $paymentIntent['id'])->first();
        if ($payment) {
            $payment->markAsFailed($paymentIntent['last_payment_error']['message'] ?? 'Paiement échoué');
        }
    }

    /**
     * Mapper les statuts Stripe
     */
    private function mapStripeStatus($stripeStatus)
    {
        $mapping = [
            'requires_payment_method' => 'pending',
            'requires_confirmation' => 'pending',
            'requires_action' => 'processing',
            'processing' => 'processing',
            'requires_capture' => 'processing',
            'canceled' => 'cancelled',
            'succeeded' => 'completed'
        ];

        return $mapping[$stripeStatus] ?? 'pending';
    }

    /**
     * Liste des paiements du patient
     */
    public function patientPayments(Request $request)
    {
        try {
            $payments = Payment::with(['appointment.doctor.user', 'appointment.doctor.specialty'])
                ->where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'success' => true,
                'data' => $payments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des paiements'
            ], 500);
        }
    }

    /**
     * Détails d'un paiement
     */
    public function show($id)
    {
        try {
            $payment = Payment::with(['appointment.doctor.user', 'appointment.patient', 'user'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement non trouvé'
            ], 404);
        }
    }

    /**
     * Demande de remboursement
     */
    public function requestRefund(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $payment = Payment::findOrFail($id);

            if ($payment->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if ($payment->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce paiement ne peut pas être remboursé'
                ], 400);
            }

            // Créer une demande de remboursement (vous pouvez créer une table refund_requests)
            $payment->update([
                'metadata' => array_merge($payment->metadata ?? [], [
                    'refund_requested' => true,
                    'refund_reason' => $request->reason,
                    'refund_requested_at' => now()
                ])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Demande de remboursement soumise avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la demande'
            ], 500);
        }
    }

    /**
     * Revenus du médecin
     */
    public function doctorRevenue(Request $request)
    {
        try {
            $doctor = $request->user()->doctor;

            $query = Payment::whereHas('appointment', function($q) use ($doctor) {
                $q->where('doctor_id', $doctor->id);
            })->where('status', 'completed');

            // Filtres par date
            if ($request->has('from_date')) {
                $query->whereDate('paid_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->whereDate('paid_at', '<=', $request->to_date);
            }

            $payments = $query->with(['appointment.patient'])
                ->orderBy('paid_at', 'desc')
                ->paginate(15);

            $totalRevenue = $query->sum('amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'payments' => $payments,
                    'total_revenue' => $totalRevenue
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des revenus'
            ], 500);
        }
    }

    /**
     * Revenus mensuels
     */
    public function monthlyRevenue(Request $request)
    {
        try {
            $doctor = $request->user()->doctor;

            $monthlyData = Payment::whereHas('appointment', function($q) use ($doctor) {
                $q->where('doctor_id', $doctor->id);
            })
                ->where('status', 'completed')
                ->whereYear('paid_at', now()->year)
                ->selectRaw('MONTH(paid_at) as month, SUM(amount) as revenue, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $monthlyData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des données'
            ], 500);
        }
    }
}
