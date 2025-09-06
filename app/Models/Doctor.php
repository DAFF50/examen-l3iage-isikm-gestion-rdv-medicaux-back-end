<?php
// app/Models/Doctor.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'specialty_id',
        'license_number',
        'experience_years',
        'consultation_fee',
        'clinic_name',
        'clinic_address',
        'working_days',
        'working_start_time',
        'working_end_time',
        'appointment_duration',
        'qualifications',
        'rating',
        'total_reviews',
        'is_verified',
        'accepts_online_payment',
    ];

    protected $casts = [
        'working_days' => 'array',
        'consultation_fee' => 'decimal:2',
        'working_start_time' => 'datetime:H:i',
        'working_end_time' => 'datetime:H:i',
        'rating' => 'decimal:2',
        'is_verified' => 'boolean',
        'accepts_online_payment' => 'boolean',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function timeSlots()
    {
        return $this->hasMany(TimeSlot::class);
    }

    // Scopes
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeBySpecialty($query, $specialtyId)
    {
        return $query->where('specialty_id', $specialtyId);
    }

    public function scopeAcceptsOnlinePayment($query)
    {
        return $query->where('accepts_online_payment', true);
    }

    public function scopeAvailable($query, $date, $time)
    {
        return $query->whereHas('timeSlots', function($q) use ($date, $time) {
            $q->where('date', $date)
                ->where('start_time', '<=', $time)
                ->where('end_time', '>', $time)
                ->where('status', 'available');
        });
    }

    // Accessors
    public function getFullNameAttribute()
    {
        return $this->user->name;
    }

    public function getProfileImageAttribute()
    {
        return $this->user->profile_image_url;
    }

    public function getWorkingDaysStringAttribute()
    {
        $days = [
            'monday' => 'Lundi',
            'tuesday' => 'Mardi',
            'wednesday' => 'Mercredi',
            'thursday' => 'Jeudi',
            'friday' => 'Vendredi',
            'saturday' => 'Samedi',
            'sunday' => 'Dimanche'
        ];

        return collect($this->working_days)
            ->map(fn($day) => $days[$day] ?? $day)
            ->join(', ');
    }

    // Helper Methods
    public function generateTimeSlots($date)
    {
        $dayOfWeek = strtolower($date->format('l'));

        if (!in_array($dayOfWeek, $this->working_days)) {
            return collect([]);
        }

        $slots = collect([]);
        $start = $date->copy()->setTimeFromTimeString($this->working_start_time);
        $end = $date->copy()->setTimeFromTimeString($this->working_end_time);

        while ($start->lessThan($end)) {
            $slotEnd = $start->copy()->addMinutes($this->appointment_duration);

            if ($slotEnd->lessThanOrEqualTo($end)) {
                $slots->push([
                    'start_time' => $start->format('H:i'),
                    'end_time' => $slotEnd->format('H:i'),
                ]);
            }

            $start = $slotEnd;
        }

        return $slots;
    }

    public function isAvailable($date, $time)
    {
        return $this->timeSlots()
            ->where('date', $date)
            ->where('start_time', '<=', $time)
            ->where('end_time', '>', $time)
            ->where('status', 'available')
            ->exists();
    }

    public function getTodayAppointmentsCount()
    {
        return $this->appointments()
            ->whereDate('appointment_date', today())
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    public function getMonthlyRevenue($month = null, $year = null)
    {
        $month = $month ?? now()->month;
        $year = $year ?? now()->year;

        return $this->appointments()
            ->whereMonth('appointment_date', $month)
            ->whereYear('appointment_date', $year)
            ->where('payment_status', 'paid')
            ->sum('amount');
    }
}
