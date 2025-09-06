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

class SendAppointmentReminder implements ShouldQueue
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
            $this->appointment->refresh();

            if ($this->appointment->status !== 'confirmed') {
                return;
            }

            // Notification
            Notification::createForUser(
                $this->appointment->patient_id,
                'appointment_reminder',
                'Rappel de rendez-vous',
                "N'oubliez pas votre rendez-vous avec Dr. {$this->appointment->doctor->user->name} demain à {$this->appointment->appointment_date->format('H:i')}.",
                [
                    'appointment_id' => $this->appointment->id,
                    'appointment_number' => $this->appointment->appointment_number,
                    'doctor_name' => $this->appointment->doctor->user->name,
                    'date' => $this->appointment->appointment_date->format('d/m/Y à H:i'),
                    'clinic_address' => $this->appointment->doctor->clinic_address
                ]
            );

            // Email rappel
            if ($this->appointment->patient->email) {
                Mail::send('emails.appointment-reminder', [
                    'appointment' => $this->appointment,
                    'patient' => $this->appointment->patient,
                    'doctor' => $this->appointment->doctor
                ], function ($message) {
                    $message->to($this->appointment->patient->email, $this->appointment->patient->name)
                        ->subject('Rappel de votre rendez-vous médical')
                        ->from(config('mail.from.address'), config('mail.from.name'));
                });
            }

            $this->appointment->update(['reminder_sent' => true]);

        } catch (\Exception $e) {
            \Log::error('Erreur envoi rappel RDV: ' . $e->getMessage());
        }
    }
}
