<!-- resources/views/pdf/payment-receipt.blade.php -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçu de Paiement</title>
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
            border-bottom: 3px solid #28a745;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #28a745;
            font-size: 24px;
            margin: 0;
        }

        .header h2 {
            color: #666;
            font-size: 16px;
            margin: 5px 0;
        }

        .transaction-id {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
            text-align: center;
            margin: 20px 0;
            padding: 10px;
            background-color: #d4edda;
            border-radius: 5px;
        }

        .amount-section {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #f8f9fa;
            border: 2px solid #28a745;
            border-radius: 10px;
        }

        .amount {
            font-size: 36px;
            font-weight: bold;
            color: #28a745;
            margin: 10px 0;
        }

        .amount-label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
        }

        .info-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-left: 4px solid #28a745;
        }

        .info-section h3 {
            margin-top: 0;
            color: #28a745;
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

        .qr-section {
            float: right;
            text-align: center;
            margin: 20px 0;
        }

        .qr-code {
            border: 2px solid #28a745;
            padding: 10px;
            background: white;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 12px;
            color: #666;
        }

        .status-paid {
            background-color: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .clearfix {
            clear: both;
        }

        .payment-method {
            text-transform: uppercase;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>REÇU DE PAIEMENT</h1>
    <h2>Consultation Médicale</h2>
</div>

<div class="transaction-id">
    Transaction N° {{ $payment->transaction_id }}
</div>

<div class="qr-section">
    <div class="qr-code">
        <img src="data:image/png;base64,{{ $qrCode }}" alt="QR Code">
    </div>
    <p style="font-size: 10px; margin: 5px 0;">Code de vérification</p>
</div>

<div class="amount-section">
    <div class="amount-label">Montant Payé</div>
    <div class="amount">{{ number_format($payment->amount, 0, ',', ' ') }} {{ $payment->currency }}</div>
    <div class="status-paid">PAYÉ</div>
</div>

<div class="clearfix"></div>

<div class="info-section">
    <h3>💳 Détails du Paiement</h3>
    <div class="info-row">
        <div class="info-label">Date de paiement :</div>
        <div class="info-value">{{ $payment->paid_at->format('d/m/Y à H:i') }}</div>
    </div>
    <div class="info-row">
        <div class="info-label">Mode de paiement :</div>
        <div class="info-value payment-method">{{ $payment->payment_method }}</div>
    </div>
    <div class="info-row">
        <div class="info-label">Référence externe :</div>
        <div class="info-value">{{ $payment->gateway_transaction_id ?? 'N/A' }}</div>
    </div>
    <div class="info-row">
        <div class="info-label">Devise :</div>
        <div class="info-value">{{ $payment->currency }}</div>
    </div>
</div>

<div class="info-section">
    <h3>🏥 Détails de la Consultation</h3>
    <div class="info-row">
        <div class="info-label">Rendez-vous N° :</div>
        <div class="info-value">{{ $payment->appointment->appointment_number }}</div>
    </div>
    <div class="info-row">
        <div class="info-label">Date du RDV :</div>
        <div class="info-value">{{ $payment->appointment->appointment_date->format('d/m/Y à H:i') }}</div>
    </div>
    <div class="info-row">
        <div class="info-label">Médecin :</div>
        <div class="info-value">Dr. {{ $payment->appointment->doctor->user->name }}</div>
    </div>
    <div class="info-row">
        <div class="info-label">Spécialité :</div>
        <div class="info-value">{{ $payment->appointment->doctor->specialty->name }}</div>
    </div>
</div>

<div class="info-section">
    <h3>👤 Informations du Patient</h3>
    <div class="info-row">
        <div class="info-label">Nom :</div>
        <div class="info-value">{{ $payment->appointment->patient->name }}</div>
    </div>
    <div class="info-row">
        <div class="info-label">Email :</div>
        <div class="info-value">{{ $payment->appointment->patient->email }}</div>
    </div>
</div>

@if($payment->appointment->doctor->clinic_name)
    <div class="info-section">
        <h3>📍 Lieu de Consultation</h3>
        <div class="info-row">
            <div class="info-label">Cabinet :</div>
            <div class="info-value">{{ $payment->appointment->doctor->clinic_name }}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Adresse :</div>
            <div class="info-value">{{ $payment->appointment->doctor->clinic_address }}</div>
        </div>
    </div>
@endif

<div class="footer">
    <p><strong>Reçu généré le :</strong> {{ $generatedAt }}</p>
    <p>Ce reçu est authentifié et peut être vérifié en ligne via le QR Code.</p>
    <p><strong>Important :</strong> Conservez ce reçu comme justificatif de paiement.</p>
    <p>Plateforme Médicale - Tous droits réservés © {{ date('Y') }}</p>
</div>
</body>
</html>
