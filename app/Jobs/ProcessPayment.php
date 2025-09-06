<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payment;
    public $tries = 3;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    public function handle()
    {
        try {
            $this->payment->refresh();

            if (in_array($this->payment->status, ['completed', 'failed', 'cancelled'])) {
                return;
            }

            switch ($this->payment->payment_method) {
                case 'stripe':
                    $this->processStripePayment();
                    break;
                case 'cinetpay':
                    $this->processCinetPayPayment();
                    break;
                default:
                    throw new \Exception('Méthode de paiement non supportée');
            }

        } catch (\Exception $e) {
            \Log::error('Erreur traitement paiement: ' . $e->getMessage());
            $this->payment->markAsFailed($e->getMessage());
        }
    }

    private function processStripePayment()
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
        $paymentIntent = \Stripe\PaymentIntent::retrieve($this->payment->gateway_transaction_id);

        if ($paymentIntent->status === 'succeeded') {
            $this->handleSuccessfulPayment();
        } elseif ($paymentIntent->status === 'requires_action') {
            $this->payment->update(['status' => 'processing']);
        } else {
            $this->payment->markAsFailed('Paiement Stripe échoué');
        }
    }

    private function processCinetPayPayment()
    {
        $response = \Http::post('https://api-checkout.cinetpay.com/v2/payment/check', [
            'apikey' => config('services.cinetpay.api_key'),
            'site_id' => config('services.cinetpay.site_id'),
            'transaction_id' => $this->payment->transaction_id
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if ($data['code'] == '00' && $data['message'] == 'SUCCES') {
                $this->handleSuccessfulPayment();
            } else {
                $this->payment->markAsFailed($data['description'] ?? 'Paiement CinetPay échoué');
            }
        } else {
            throw new \Exception('Erreur vérification CinetPay');
        }
    }

    private function handleSuccessfulPayment()
    {
        $this->payment->markAsCompleted();

        $appointment = $this->payment->appointment;
        $appointment->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'confirmed_at' => now()
        ]);

        $appointment->timeSlot?->update(['status' => 'booked']);

        SendAppointmentConfirmation::dispatch($appointment);
        GenerateAppointmentPdf::dispatch($appointment);
    }
}
