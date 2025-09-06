<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'appointment_id',
        'user_id',
        'amount',
        'currency',
        'payment_method',
        'status',
        'gateway_transaction_id',
        'gateway_response',
        'metadata',
        'paid_at',
        'failure_reason',
        'refund_amount',
        'refunded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            $payment->transaction_id = 'TXN-' . strtoupper(Str::random(10));
        });
    }

    // Relations
    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // Helper Methods
    public function markAsCompleted($gatewayResponse = [])
    {
        $this->update([
            'status' => 'completed',
            'paid_at' => now(),
            'gateway_response' => $gatewayResponse
        ]);
    }

    public function markAsFailed($reason = null, $gatewayResponse = [])
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'gateway_response' => $gatewayResponse
        ]);
    }

    public function refund($amount = null, $reason = null)
    {
        $refundAmount = $amount ?? $this->amount;

        $this->update([
            'status' => 'refunded',
            'refund_amount' => $refundAmount,
            'refunded_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], [
                'refund_reason' => $reason
            ])
        ]);
    }
}
