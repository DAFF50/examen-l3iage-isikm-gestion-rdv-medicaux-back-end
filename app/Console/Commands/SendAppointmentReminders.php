<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Jobs\SendAppointmentReminder;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Envoie des rappels pour les rendez-vous de demain';

    public function handle()
    {
        $tomorrow = now()->addDay();

        $appointments = Appointment::with(['patient', 'doctor.user'])
            ->whereDate('appointment_date', $tomorrow->format('Y-m-d'))
            ->where('status', 'confirmed')
            ->where('reminder_sent', false)
            ->get();

        $this->info("Traitement de {$appointments->count()} rendez-vous pour demain...");

        foreach ($appointments as $appointment) {
            SendAppointmentReminder::dispatch($appointment);
            $this->info("Rappel programmé pour {$appointment->patient->name} - RDV {$appointment->appointment_number}");
        }

        $this->info('Tous les rappels ont été programmés.');
    }
}
