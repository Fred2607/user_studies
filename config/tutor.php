<?php

return [
    /*
    | Module 4 tutor (app/Services/Tutor). Materials are read at runtime: emails-m4.json,
    | tutor-system-prompt.md, tutor-output-schema.json, test-dialogs.md.
    */
    'materials_dir' => env('TUTOR_MATERIALS_DIR', resource_path('tutor')),

    // Per-call timeout of a tutor turn (ms); one retry, then the deterministic fallback (cap4 4.4.5).
    'timeout_ms' => (int) env('TUTOR_TIMEOUT_MS', 20000),

    // Tutor client (container binding "tutor.llm"): Claude, low effort for chat latency, no SDK retries.
    'model' => env('TUTOR_MODEL', env('ANTHROPIC_MODEL', 'claude-opus-5-5')),
    'effort' => env('TUTOR_EFFORT', 'low'),
    'max_tokens' => (int) env('TUTOR_MAX_TOKENS', 2000),

    // TutorChat (Livewire): user messages sent to the tutor per user per minute; over the limit no LLM call is made.
    'rate_limit_per_minute' => (int) env('TUTOR_RATE_LIMIT_PER_MINUTE', 20),

    // Maximum length (characters) of one user message to the tutor.
    'max_message_chars' => (int) env('TUTOR_MAX_MESSAGE_CHARS', 1000),

    // Per-user lock while a reply is being processed (s): covers 2 turns x 2 attempts x timeout.
    'lock_seconds' => (int) env('TUTOR_LOCK_SECONDS', 120),
];
