<?php

return [
    /*
    | Module 4 tutor (app/Services/Tutor). Materials are read at runtime: emails-m4.json,
    | tutor-system-prompt.md, tutor-output-schema.json, test-dialogs.md.
    */
    'materials_dir' => env('TUTOR_MATERIALS_DIR', resource_path('tutor')),

    // Per-call timeout of a tutor turn (ms); one retry, then the deterministic fallback (cap4 4.4.5).
    'timeout_ms' => (int) env('TUTOR_TIMEOUT_MS', 20000),
];
