<!-- resources/views/pdf/appointment-certificate.blade.php -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Justificatif de Rendez-vous</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        .header {
            text-align: center;
            border-bottom: 3px solid #007bff;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #007bff;
            font-size: 24px;
            margin: 0;
        }

        .header h2 {
            color: #666;
            font-size: 16px;
            margin: 5px 0;
        }

        .content {
            margin: 20px 0;
        }

        .info-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
        }

        .info-section h3 {
            margin-top: 0;
            color: #007bff;
            font-size: 16px;
        }

        .info-row {
            display: flex;
            margin: 8px 0;
        }

        .info-label {
            font-weight: bold;
            width: 150px;
            color: #555;
        }

        .info-value {
            flex: 1;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 15px;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .status-confirmed {
            background-color: #28a745;
        }

        .status-pending {
            background-color: #ffc107;
            color: #212529;
        }

        .status-completed {
            background-color: #17a2b8;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 12px;
            color: #666;
        }

        .qr-section {
            float: right;
            text-align: center;
            margin: 20px 0;
        }

        .qr-code {
            border: 2px solid #007bff;
            padding: 10px;
            background: white;
        }

        .amount {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
        }

        .appointment-number {
            font-size: 20px;
            font-weight: bold;
            color: #007bff;
            text-align: center;
            margin: 20px 0;
            padding: 10px;
            background-color: #e3f2fd;
            border-radius: 5px;
        }

        .clearfix {
            clear: both;
        }

        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin: 20px 0;
            font-size: 12px;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>JUSTIFICATIF DE RENDEZ-VOUS MÉDICAL</h1>
    <h2>Plateforme Médicale en Ligne</h2>
</div>

<div class="appointment-number">
    N° {{ $appointment->appointment_number }}
</div>

<div class="qr-section">
    <div class="qr-code">
        <img src="data:image/png;base64,{{ $qrCode }}" alt="QR Code">
    </div>
    <p style="font-size: 10px; margin: 5px 0;">Code de vérification</p>
</div>

<div class="content">
    <div class="info-section">
        <h3>📋 Informations du Rendez-vous</h3>
        <div class="info-row">
            <div class="info-label">Date et Heure :</div>
            <div class="info-value">{{ $appointment->appointment_date->format('d/m/Y à H:i') }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Statut :</div>
            <div class="info-value">
                    <span class="status-badge status-{{ $appointment->status }}">
                        {{ $appointment->status_text }}
                    </span>
            </div>
        </div>
        @if($appointment->reason)
            <div class="info-row">
                <div class="info-label">Motif :</div>
                <div class="info-value">{{ $appointment->reason }}</div>
            </div>
        @endif
    </div>

    <div class="info-section">
        <h3>👤 Informations du Patient</h3>
        <div class="info-row">
            <div class="info-label">Nom :</div>
            <div class="info-value">{{ $appointment->patient->name }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Email :</div>
            <div class="info-value">{{ $appointment->patient->email }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Téléphone :</div>
            <div class="info-value">{{ $appointment->patient->phone }}</div>
        </div>
    </div>

    <div class="info-section">
        <h3>👨‍⚕️ Informations du Médecin</h3>
        <div class="info-row">
            <div class="info-label">Médecin :</div>
            <div class="info-value">Dr. {{ $appointment->doctor->user->name }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Spécialité :</div>
            <div class="info-value">{{ $appointment->doctor->specialty->name }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Cabinet :</div>
            <div class="info-value">{{ $appointment->doctor->clinic_name }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Adresse :</div>
            <div class="info-value">{{ $appointment->doctor->clinic_address }}</div>
        </div>
    </div>

    @if($appointment->payment)
        <div class="info-section">
            <h3>💳 Informations de Paiement</h3>
            <div class="info-row">
                <div class="info-label">Montant :</div>
                <div class="info-value amount">{{ number_format($appointment->amount, 0, ',', ' ') }} {{ $appointment->payment->currency ?? 'XOF' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Mode de paiement :</div>
                <div class="info-value">{{ $appointment->payment_method_text }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Statut paiement :</div>
                <div class="info-value">
                    <span class="status-badge status-{{ $appointment->payment_status }}">
                        {{ $appointment->payment_status_text }}
                    </span>
                </div>
            </div>
            @if($appointment->payment->paid_at)
                <div class="info-row">
                    <div class="info-label">Payé le :</div>
                    <div class="info-value">{{ $appointment->payment->paid_at->format('d/m/Y à H:i') }}</div>
                </div>
            @endif
        </div>
    @endif

    @if($appointment->notes)
        <div class="info-section">
            <h3>📝 Notes Médicales</h3>
            <p>{{ $appointment->notes }}</p>
        </div>
    @endif
</div>

<div class="clearfix"></div>

<div class="warning">
    ⚠️ <strong>Important :</strong> Ce justificatif est valable uniquement pour le rendez-vous mentionné ci-dessus.
    En cas de modification ou d'annulation, un nouveau justificatif sera généré.
    Présentez-vous 15 minutes avant l'heure du rendez-vous.
</div>

<div class="footer">
    <p><strong>Document généré le :</strong> {{ $generatedAt }}</p>
    <p>Ce document est authentifié par QR Code et peut être vérifié en ligne.</p>
    <p>Plateforme Médicale - Tous droits réservés © {{ date('Y') }}</p>
</div>
</body>
</html>
