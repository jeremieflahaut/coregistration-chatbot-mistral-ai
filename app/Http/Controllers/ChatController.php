<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Models\Chat;

class ChatController extends Controller
{
    protected string $context = 'Tu es un chatbot amical qui guide les utilisateurs à travers une inscription pour la coregistration.
        Ton objectif est d\'accompagner l\'utilisateur de manière fluide et engageante dans le processus d\'inscription, en posant une série de questions pour collecter son prénom, son nom, et son adresse email.

        Une fois ces informations recueillies, tu lui présentes une sélection d\'offres pertinentes en fonction de son profil. L\'utilisateur peut choisir une ou plusieurs offres auxquelles il souhaite souscrire.

        Tu dois t\'assurer que l\'expérience soit fluide et agréable :
        ✅ Ton ton est amical et rassurant pour mettre l\'utilisateur en confiance.
        ✅ Tu reformules en cas de besoin si une réponse n’est pas valide (ex : email incorrect).
        ✅ Tu présentes les offres de manière engageante pour susciter l\'intérêt.
        ✅ Tu confirmes chaque étape pour éviter toute confusion.
        ✅ Si l’utilisateur hésite, tu peux l’aider en lui expliquant les bénéfices des offres.
        ✅ À la fin, tu remercies l\'utilisateur chaleureusement et lui rappelles qu\'il recevra un email de confirmation.

        Exemples de réponses que tu peux donner :

        "Super, merci ! Maintenant, peux-tu me donner ton nom de famille ?"
        "Je suis là pour t\'aider 😊. Voici quelques offres intéressantes pour toi !"
        "Si tu n\'es pas sûr(e), je peux t\'expliquer les avantages de ces offres."
        "Merci pour ton inscription ! Tu recevras bientôt un email avec tous les détails. 🚀"

        Si l’utilisateur ne répond pas après un certain temps, tu peux le relancer poliment pour éviter qu’il abandonne la conversation.';

    public function start(): JsonResponse
    {
        $sessionId = Str::uuid();
        $chat = Chat::create([
            'session_id' => $sessionId,
            'state' => 'ask_name',
            'data' => []
        ]);

        return response()->json([
            'session_id' => $sessionId,
            'message' => $this->generateMistralResponse($chat, "Démarre la conversation en expliquant que tu es la pour proposer des offres promotionnelles et demande le prénom de l'utilisateur de manière amicale.")
        ]);
    }

    public function reply(Request $request): JsonResponse
    {
        $chat = Chat::where('session_id', $request->session_id)->firstOrFail();
        $message = $request->message ?? '';

        $data = $chat->data ?? [];

        Log::debug($chat->state);

        switch ($chat->state) {
            case 'ask_name':
                $data['name'] = $message;
                $chat->data = $data;
                $chat->state = 'ask_surname';
                break;

            case 'ask_surname':
                $data['surname'] = $message;
                $chat->data = $data;
                $chat->state = 'ask_email';
                break;

            case 'ask_email':
                if (!filter_var($message, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['message' => $this->generateMistralResponse($chat, "L'utilisateur a donné une adresse email invalide, demande-lui de la reformuler.")]);
                }

                $offers = $this->fetchOffers();
                $data['email'] = $message;
                $data['offers'] = $offers;
                $chat->data = $data;
                $chat->state = 'show_offers';
                $chat->save();

                $offersText = "Voici les offres disponibles pour toi :<br>";
                foreach ($offers as $index => $offer) {
                    $offersText .= ($index + 1) . "️⃣ " . $offer['name'] . " - " . $offer['description'] . "<br>";
                }

                $offersText .= "<br>Sélectionne les offres qui t'intéressent en indiquant leurs numéros.";

                return response()->json(['message' => $offersText]);

            case 'show_offers':
                $selectedOptins = $this->extractSelectedOffers($message, $chat->data['offers'] ?? []);

                if (empty($selectedOptins)) {
                    return response()->json([
                        'message' => $this->generateMistralResponse($chat, "Je ne reconnais pas ton choix. Peux-tu préciser en mentionnant le numéro ou le nom de l'offre ?")
                    ]);
                }

                $data['optins'] = $selectedOptins;
                $chat->data = $data;
                $chat->state = 'finished';
                $chat->save();

                $this->sendOptins($chat->data);

                return response()->json([
                    'message' => $this->generateFinalMessage($chat),
                ]);

            case 'finished':
                return response()->json([
                    'message' => "Notre conversation est terminée. Merci encore et bonne journée ! 🌟",
                    'state' => "finished"
                ]);

            default:
                return response()->json(['message' => "Je ne comprends pas votre réponse."]);
        }

        $chat->save();
        return response()->json(['message' => $this->generateMistralResponse($chat, "Continue la conversation selon l'état suivant.")]);
    }

    private function fetchOffers(): array
    {
        //TODO Get offers vie api
        $offers = [
            [
                "offer_id" => "123",
                "name" => "Assurance Auto",
                "description" => "Bénéficiez d'une couverture complète pour votre véhicule.",
                "optin_token" => "abc123"
            ],
            [
                "offer_id" => "456",
                "name" => "Forfait Mobile 50 Go",
                "description" => "Profitez de 50 Go pour seulement 9,99€/mois.",
                "optin_token" => "def456"
            ],
            [
                "offer_id" => "789",
                "name" => "Crédit Express",
                "description" => "Un crédit immédiat avec des taux préférentiels.",
                "optin_token" => "ghi789"
            ],
            [
                "offer_id" => "101",
                "name" => "Box Internet Fibre",
                "description" => "Une connexion ultra-rapide avec un prix spécial pour les nouveaux clients.",
                "optin_token" => "jkl101"
            ]
        ];

        shuffle($offers);
        return array_slice($offers, 0, rand(2, 3));
    }

    private function sendOptins($data): void
    {
        foreach ($data['optins'] as $optin) {
            Log::debug(var_export([
                'email' => $data['email'],
                'offer_id' => $optin['offer_id'],
                'optin_token' => $optin['optin_token']
            ], true));
        }
    }

    private function generateMistralResponse($chat, $prompt)
    {
        $context = $this->context;

        $context .= " L'utilisateur a déjà fourni ces informations : " . json_encode($chat->data) . ".";
        $context .= " Tu dois poursuivre naturellement et poser la question suivante en fonction de l'étape actuelle qui est : " . $chat->state . ".";
        $context .= " Ne change jamais les informations fournies par l'utilisateur.";
        $context .= " Garde le prénom exact donné par l'utilisateur, sans le modifier ni le reformuler.";
        $context .= " Garde les réponses précises et ne modifie jamais les informations personnelles.";

        if ($chat->state === 'show_offers') {
            $context .= " Présente les offres sous forme de liste numérotée, avec chaque offre sur une ligne distincte.";
            $context .= " Utilise ce format EXACTEMENT, avec des retours à la ligne :\n";
            $context .= " 1️⃣ Assurance Auto - Une couverture complète pour votre voiture.\n";
            $context .= " 2️⃣ Forfait Mobile - 50 Go pour 9,99€/mois.\n";
            $context .= " 3️⃣ Crédit Express - Un crédit rapide à taux réduit.\n";
            $context .= " N'oublie pas d'ajouter une nouvelle ligne après chaque offre.";
            $context .= " Demande ensuite à l'utilisateur de sélectionner les offres en donnant leurs numéros.";
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('MISTRAL_API_KEY'),
            'Content-Type' => 'application/json'
        ])->post(env('MISTRAL_API_URL'), [
            'model' => 'mistral-small-latest',
            'messages' => [
                ['role' => 'system', 'content' => $context],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.2,
        ]);

        $responseData = $response->json();
        return $responseData['choices'][0]['message']['content'] ?? "Je ne suis pas sûr de comprendre, peux-tu reformuler ?";
    }

    private function extractSelectedOffers($userMessage, $offers): array
    {
        $selectedOptins = [];
        $totalOffers = count($offers);

        preg_match_all('/\d+/', $userMessage, $matches);
        $selectedIndexes = array_map('intval', $matches[0]);


        foreach ($selectedIndexes as $index) {
            if ($index > $totalOffers) {

                $digits = str_split((string)$index);
                foreach ($digits as $digit) {
                    $digitIndex = intval($digit);
                    if ($digitIndex > 0 && $digitIndex <= $totalOffers) {
                        $selectedIndexes[] = $digitIndex;
                    }
                }
            }
        }

        $selectedIndexes = array_unique($selectedIndexes);

        foreach ($selectedIndexes as $index) {
            if (isset($offers[$index - 1])) {
                $selectedOptins[] = [
                    'offer_id' => $offers[$index - 1]['offer_id'],
                    'name' => $offers[$index - 1]['name'],
                    'optin_token' => $offers[$index - 1]['optin_token']
                ];
            }
        }

        foreach ($offers as $offer) {
            if (stripos($userMessage, $offer['name']) !== false) {
                $selectedOptins[] = [
                    'offer_id' => $offer['offer_id'],
                    'name' => $offer['name'],
                    'optin_token' => $offer['optin_token']
                ];
            }
        }

        return array_unique($selectedOptins, SORT_REGULAR);
    }

    private function generateFinalMessage($chat): string
    {
        $offers = $chat->data['optins'] ?? [];
        $offerList = [];

        foreach ($offers as $optin) {
            $offerList[] = $optin['name'];
        }

        $offerText = count($offerList) > 1
            ? "Tu as sélectionné ces offres :\n" . implode(", ", $offerList)
            : "Tu as sélectionné l’offre : " . $offerList[0];

        return "Merci pour ton inscription, " . $chat->data['name'] . " ! 🎉\n\n"
            . "$offerText\n\n"
            . "Tu recevras bientôt un email avec tous les détails. Bonne journée ! 😊";
    }
}
