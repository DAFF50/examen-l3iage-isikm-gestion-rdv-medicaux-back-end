<?php
// app/Http/Controllers/PdfController.php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PdfController extends Controller
{
    /**
     * Télécharger le justificatif de rendez-vous
     */
    public function downloadAppointmentPdf(Request $request, $id)
    {
        try {
            $appointment = Appointment::with([
                'patient:id,name,phone,email,address',
                'doctor.user:id,name,email,phone',
                'doctor.specialty:id,name',
                'doctor',
                'payment'
            ])->findOrFail($id);

            // Vérifier les permissions
            $user = $request->user();
            if ($user->user_type === 'patient' && $appointment->patient_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if ($user->user_type === 'doctor' && $appointment->doctor->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            // Si le PDF existe déjà, le retourner
            if ($appointment->pdf_path && Storage::exists('public/' . $appointment->pdf_path)) {
                return response()->download(storage_path('app/public/' . $appointment->pdf_path));
            }

            // Générer le PDF
            $pdfContent = $this->generateAppointmentPdf($appointment);

            // Sauvegarder le PDF
            $filename = 'appointment_' . $appointment->appointment_number . '.pdf';
            $path = 'pdfs/appointments/' . $filename;
            Storage::put('public/' . $path, $pdfContent);

            // Mettre à jour l'appointment
            $appointment->update(['pdf_path' => $path]);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Télécharger le reçu de paiement
     */
    public function downloadPaymentReceipt(Request $request, $id)
    {
        try {
            $payment = Payment::with([
                'appointment.patient:id,name,phone,email',
                'appointment.doctor.user:id,name',
                'appointment.doctor.specialty:id,name',
                'user:id,name,email'
            ])->findOrFail($id);

            // Vérifier les permissions
            if ($payment->user_id !== $request->user()->id && $request->user()->user_type !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if ($payment->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Le paiement n\'est pas encore confirmé'
                ], 400);
            }

            $pdfContent = $this->generatePaymentReceiptPdf($payment);
            $filename = 'receipt_' . $payment->transaction_id . '.pdf';

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du reçu'
            ], 500);
        }
    }

    /**
     * Générer le PDF de justificatif de rendez-vous
     */
    private function generateAppointmentPdf($appointment)
    {
        // Générer le QR Code
        $qrCodeData = route('appointment.verify', ['number' => $appointment->appointment_number]);
        $qrCode = QrCode::size(80)->generate($qrCodeData);

        $data = [
            'appointment' => $appointment,
            'qrCode' => base64_encode($qrCode),
            'generatedAt' => now()->format('d/m/Y à H:i'),
        ];

        $html = view('pdf.appointment-certificate', $data)->render();

        return Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setOptions([
                'defaultFont' => 'Arial',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled' => true
            ])
            ->output();
    }

    /**
     * Générer le PDF de reçu de paiement
     */
    private function generatePaymentReceiptPdf($payment)
    {
        // Générer le QR Code pour vérification
        $qrCodeData = route('payment.verify', ['transaction' => $payment->transaction_id]);
        $qrCode = QrCode::size(80)->generate($qrCodeData);

        $data = [
            'payment' => $payment,
            'qrCode' => base64_encode($qrCode),
            'generatedAt' => now()->format('d/m/Y à H:i'),
        ];

        $html = view('pdf.payment-receipt', $data)->render();

        return Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setOptions([
                'defaultFont' => 'Arial',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled' => true
            ])
            ->output();
    }

    /**
     * Générer un PDF de prescription (médecin)
     */
    public function generatePrescriptionPdf(Request $request, $appointmentId)
    {
        try {
            $appointment = Appointment::with([
                'patient:id,name,phone,email,address,date_of_birth',
                'doctor.user:id,name',
                'doctor.specialty:id,name'
            ])->findOrFail($appointmentId);

            // Vérifier que c'est le médecin du rendez-vous
            if ($appointment->doctor->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if (!$appointment->prescription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune prescription disponible'
                ], 400);
            }

            $data = [
                'appointment' => $appointment,
                'doctor' => $appointment->doctor,
                'patient' => $appointment->patient,
                'prescription' => $appointment->prescription,
                'generatedAt' => now()->format('d/m/Y à H:i'),
            ];

            $html = view('pdf.prescription', $data)->render();

            $pdfContent = Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->output();

            $filename = 'prescription_' . $appointment->appointment_number . '.pdf';

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de la prescription'
            ], 500);
        }
    }

    /**
     * Générer un rapport mensuel (médecin)
     */
    public function generateMonthlyReport(Request $request)
    {
        try {
            $doctor = $request->user()->doctor;
            $month = $request->get('month', now()->month);
            $year = $request->get('year', now()->year);

            $appointments = $doctor->appointments()
                ->with(['patient:id,name,phone', 'payment'])
                ->whereMonth('appointment_date', $month)
                ->whereYear('appointment_date', $year)
                ->where('status', '!=', 'cancelled')
                ->orderBy('appointment_date')
                ->get();

            $stats = [
                'total_appointments' => $appointments->count(),
                'completed_appointments' => $appointments->where('status', 'completed')->count(),
                'total_revenue' => $appointments->where('payment_status', 'paid')->sum('amount'),
                'no_shows' => $appointments->where('status', 'no_show')->count(),
            ];

            $data = [
                'doctor' => $doctor,
                'appointments' => $appointments,
                'stats' => $stats,
                'month' => $month,
                'year' => $year,
                'monthName' => now()->month($month)->format('F'),
                'generatedAt' => now()->format('d/m/Y à H:i'),
            ];

            $html = view('pdf.monthly-report', $data)->render();

            $pdfContent = Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->output();

            $filename = 'rapport_' . $doctor->user->name . '_' . $month . '_' . $year . '.pdf';

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du rapport'
            ], 500);
        }
    }

    /**
     * Test de génération PDF
     */
    public function testPdfGeneration($appointmentId)
    {
        try {
            $appointment = Appointment::with([
                'patient:id,name,phone,email,address',
                'doctor.user:id,name,email,phone',
                'doctor.specialty:id,name',
                'doctor',
                'payment'
            ])->findOrFail($appointmentId);

            $pdfContent = $this->generateAppointmentPdf($appointment);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="test.pdf"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Générer un certificat médical
     */
    public function generateMedicalCertificate(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'appointment_id' => 'required|exists:appointments,id',
            'certificate_text' => 'required|string|max:1000',
            'rest_days' => 'nullable|integer|min:1|max:365'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $appointment = Appointment::with([
                'patient:id,name,phone,email,address,date_of_birth',
                'doctor.user:id,name',
                'doctor.specialty:id,name',
                'doctor'
            ])->findOrFail($request->appointment_id);

            // Vérifier que c'est le médecin du rendez-vous
            if ($appointment->doctor->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            if ($appointment->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Le rendez-vous doit être terminé pour émettre un certificat'
                ], 400);
            }

            $data = [
                'appointment' => $appointment,
                'doctor' => $appointment->doctor,
                'patient' => $appointment->patient,
                'certificateText' => $request->certificate_text,
                'restDays' => $request->rest_days,
                'generatedAt' => now()->format('d/m/Y à H:i'),
                'certificateNumber' => 'CERT-' . strtoupper(\Str::random(8)),
            ];

            $html = view('pdf.medical-certificate', $data)->render();

            $pdfContent = Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->output();

            $filename = 'certificat_medical_' . $appointment->appointment_number . '.pdf';

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du certificat'
            ], 500);
        }
    }

    /**
     * Vérifier un document via QR Code
     */
    public function verifyDocument(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'type' => 'required|in:appointment,payment',
            'identifier' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if ($request->type === 'appointment') {
                $appointment = Appointment::where('appointment_number', $request->identifier)
                    ->with(['patient:id,name', 'doctor.user:id,name', 'doctor.specialty:id,name'])
                    ->first();

                if (!$appointment) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Document non trouvé'
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'valid' => true,
                    'data' => [
                        'type' => 'Justificatif de rendez-vous',
                        'appointment_number' => $appointment->appointment_number,
                        'patient' => $appointment->patient->name,
                        'doctor' => $appointment->doctor->user->name,
                        'specialty' => $appointment->doctor->specialty->name,
                        'date' => $appointment->appointment_date->format('d/m/Y à H:i'),
                        'status' => $appointment->status_text
                    ]
                ]);

            } elseif ($request->type === 'payment') {
                $payment = Payment::where('transaction_id', $request->identifier)
                    ->with(['appointment.patient:id,name', 'appointment.doctor.user:id,name'])
                    ->first();

                if (!$payment) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Document non trouvé'
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'valid' => true,
                    'data' => [
                        'type' => 'Reçu de paiement',
                        'transaction_id' => $payment->transaction_id,
                        'patient' => $payment->appointment->patient->name,
                        'doctor' => $payment->appointment->doctor->user->name,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'date' => $payment->paid_at->format('d/m/Y à H:i'),
                        'status' => 'Payé'
                    ]
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification'
            ], 500);
        }
    }
}
