<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\AiConversation;
use Illuminate\Console\Command;

class CleanupOldData extends Command
{
    protected $signature = 'cleanup:old-data {--days=30}';
    protected $description = 'Nettoie les anciennes données (notifications, conversations IA)';

    public function handle()
    {
        $days = $this->option('days');

        $deletedNotifications = Notification::where('created_at', '<', now()->subDays($days))
            ->where('is_read', true)
            ->delete();

        $deletedConversations = AiConversation::where('created_at', '<', now()->subDays($days))
            ->whereNull('user_id')
            ->delete();

        $this->info("Nettoyage terminé:");
        $this->info("- {$deletedNotifications} notifications supprimées");
        $this->info("- {$deletedConversations} conversations IA supprimées");
    }
}
