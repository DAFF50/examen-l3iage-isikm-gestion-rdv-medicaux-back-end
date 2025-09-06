<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendAppointmentConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function handle()
    {
        try {
            // Notification patient
            Notification::createForUser(
                $this->appointment->patient_id,
                'appointment_confirmed',
                'Rendez-vous confirmé',
                "Votre rendez-vous avec Dr. {$this->appointment->doctor->user->name} le {$this->appointment->appointment_date->format('d/m/Y à H:i')} a été confirmé.",
                [
                    'appointment_id' => $this->appointment->id,
                    'appointment_number' => $this->appointment->appointment_number,
                    'doctor_name' => $this->appointment->doctor->user->name,
                    'date' => $this->appointment->appointment_date->format('d/m/Y à H:i')
                ],
                'database'
            );

            // Notification médecin
            Notification::createForUser(
                $this->appointment->doctor->user_id,
                'appointment_confirmed',
                'Nouveau rendez-vous confirmé',
                "Rendez-vous confirmé avec {$this->appointment->patient->name} le {$this->appointment->appointment_date->format('d/m/Y à H:i')}.",
                [
                    'appointment_id' => $this->appointment->id,
                    'patient_name' => $this->appointment->patient->name,
                    'date' => $this->appointment->appointment_date->format('d/m/Y à H:i')
                ],
                'database'
            );

            // Email patient
            if ($this->appointment->patient->email) {
                Mail::send('emails.appointment-confirmed', [
                    'appointment' => $this->appointment,
                    'patient' => $this->appointment->patient,
                    'doctor' => $this->appointment->doctor
                ], function ($message) {
                    $message->to($this->appointment->patient->email, $this->appointment->patient->name)
                        ->subject('Confirmation de votre rendez-vous médical')
                        ->from(config('mail.from.address'), config('mail.from.name'));
                });
            }

        } catch (\Exception $e) {
            \Log::error('Erreur envoi confirmation RDV: ' . $e->getMessage());
        }
    }
}
