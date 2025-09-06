<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run()
    {
        // Créer les rôles
        $adminRole = Role::create(['name' => 'admin']);
        $doctorRole = Role::create(['name' => 'doctor']);
        $patientRole = Role::create(['name' => 'patient']);

        // Créer les permissions
        $permissions = [
            'view_dashboard', 'manage_profile',
            'create_appointment', 'view_own_appointments', 'cancel_own_appointment', 'make_payment',
            'view_doctor_appointments', 'confirm_appointment', 'reject_appointment', 'manage_time_slots',
            'view_patient_info', 'add_prescription', 'mark_appointment_complete',
            'manage_users', 'manage_doctors', 'manage_specialties', 'view_all_appointments',
            'view_statistics', 'manage_payments', 'send_notifications',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Assigner les permissions aux rôles
        $patientRole->givePermissionTo([
            'view_dashboard', 'manage_profile', 'create_appointment', 'view_own_appointments',
            'cancel_own_appointment', 'make_payment'
        ]);

        $doctorRole->givePermissionTo([
            'view_dashboard', 'manage_profile', 'view_doctor_appointments', 'confirm_appointment',
            'reject_appointment', 'manage_time_slots', 'view_patient_info', 'add_prescription',
            'mark_appointment_complete'
        ]);

        $adminRole->givePermissionTo(Permission::all());
    }
}
