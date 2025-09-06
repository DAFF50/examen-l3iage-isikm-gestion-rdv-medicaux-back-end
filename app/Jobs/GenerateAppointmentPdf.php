<?php

namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class GenerateAppointmentPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function handle()
    {
        try {
            if ($this->appointment->pdf_path && Storage::exists('public/' . $this->appointment->pdf_path)) {
                return;
            }

            $this->appointment->load([
                'patient:id,name,phone,email,address',
                'doctor.user:id,name,email,phone',
                'doctor.specialty:id,name',
                'payment'
            ]);

            $qrCodeData = route('appointment.verify', ['number' => $this->appointment->appointment_number]);
            $qrCode = \SimpleSoftwareIO\QrCode\Facades\QrCode::size(80)->generate($qrCodeData);

            $data = [
                'appointment' => $this->appointment,
                'qrCode' => base64_encode($qrCode),
                'generatedAt' => now()->format('d/m/Y à H:i'),
            ];

            $html = view('pdf.appointment-certificate', $data)->render();

            $pdfContent = Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->output();

            $filename = 'appointment_' . $this->appointment->appointment_number . '.pdf';
            $path = 'pdfs/appointments/' . $filename;

            Storage::put('public/' . $path, $pdfContent);

            $this->appointment->update(['pdf_path' => $path]);

            \Log::info('PDF généré avec succès pour le RDV: ' . $this->appointment->appointment_number);

        } catch (\Exception $e) {
            \Log::error('Erreur génération PDF RDV: ' . $e->getMessage());
        }
    }
}
