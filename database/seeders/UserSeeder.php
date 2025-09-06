<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Doctor;
use App\Models\Specialty;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Créer l'admin
        $admin = User::create([
            'name' => 'Admin System',
            'email' => 'admin@medical-platform.com',
            'password' => Hash::make('password123'),
            'user_type' => 'admin',
            'phone' => '+221 77 123 45 67',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        // Créer des patients de test
        $patients = [
            ['name' => 'Amadou Diallo', 'email' => 'amadou@example.com', 'phone' => '+221 77 234 56 78', 'address' => 'Dakar, Sénégal', 'date_of_birth' => '1990-05-15', 'gender' => 'male'],
            ['name' => 'Fatou Sall', 'email' => 'fatou@example.com', 'phone' => '+221 76 345 67 89', 'address' => 'Pikine, Sénégal', 'date_of_birth' => '1985-08-22', 'gender' => 'female'],
            ['name' => 'Ousmane Ba', 'email' => 'ousmane@example.com', 'phone' => '+221 78 456 78 90', 'address' => 'Rufisque, Sénégal', 'date_of_birth' => '1992-12-10', 'gender' => 'male']
        ];

        foreach ($patients as $patientData) {
            $patient = User::create([
                'name' => $patientData['name'],
                'email' => $patientData['email'],
                'password' => Hash::make('password123'),
                'user_type' => 'patient',
                'phone' => $patientData['phone'],
                'address' => $patientData['address'],
                'date_of_birth' => $patientData['date_of_birth'],
                'gender' => $patientData['gender'],
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $patient->assignRole('patient');
        }

        // Créer des médecins de test
        $doctors = [
            ['name' => 'Dr. Aissatou Kane', 'email' => 'dr.kane@example.com', 'phone' => '+221 77 111 22 33', 'specialty' => 'Médecine Générale', 'license_number' => 'MED001SN', 'experience_years' => 8, 'clinic_name' => 'Cabinet Médical Kane', 'clinic_address' => '15 Avenue Bourguiba, Dakar', 'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'working_start_time' => '08:00', 'working_end_time' => '17:00', 'bio' => 'Médecin généraliste avec 8 ans d\'expérience, spécialisée dans le suivi médical et la prévention.'],
            ['name' => 'Dr. Mamadou Sylla', 'email' => 'dr.sylla@example.com', 'phone' => '+221 76 222 33 44', 'specialty' => 'Cardiologie', 'license_number' => 'CARD002SN', 'experience_years' => 12, 'clinic_name' => 'Centre Cardiologique Sylla', 'clinic_address' => '25 Rue de la République, Dakar', 'working_days' => ['monday', 'tuesday', 'thursday', 'friday'], 'working_start_time' => '09:00', 'working_end_time' => '16:00', 'bio' => 'Cardiologue expérimenté, spécialiste des maladies cardiovasculaires et de l\'hypertension.'],
            ['name' => 'Dr. Marieme Ndiaye', 'email' => 'dr.ndiaye@example.com', 'phone' => '+221 78 333 44 55', 'specialty' => 'Pédiatrie', 'license_number' => 'PED003SN', 'experience_years' => 6, 'clinic_name' => 'Cabinet Pédiatrique Ndiaye', 'clinic_address' => '10 Boulevard du Centenaire, Dakar', 'working_days' => ['monday', 'tuesday', 'wednesday', 'friday', 'saturday'], 'working_start_time' => '08:30', 'working_end_time' => '17:30', 'bio' => 'Pédiatre dédiée à la santé des enfants, vaccination et suivi de croissance.'],
            ['name' => 'Dr. Ibrahima Diop', 'email' => 'dr.diop@example.com', 'phone' => '+221 77 444 55 66', 'specialty' => 'Dermatologie', 'license_number' => 'DERM004SN', 'experience_years' => 10, 'clinic_name' => 'Clinique Dermatologique Diop', 'clinic_address' => '30 Avenue Cheikh Anta Diop, Dakar', 'working_days' => ['tuesday', 'wednesday', 'thursday', 'friday'], 'working_start_time' => '09:00', 'working_end_time' => '16:00', 'bio' => 'Dermatologue spécialisé dans les maladies de la peau, eczéma, acné et soins esthétiques.']
        ];

        foreach ($doctors as $doctorData) {
            $specialty = Specialty::where('name', $doctorData['specialty'])->first();

            $user = User::create([
                'name' => $doctorData['name'],
                'email' => $doctorData['email'],
                'password' => Hash::make('password123'),
                'user_type' => 'doctor',
                'phone' => $doctorData['phone'],
                'bio' => $doctorData['bio'],
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $user->assignRole('doctor');

            Doctor::create([
                'user_id' => $user->id,
                'specialty_id' => $specialty->id,
                'license_number' => $doctorData['license_number'],
                'experience_years' => $doctorData['experience_years'],
                'consultation_fee' => $specialty->consultation_fee,
                'clinic_name' => $doctorData['clinic_name'],
                'clinic_address' => $doctorData['clinic_address'],
                'working_days' => $doctorData['working_days'],
                'working_start_time' => $doctorData['working_start_time'],
                'working_end_time' => $doctorData['working_end_time'],
                'appointment_duration' => 30,
                'is_verified' => true,
                'accepts_online_payment' => true,
                'rating' => rand(40, 50) / 10,
                'total_reviews' => rand(10, 50)
            ]);
        }
    }
}
