<!-- resources/views/emails/appointment-reminder.blade.php -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rappel de rendez-vous</title>
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
            background-color: #ffc107;
            color: #212529;
            padding: 20px;
            text-align: center;
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
            border-left: 4px solid #ffc107;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
        }
        .btn-cancel {
            background-color: #dc3545;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #666;
        }
        .countdown {
            background-color: #007bff;
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            margin: 20px 0;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>🔔 Rappel de Rendez-vous</h1>
</div>

<div class="content">
    <p>Bonjour <strong>{{ $patient->name }}</strong>,</p>

    <div class="countdown">
        📅 Votre rendez-vous est prévu {{ $appointment->appointment_date->diffForHumans() }}
    </div>

    <p>Nous vous rappelons votre rendez-vous médical :</p>

    <div class="appointment-details">
        <h3>📋 Détails du rendez-vous</h3>
        <p><strong>Numéro :</strong> {{ $appointment->appointment_number }}</p>
        <p><strong>Médecin :</strong> Dr. {{ $doctor->user->name }}</p>
        <p><strong>Spécialité :</strong> {{ $doctor->specialty->name }}</p>
        <p><strong>Date et heure :</strong> {{ $appointment->appointment_date->format('d/m/Y à H:i') }}</p>
        <p><strong>Cabinet :</strong> {{ $doctor->clinic_name }}</p>
        <p><strong>Adresse :</strong> {{ $doctor->clinic_address }}</p>
    </div>

    <div style="background-color: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <strong>✅ À prévoir :</strong>
        <ul>
            <li>Arrivez 15 minutes avant l'heure</li>
            <li>Pièce d'identité et carte vitale</li>
            <li>Liste de vos médicaments actuels</li>
            <li>Examens médicaux récents si pertinents</li>
        </ul>
    </div>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ config('app.frontend_url') }}/appointments/{{ $appointment->id }}" class="btn">
            Voir les détails
        </a>
        <a href="{{ config('app.frontend_url') }}/appointments/{{ $appointment->id }}/cancel" class="btn btn-cancel">
            Annuler le RDV
        </a>
    </div>

    <p>En cas de questions, n'hésitez pas à nous contacter.</p>

    <p>Cordialement,<br>
        L'équipe de la Plateforme Médicale</p>
</div>

<div class="footer">
    <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
    <p>© {{ date('Y') }} Plateforme Médicale - Tous droits réservés</p>
</div>
</body>
</html>
