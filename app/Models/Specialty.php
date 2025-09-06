<?php
// app/Models/Specialty.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Specialty extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'icon',
        'consultation_fee',
        'is_active',
    ];

    protected $casts = [
        'consultation_fee' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Relations
    public function doctors()
    {
        return $this->hasMany(Doctor::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Accessors
    public function getIconUrlAttribute()
    {
        return $this->icon
            ? asset('storage/specialties/' . $this->icon)
            : asset('images/default-specialty.png');
    }

    public function getDoctorsCountAttribute()
    {
        return $this->doctors()->count();
    }
}
