<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService; // adjust namespace to wherever you place ChatbotService.php
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChatbotController extends Controller
{
    private ChatbotService $chatbot;

    public function __construct(ChatbotService $chatbot)
    {
        $this->chatbot = $chatbot;
    }

    public function respond(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $result = $this->chatbot->respond(trim($validated['message']));

        return response()->json($result);
    }
}

/*
 * SETUP:
 * 1. Move NaiveBayesClassifier.php, ChatbotService.php, chatbot-data.php into
 *    app/Services/ (create the folder if it doesn't exist). Update the
 *    require_once paths in ChatbotService.php if you move chatbot-data.php
 *    elsewhere.
 * 2. Add the App\Services namespace to ChatbotService.php and
 *    NaiveBayesClassifier.php (or leave them un-namespaced and adjust the
 *    `use` statement above to match).
 * 3. Add this controller to app/Http/Controllers/.
 * 4. In routes/api.php (or routes/web.php if you're not using API routes):
 *
 *      use App\Http\Controllers\ChatbotController;
 *      Route::post('/chatbot', [ChatbotController::class, 'respond']);
 *
 * 5. Your endpoint is now POST /api/chatbot (or /chatbot if added to web.php).
 */
