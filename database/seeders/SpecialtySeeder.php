<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Specialty;

class SpecialtySeeder extends Seeder
{
    public function run()
    {
        $specialties = [
            ['name' => 'Médecine Générale', 'description' => 'Consultation générale, suivi médical, prévention', 'consultation_fee' => 25000, 'icon' => 'general-medicine.svg'],
            ['name' => 'Cardiologie', 'description' => 'Spécialiste du cœur et des vaisseaux sanguins', 'consultation_fee' => 50000, 'icon' => 'cardiology.svg'],
            ['name' => 'Dermatologie', 'description' => 'Spécialiste des maladies de la peau', 'consultation_fee' => 40000, 'icon' => 'dermatology.svg'],
            ['name' => 'Pédiatrie', 'description' => 'Médecine des enfants et des adolescents', 'consultation_fee' => 35000, 'icon' => 'pediatrics.svg'],
            ['name' => 'Gynécologie', 'description' => 'Santé féminine, grossesse, contraception', 'consultation_fee' => 45000, 'icon' => 'gynecology.svg'],
            ['name' => 'Ophtalmologie', 'description' => 'Spécialiste des yeux et de la vision', 'consultation_fee' => 40000, 'icon' => 'ophthalmology.svg'],
            ['name' => 'ORL', 'description' => 'Oreille, nez, gorge et chirurgie cervico-faciale', 'consultation_fee' => 40000, 'icon' => 'orl.svg'],
            ['name' => 'Orthopédie', 'description' => 'Spécialiste des os, articulations et muscles', 'consultation_fee' => 50000, 'icon' => 'orthopedics.svg'],
            ['name' => 'Psychiatrie', 'description' => 'Santé mentale et troubles psychiatriques', 'consultation_fee' => 60000, 'icon' => 'psychiatry.svg'],
            ['name' => 'Dentisterie', 'description' => 'Soins dentaires, orthodontie, chirurgie dentaire', 'consultation_fee' => 30000, 'icon' => 'dentistry.svg']
        ];

        foreach ($specialties as $specialty) {
            Specialty::create($specialty);
        }
    }
}
