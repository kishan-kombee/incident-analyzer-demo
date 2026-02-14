<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1/chat/completions'),
    ],

    /*
    | Google Gemini (free tier for dev: https://aistudio.google.com/app/apikey)
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent'),
    ],

    /*
    | xAI Grok (OpenAI-compatible: https://console.x.ai — API key at console.x.ai/team/default/api-keys)
    */
    'grok' => [
        'key' => env('GROK_API_KEY'),
        'model' => env('GROK_MODEL', 'grok-2'),
        'url' => env('GROK_URL', 'https://api.x.ai/v1/chat/completions'),
    ],

    /*
    | Groq (fast inference, free tier: https://console.groq.com/keys — models: llama-3.3-70b-versatile, mixtral-8x7b, etc.)
    */
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1/chat/completions'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
    ],

];
