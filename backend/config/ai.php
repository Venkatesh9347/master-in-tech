<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "openai", "stub"
    | Use "stub" for local development without API credentials.
    |
    | Selecting any other value fails loudly at service resolution time (an
    | exception is thrown instead of silently falling back), so a typo or an
    | unsupported provider (e.g. a not-yet-implemented "ollama") can never
    | silently route traffic somewhere unexpected.
    |
    */
    'default_provider' => env('AI_PROVIDER', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | System Prompt
    |--------------------------------------------------------------------------
    */
    'system_prompt' => env(
        'AI_SYSTEM_PROMPT',
        'You are MasterInTech AI, a helpful assistant for students learning technology, programming, cloud, data science, and career skills. Provide clear, accurate, and encouraging guidance. Do not invent course enrollment status or access credentials.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Conversation Limits
    |--------------------------------------------------------------------------
    */
    'max_message_length' => (int) env('AI_MAX_MESSAGE_LENGTH', 4000),
    'max_history_messages' => (int) env('AI_MAX_HISTORY_MESSAGES', 40),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 1024),

    /*
    |--------------------------------------------------------------------------
    | Provider Credentials & Settings
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        ],
    ],

];
