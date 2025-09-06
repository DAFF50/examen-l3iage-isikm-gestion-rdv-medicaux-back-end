<?php
// app/Http/Controllers/AiAssistantController.php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AiAssistantController extends Controller
{
    private $openaiApiUrl = 'https://api.openai.com/v1/chat/completions';

    /**
     * Chat IA pour visiteurs (sans authentification)
     */
    public function chat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
            'session_id' => 'nullable|string',
            'type' => 'nullable|in:health_question,navigation_help,general'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Message invalide',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $sessionId = $request->session_id ?? Str::uuid();
            $messageType = $request->type ?? 'general';

            $response = $this->processAiRequest($request->message, $messageType, $sessionId);

            // Sauvegarder la conversation (sans user_id pour les visiteurs)
            AiConversation::createConversation(
                null,
                $sessionId,
                $request->message,
                $response['message'],
                $messageType,
                ['is_guest' => true]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $response['message'],
                    'type' => $messageType,
                    'session_id' => $sessionId,
                    'confidence' => $response['confidence'] ?? null
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement de votre message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Chat IA pour utilisateurs authentifiés
     */
    public function authenticatedChat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
            'session_id' => 'nullable|string',
            'type' => 'nullable|in:health_question,navigation_help,general'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Message invalide',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            $sessionId = $request->session_id ?? Str::uuid();
            $messageType = $request->type ?? 'general';

            // Récupérer le contexte de la conversation
            $context = $this->getConversationContext($user->id, $sessionId);

            $response = $this->processAiRequest(
                $request->message,
                $messageType,
                $sessionId,
                $user,
                $context
            );

            // Sauvegarder la conversation
            AiConversation::createConversation(
                $user->id,
                $sessionId,
                $request->message,
                $response['message'],
                $messageType,
                [
                    'user_type' => $user->user_type,
                    'confidence' => $response['confidence'] ?? null
                ]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $response['message'],
                    'type' => $messageType,
                    'session_id' => $sessionId,
                    'confidence' => $response['confidence'] ?? null,
                    'suggestions' => $response['suggestions'] ?? []
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement de votre message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Traitement principal de la requête IA
     */
    private function processAiRequest($message, $type, $sessionId, $user = null, $context = [])
    {
        // Définir le prompt selon le type de message
        $systemPrompt = $this->getSystemPrompt($type, $user);

        // Ajouter le contexte de conversation
        $conversationHistory = $this->buildConversationHistory($sessionId, $context);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$conversationHistory,
            ['role' => 'user', 'content' => $message]
        ];

        // Si OpenAI n'est pas configuré, utiliser des réponses prédéfinies
        if (!config('services.openai.api_key')) {
            return $this->getPreDefinedResponse($message, $type);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->openaiApiUrl, [
                'model' => 'gpt-3.5-turbo',
                'messages' => $messages,
                'max_tokens' => 500,
                'temperature' => 0.7,
                'top_p' => 1,
                'frequency_penalty' => 0,
                'presence_penalty' => 0
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'message' => $data['choices'][0]['message']['content'],
                    'confidence' => 0.95
                ];
            } else {
                throw new \Exception('Erreur API OpenAI: ' . $response->body());
            }

        } catch (\Exception $e) {
            // Fallback vers les réponses prédéfinies
            return $this->getPreDefinedResponse($message, $type);
        }
    }

    /**
     * Prompts système selon le type
     */
    private function getSystemPrompt($type, $user = null)
    {
        $basePrompt = "Tu es un assistant médical intelligent pour une plateforme de prise de rendez-vous. ";

        switch ($type) {
            case 'health_question':
                return $basePrompt . "Réponds aux questions de santé générales avec bienveillance, mais rappelle toujours que tes réponses ne remplacent pas une consultation médicale. Recommande de consulter un professionnel pour tout problème spécifique.";

            case 'navigation_help':
                $userContext = $user ? "L'utilisateur est un(e) {$user->user_type}." : "L'utilisateur est un visiteur.";
                return $basePrompt . "Aide l'utilisateur à naviguer sur la plateforme, à prendre rendez-vous, comprendre les fonctionnalités. {$userContext} Sois précis et utile.";

            case 'general':
            default:
                return $basePrompt . "Sois serviable, professionnel et bienveillant. Tu peux aider avec les questions médicales générales et la navigation sur la plateforme.";
        }
    }

    /**
     * Réponses prédéfinies (fallback)
     */
    private function getPreDefinedResponse($message, $type)
    {
        $message = strtolower($message);

        // Mots-clés pour les questions de santé
        $healthKeywords = ['douleur', 'mal', 'symptôme', 'fièvre', 'toux', 'médecin', 'consultation'];
        $navigationKeywords = ['rendez-vous', 'réserver', 'annuler', 'paiement', 'docteur', 'spécialité'];

        if ($type === 'health_question' || $this->containsAny($message, $healthKeywords)) {
            $responses = [
                "Je comprends votre préoccupation concernant votre santé. Bien que je puisse vous donner des informations générales, je vous recommande fortement de consulter un professionnel de santé pour un diagnostic précis. Voulez-vous que je vous aide à trouver un médecin sur notre plateforme ?",
                "Pour tout symptôme ou problème de santé, il est important de consulter un médecin. Je peux vous aider à prendre rendez-vous avec un spécialiste adapté à votre situation. Quelle spécialité recherchez-vous ?",
                "Votre santé est importante. Bien que je puisse partager des informations générales, seul un médecin peut évaluer correctement vos symptômes. Puis-je vous aider à trouver un médecin disponible ?"
            ];
        } elseif ($type === 'navigation_help' || $this->containsAny($message, $navigationKeywords)) {
            $responses = [
                "Je suis là pour vous aider à utiliser notre plateforme ! Vous pouvez rechercher des médecins par spécialité, consulter leurs disponibilités et prendre rendez-vous en ligne. Que souhaitez-vous faire ?",
                "Notre plateforme vous permet de : 1) Rechercher des médecins par spécialité, 2) Consulter leurs profils et disponibilités, 3) Prendre rendez-vous, 4) Payer en ligne. Comment puis-je vous aider ?",
                "Pour prendre rendez-vous, c'est simple ! Choisissez une spécialité, sélectionnez un médecin, puis un créneau disponible. Vous pouvez payer en ligne ou au cabinet. Avez-vous besoin d'aide pour une étape particulière ?"
            ];
        } else {
            $responses = [
                "Bonjour ! Je suis votre assistant médical. Je peux vous aider avec des questions de santé générales et vous guider pour utiliser notre plateforme de rendez-vous médicaux. Comment puis-je vous aider aujourd'hui ?",
                "Hello ! Je suis là pour vous assister. Que ce soit pour des informations de santé ou pour naviguer sur notre plateforme, n'hésitez pas à me poser vos questions !",
                "Bienvenue ! En tant qu'assistant médical, je peux vous aider à trouver des médecins, comprendre nos services, ou répondre à vos questions générales sur la santé. Que puis-je faire pour vous ?"
            ];
        }

        return [
            'message' => $responses[array_rand($responses)],
            'confidence' => 0.8
        ];
    }

    /**
     * Vérifier si le message contient certains mots-clés
     */
    private function containsAny($message, $keywords)
    {
        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Récupérer le contexte de conversation
     */
    private function getConversationContext($userId, $sessionId)
    {
        return AiConversation::where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['user_message', 'ai_response'])
            ->reverse();
    }

    /**
     * Construire l'historique de conversation
     */
    private function buildConversationHistory($sessionId, $context)
    {
        $history = [];

        foreach ($context as $conv) {
            $history[] = ['role' => 'user', 'content' => $conv->user_message];
            $history[] = ['role' => 'assistant', 'content' => $conv->ai_response];
        }

        return $history;
    }

    /**
     * Récupérer les conversations de l'utilisateur
     */
    public function getConversations(Request $request)
    {
        try {
            $conversations = AiConversation::where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $conversations
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des conversations'
            ], 500);
        }
    }

    /**
     * Donner un feedback sur une réponse
     */
    public function giveFeedback(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'is_helpful' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $conversation = AiConversation::findOrFail($id);

            // Vérifier que c'est la conversation de l'utilisateur
            if ($conversation->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            $conversation->markAsHelpful($request->is_helpful);

            return response()->json([
                'success' => true,
                'message' => 'Feedback enregistré'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement'
            ], 500);
        }
    }

    /**
     * Suggestions intelligentes basées sur le profil utilisateur
     */
    public function getSuggestions(Request $request)
    {
        try {
            $user = $request->user();
            $suggestions = [];

            if ($user->user_type === 'patient') {
                $suggestions = [
                    'Comment prendre un rendez-vous ?',
                    'Quelles spécialités sont disponibles ?',
                    'Comment annuler un rendez-vous ?',
                    'Comment payer ma consultation ?',
                    'Où télécharger mon justificatif ?'
                ];

                // Suggestions personnalisées basées sur l'historique
                if ($user->patientAppointments()->count() === 0) {
                    array_unshift($suggestions, 'Comment créer mon premier rendez-vous ?');
                }

            } elseif ($user->user_type === 'doctor') {
                $suggestions = [
                    'Comment gérer mes créneaux ?',
                    'Comment confirmer un rendez-vous ?',
                    'Comment consulter mes revenus ?',
                    'Comment modifier mes horaires ?',
                    'Comment gérer les annulations ?'
                ];
            } else {
                $suggestions = [
                    'Comment utiliser la plateforme ?',
                    'Quels sont vos services ?',
                    'Comment contacter le support ?'
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $suggestions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des suggestions'
            ], 500);
        }
    }

    /**
     * Analyse des tendances des questions
     */
    public function getAnalytics(Request $request)
    {
        try {
            // Seulement pour les admins
            if ($request->user()->user_type !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            $analytics = [
                'total_conversations' => AiConversation::count(),
                'conversations_today' => AiConversation::whereDate('created_at', today())->count(),
                'by_type' => AiConversation::selectRaw('type, COUNT(*) as count')
                    ->groupBy('type')
                    ->get(),
                'satisfaction_rate' => AiConversation::whereNotNull('is_helpful')
                        ->selectRaw('AVG(CASE WHEN is_helpful = 1 THEN 100 ELSE 0 END) as rate')
                        ->first()->rate ?? 0,
                'most_active_hours' => AiConversation::selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
                    ->groupBy('hour')
                    ->orderBy('count', 'desc')
                    ->limit(5)
                    ->get()
            ];

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des analytics'
            ], 500);
        }
    }

    /**
     * Test de l'IA (développement)
     */
    public function testAi()
    {
        try {
            $testMessage = "Bonjour, comment prendre un rendez-vous ?";
            $response = $this->processAiRequest($testMessage, 'navigation_help', 'test-session');

            return response()->json([
                'success' => true,
                'test_message' => $testMessage,
                'ai_response' => $response
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
