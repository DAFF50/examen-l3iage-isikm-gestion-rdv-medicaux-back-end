<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Planifier les tâches automatiques.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Envoyer les rappels de RDV tous les jours à 20h
        $schedule->command('appointments:send-reminders')
                 ->dailyAt('20:00');

        // Nettoyer les anciennes données toutes les semaines
        $schedule->command('cleanup:old-data --days=30')
                 ->weekly();

        // Générer les créneaux manquants pour les médecins
        $schedule->call(function () {
            $doctors = \App\Models\Doctor::with('timeSlots')->get();
            foreach ($doctors as $doctor) {
                // Générer les créneaux pour les 7 prochains jours si manquants
                if (method_exists($this, 'generateMissingSlotsForDoctor')) {
                    $this->generateMissingSlotsForDoctor($doctor);
                }
            }
        })->daily();
    }

    /**
     * Enregistrer les commandes artisan personnalisées.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
