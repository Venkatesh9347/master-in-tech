<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Foundation Kill Switch (AI-0)
    |--------------------------------------------------------------------------
    |
    | The AI gateway refuses every request while this is false, before any
    | provider is resolved or contacted. AI is infrastructure only and stays
    | disabled unless explicitly enabled here. An administrator may override
    | the non-secret keys below at runtime via website settings (group
    | "ai"); API keys always come from server environment, never settings.
    |
    */
    'enabled' => filter_var(env('AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "openai", "gemini", "anthropic", "ollama", "stub"
    | Use "stub" for local development without API credentials.
    | Use "ollama" for a self-hosted local model daemon (optional — the
    | application never requires Ollama to be installed; an unreachable
    | daemon fails gracefully with a 503, never a crash).
    |
    | Selecting any other value fails loudly at service resolution time (an
    | exception is thrown instead of silently falling back), so a typo can
    | never silently route traffic somewhere unexpected.
    |
    */
    'default_provider' => env('AI_PROVIDER', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | Default Model Override
    |--------------------------------------------------------------------------
    |
    | Optional global fallback used only when the resolved provider has no
    | configured model of its own. Blank means "no global override".
    |
    */
    'default_model' => env('AI_DEFAULT_MODEL'),

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
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest'),
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        ],
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            'model' => env('OLLAMA_MODEL', 'llama3.1'),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Pricing (estimates only)
    |--------------------------------------------------------------------------
    |
    | Per-1K-token prices in the given currency, used solely to estimate AI
    | usage cost for internal tracking. These are placeholders — verify
    | current provider pricing before relying on the estimates for anything
    | beyond rough internal accounting. Unknown models simply estimate
    | nothing (null) and never fail the AI request.
    |
    */
    'pricing' => [
        'openai' => [
            'gpt-4o-mini' => ['input_per_1k' => 0.00015, 'output_per_1k' => 0.0006, 'currency' => 'USD'],
        ],
        'gemini' => [
            'gemini-2.0-flash' => ['input_per_1k' => 0.0001, 'output_per_1k' => 0.0004, 'currency' => 'USD'],
        ],
        'anthropic' => [
            'claude-3-5-haiku-latest' => ['input_per_1k' => 0.0008, 'output_per_1k' => 0.004, 'currency' => 'USD'],
        ],
    ],

];
