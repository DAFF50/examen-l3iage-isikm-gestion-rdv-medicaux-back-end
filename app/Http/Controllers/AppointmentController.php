<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\TimeSlot;
use App\Notifications\AppointmentCancelled;
use App\Notifications\AppointmentRescheduled;
use App\Notifications\NewAppointmentNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AppointmentController extends Controller
{
    /**
     * Créer un rendez-vous
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:users,id',
            'time_slot_id' => 'required|exists:time_slots,id',
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $slot = TimeSlot::findOrFail($request->time_slot_id);

            if (!$slot->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce créneau n’est pas disponible'
                ], 400);
            }

            $appointment = Appointment::create([
                'patient_id' => $request->user()->id,
                'doctor_id' => $request->doctor_id,
                'time_slot_id' => $slot->id,
                'appointment_date' => $slot->date,
                'appointment_time' => $slot->start_time,
                'status' => 'pending',
                'reason' => $request->reason,
                'amount' => config('appointments.consultation_fee')
            ]);

            // Créer un paiement lié
            Payment::create([
                'appointment_id' => $appointment->id,
                'amount' => $appointment->amount,
                'status' => 'pending'
            ]);

            // Notifications
            $appointment->doctor->notify(new NewAppointmentNotification($appointment));

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous créé avec succès',
                'data' => $appointment
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du rendez-vous',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reprogrammer un rendez-vous
     */
    public function reschedule(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'new_time_slot_id' => 'required|exists:time_slots,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $appointment = Appointment::findOrFail($id);

            if ($appointment->patient_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            $newSlot = TimeSlot::findOrFail($request->new_time_slot_id);

            if (!$newSlot->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le nouveau créneau n’est pas disponible'
                ], 400);
            }

            $oldData = [
                'date' => $appointment->appointment_date,
                'time' => $appointment->appointment_time
            ];

            $appointment->update([
                'time_slot_id' => $newSlot->id,
                'appointment_date' => $newSlot->date,
                'appointment_time' => $newSlot->start_time,
                'status' => 'rescheduled'
            ]);

            // Notifications
            $appointment->doctor->notify(new AppointmentRescheduled($appointment, $oldData));

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous reprogrammé avec succès',
                'data' => $appointment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la reprogrammation du rendez-vous',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Annuler un rendez-vous
     */
    public function cancel(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $appointment = Appointment::findOrFail($id);

            if ($appointment->patient_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if (!$appointment->canBeCancelled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce rendez-vous ne peut plus être annulé'
                ], 400);
            }

            $appointment->cancel($request->reason);

            // Politique de remboursement
            if ($appointment->payment_status === 'paid') {
                $hoursBefore = now()->diffInHours(Carbon::parse($appointment->appointment_date . ' ' . $appointment->appointment_time));

                if ($hoursBefore >= 24) {
                    $appointment->payment?->refund();
                } else {
                    $refundAmount = $appointment->amount * 0.8;
                    $appointment->payment?->refund($refundAmount, 'Annulation tardive - frais appliqués');
                }
            }

            // Notification
            $appointment->doctor->notify(new AppointmentCancelled($appointment, $request->reason));

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous annulé avec succès',
                'data' => $appointment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’annulation du rendez-vous',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

