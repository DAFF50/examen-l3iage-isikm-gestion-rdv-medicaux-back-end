<?php
// app/Models/Appointment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_number',
        'patient_id',
        'doctor_id',
        'time_slot_id',
        'appointment_date',
        'appointment_time',
        'status',
        'payment_method',
        'payment_status',
        'amount',
        'reason',
        'notes',
        'prescription',
        'pdf_path',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
        'reminder_sent',
    ];

    protected $casts = [
        'appointment_date' => 'datetime',
        'appointment_time' => 'datetime:H:i',
        'amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reminder_sent' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($appointment) {
            $appointment->appointment_number = 'APT-' . strtoupper(Str::random(8));
        });
    }

    // Relations
    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function timeSlot()
    {
        return $this->belongsTo(TimeSlot::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('appointment_date', today());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('appointment_date', '>=', now());
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeByDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    public function scopeByPatient($query, $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    // Accessors
    public function getFullAppointmentDateAttribute()
    {
        return $this->appointment_date->format('d/m/Y à H:i');
    }

    public function getStatusTextAttribute()
    {
        $statuses = [
            'pending' => 'En attente',
            'confirmed' => 'Confirmé',
            'cancelled' => 'Annulé',
            'completed' => 'Terminé',
            'no_show' => 'Absence'
        ];

        return $statuses[$this->status] ?? $this->status;
    }

    public function getPaymentStatusTextAttribute()
    {
        $statuses = [
            'pending' => 'En attente',
            'paid' => 'Payé',
            'failed' => 'Échec',
            'refunded' => 'Remboursé'
        ];

        return $statuses[$this->payment_status] ?? $this->payment_status;
    }

    public function getPaymentMethodTextAttribute()
    {
        $methods = [
            'online' => 'Paiement en ligne',
            'cash_at_clinic' => 'Espèces au cabinet'
        ];

        return $methods[$this->payment_method] ?? $this->payment_method;
    }

    public function getPdfUrlAttribute()
    {
        return $this->pdf_path ? asset('storage/' . $this->pdf_path) : null;
    }

    // Helper Methods
    public function canBeCancelled()
    {
        return in_array($this->status, ['pending', 'confirmed']) &&
            $this->appointment_date->greaterThan(now()->addHours(24));
    }

    public function canBeRescheduled()
    {
        return in_array($this->status, ['pending', 'confirmed']) &&
            $this->appointment_date->greaterThan(now()->addHours(24));
    }

    public function isUpcoming()
    {
        return $this->appointment_date->greaterThan(now()) &&
            in_array($this->status, ['confirmed']);
    }

    public function isPast()
    {
        return $this->appointment_date->lessThan(now());
    }

    public function needsReminder()
    {
        return !$this->reminder_sent &&
            $this->status === 'confirmed' &&
            $this->appointment_date->greaterThan(now()) &&
            $this->appointment_date->lessThanOrEqualTo(now()->addDay());
    }

    public function confirm()
    {
        $this->update([
            'status' => 'confirmed',
            'confirmed_at' => now()
        ]);

        // Marquer le créneau comme réservé
        $this->timeSlot?->update(['status' => 'booked']);
    }

    public function cancel($reason = null)
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason
        ]);

        // Libérer le créneau
        $this->timeSlot?->update(['status' => 'available']);
    }

    public function complete()
    {
        $this->update(['status' => 'completed']);
    }

    public function markAsNoShow()
    {
        $this->update(['status' => 'no_show']);
    }
}
