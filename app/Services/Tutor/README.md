# Module 4 tutor core (PHP port of `prototipo/tutor-core`)

Faithful port of the TypeScript core: same materials (`resources/tutor/`, copied from `prototipo/materiali/`, see the divergence below; path from `config('tutor.materials_dir')`), same rules, same 22 harness cases. No UI, no DB, no network.

| TS | PHP |
|---|---|
| `materials.ts` | `Materials` (cards stay decoded associative arrays) |
| `prompt-builder.ts` | `PromptBuilder`, `PromptContext`, `BuiltPrompt` |
| `text.ts` | `Text` (+ `JsRegex`) |
| `validator.ts` (ajv) | `Validator` (opis/json-schema, draft 2020-12, full schema) |
| `tutor.ts` (`TutorEngine`, `requestTutorTurn`) | `Tutor` (`requestTurn`, `callWithTimeout`) |
| `state-machine.ts` | `DialogSession` (+ `toArray`/`fromArray`, `drawSequence`) |
| `LLMProvider` / `MockProvider` | `App\Services\Llm\LlmClient` / `FakeLlmClient` |
| `harness/cases.ts`, `run.ts` | `Harness\HarnessCases`, `Harness\HarnessRunner`, `php artisan tutor:harness` |
| string unions | enums `HelpLevel`, `Classification`, `TurnType`, `Phase`, `CloseReason`, `FailureType` |

## Divergences from the prototype materials

- `emails-m4.json`: `_meta.browser_safe_fields` no longer lists `id` (security review, 2026-11-13). Card ids encode the answer (`m4-p*` phishing, `m4-l*` legitimate), so they must not reach the browser; `TutorConversation::browserEmail` also drops `id` defensively. The prototype copy (`~/Developer/Tesi/prototipo/materiali/emails-m4.json`) is unchanged: re-apply this edit if the file is copied again.

## Where PHP forced a different mechanism (behaviour kept identical)

- **Regex**: PHP `/u` also turns on Unicode `\b \w \d`. JS regexes without the `u` flag keep their literal and are compiled by `JsRegex` (ASCII `\b \w \d`, Unicode `\s` as in JS, e.g. NBSP); regexes with `u` are written with `/u`. `trim` follows JS (Unicode whitespace + BOM). Invalid UTF-8 is scrubbed first.
- **JSON in the prompt**: `PromptBuilder::jsonStringify` = `JSON.stringify(x, null, 2)` (2-space indent, no `\/`, no `\uXXXX`). Verified byte-identical to the TS prompt for all 6 cards. Limit: an empty JSON object `{}` in a card would become `[]` (none in the bank).
- **Schema**: opis rejects a relative root `$id`; it is resolved against `https://tutor.invalid/` (no `$ref`, no effect on validation). The full schema is sent as `json_schema` and always re-validated locally. K1 `detail` texts are opis messages, not ajv ones.
- **Timeout**: PHP cannot abort a call. `timeout` (seconds, rounded up) goes to the LlmClient; an exception whose message says "timed out"/"timeout", or one raised after the deadline, counts as `timeout`; a reply that arrives after `timeoutMs` is discarded as a timeout (like `Promise.race`). Default 20 s, `effort => 'low'`.
- **Fake "hang"** throws an LlmException "timed out" immediately instead of waiting.
- Only `\Exception` from the client becomes `provider_error`; PHP `\Error` (bugs) is not swallowed.

## Use from the platform

```php
$m = new Materials();
$engine = Tutor::fromMaterials(app(LlmClient::class), $m, config('tutor.timeout_ms'));
$session = new DialogSession($card, $position, module3Guidelines: $g, styleCard: $style, previousMissedClues: $missed);
$step = $session->step($engine, $text, 'phishing'); // InputRejectedException if the input does not pass the gate
// $step->shown: messages to show; $step->closing: verdict (from the card), found/missed items, message
// $session->events: log (Tabella 10); $session->missedMainLabels(): input for the next email and Module 5
$state = $session->toArray();  // JSON-plain, card referenced by id only (no solution data stored)
$session = DialogSession::fromArray($state, Materials::findCard($m->loadEmailBank(), $state['card_id']));
$draw = DialogSession::drawSequence($bank['_meta']); // ['seed', 'sequence', 'legitimate_position'] for the log
```

Harness: `php artisan tutor:harness --provider=fake` (no network) writes `storage/app/tutor-reports/harness-<provider>-<timestamp>.md`; `--provider=llm --runs=3` uses the container LlmClient (real calls, real cost).
