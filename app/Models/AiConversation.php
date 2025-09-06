<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'user_message',
        'ai_response',
        'type',
        'confidence_score',
        'context',
        'is_helpful',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'context' => 'array',
        'is_helpful' => 'boolean',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeBySession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    // Helper Methods
    public function markAsHelpful($helpful = true)
    {
        $this->update(['is_helpful' => $helpful]);
    }

    public static function createConversation($userId, $sessionId, $userMessage, $aiResponse, $type = 'general', $context = [])
    {
        return static::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'user_message' => $userMessage,
            'ai_response' => $aiResponse,
            'type' => $type,
            'context' => $context,
        ]);
    }
}
