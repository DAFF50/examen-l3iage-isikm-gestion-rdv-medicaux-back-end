<!-- resources/views/emails/appointment-confirmed.blade.php -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation de rendez-vous</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #007bff;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background-color: #f8f9fa;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .message-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #007bff;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>{{ $notification->title }}</h1>
</div>

<div class="content">
    <p>Bonjour <strong>{{ $user->name }}</strong>,</p>

    <div class="message-content">
        <p>{{ $notification->message }}</p>
    </div>

    @if($notification->data && isset($notification->data['appointment_id']))
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ config('app.frontend_url') }}/appointments/{{ $notification->data['appointment_id'] }}" class="btn">
                Voir le rendez-vous
            </a>
        </div>
    @endif

    <p>Cordialement,<br>
        L'équipe de la Plateforme Médicale</p>
</div>

<div class="footer">
    <p>Vous recevez cet email car vous êtes inscrit sur notre plateforme.</p>
    <p>© {{ date('Y') }} Plateforme Médicale - Tous droits réservés</p>
</div>
</body>
</html>
border-radius: 8px 8px 0 0;
}
.content {
background-color: #f8f9fa;
padding: 30px;
border-radius: 0 0 8px 8px;
}
.appointment-details {
background-color: white;
padding: 20px;
border-radius: 8px;
margin: 20px 0;
border-left: 4px solid #007bff;
}
.btn {
display: inline-block;
padding: 12px 24px;
background-color: #28a745;
color: white;
text-decoration: none;
border-radius: 5px;
margin: 10px 0;
}
.footer {
text-align: center;
margin-top: 30px;
font-size: 12px;
color: #666;
}
</style>
</head>
<body>
<div class="header">
    <h1>✅ Rendez-vous Confirmé</h1>
</div>

<div class="content">
    <p>Bonjour <strong>{{ $patient->name }}</strong>,</p>

    <p>Votre rendez-vous a été confirmé avec succès !</p>

    <div class="appointment-details">
        <h3>📋 Détails du rendez-vous</h3>
        <p><strong>Numéro :</strong> {{ $appointment->appointment_number }}</p>
        <p><strong>Médecin :</strong> Dr. {{ $doctor->user->name }}</p>
        <p><strong>Spécialité :</strong> {{ $doctor->specialty->name }}</p>
        <p><strong>Date et heure :</strong> {{ $appointment->appointment_date->format('d/m/Y à H:i') }}</p>
        <p><strong>Cabinet :</strong> {{ $doctor->clinic_name }}</p>
        <p><strong>Adresse :</strong> {{ $doctor->clinic_address }}</p>
        @if($appointment->reason)
            <p><strong>Motif :</strong> {{ $appointment->reason }}</p>
        @endif
    </div>

    @if($appointment->payment && $appointment->payment_status === 'paid')
        <div class="appointment-details">
            <h3>💳 Informations de paiement</h3>
            <p><strong>Montant :</strong> {{ number_format($appointment->amount, 0, ',', ' ') }} {{ $appointment->payment->currency }}</p>
            <p><strong>Statut :</strong> <span style="color: #28a745;">Payé</span></p>
            <p><strong>Transaction :</strong> {{ $appointment->payment->transaction_id }}</p>
        </div>
    @endif

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ config('app.frontend_url') }}/appointments/{{ $appointment->id }}" class="btn">
            Voir mon rendez-vous
        </a>
    </div>

    <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <strong>⚠️ Important :</strong>
        <ul>
            <li>Présentez-vous 15 minutes avant l'heure du rendez-vous</li>
            <li>Apportez une pièce d'identité et votre carte vitale</li>
            <li>En cas d'empêchement, annulez au moins 24h à l'avance</li>
        </ul>
    </div>

    <p>Cordialement,<br>
        L'équipe de la Plateforme Médicale</p>
</div>

<div class="footer">
    <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
    <p>© {{ date('Y') }} Plateforme Médicale - Tous droits réservés</p>
</div>
</body>
</html>
