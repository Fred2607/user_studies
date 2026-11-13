{{-- Module 4 tutor chat. Receives only the browser-safe projection of the email while its dialog is open. --}}
<div class="tutor-chat w-full max-w-3xl mx-auto text-left" data-tutor-completed="{{ $completed ? 'true' : 'false' }}">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-xl font-bold">Exercise {{ $position }} of {{ $total }}</h3>
        <span class="text-sm text-gray-600">Is this email phishing or legitimate? Explain why.</span>
    </div>

    @if (! empty($email['context']))
        <p class="mb-3 text-base text-gray-700 italic">{{ $email['context'] }}</p>
    @endif

    {{-- The email, as a mail client would show it. Links are not followed; hovering/focusing shows the real address. --}}
    <article aria-label="Email to analyse" class="border border-gray-300 rounded-xl overflow-hidden mb-6 bg-white"
             x-data="{ hover: null }"
             x-on:click="if ($event.target.closest('a')) { $event.preventDefault() }"
             x-on:mouseover="hover = $event.target.closest('a')?.getAttribute('href') ?? null"
             x-on:focusin="hover = $event.target.closest('a')?.getAttribute('href') ?? null"
             x-on:mouseout="hover = null">
        <dl class="bg-gray-50 border-b border-gray-200 px-4 py-3 text-sm grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1">
            <dt class="text-gray-500">From</dt>
            <dd><span class="font-semibold">{{ $email['sender_name'] ?? '' }}</span> &lt;{{ $email['sender_address'] ?? '' }}&gt;</dd>
            @if (! empty($email['reply_to']))
                <dt class="text-gray-500">Reply-to</dt>
                <dd>{{ $email['reply_to'] }}</dd>
            @endif
            <dt class="text-gray-500">To</dt>
            <dd>{{ $email['recipient']['name'] ?? '' }} &lt;{{ $email['recipient']['address'] ?? '' }}&gt;</dd>
            @if ($emailDate)
                <dt class="text-gray-500">Date</dt>
                <dd>{{ $emailDate }}</dd>
            @endif
            <dt class="text-gray-500">Subject</dt>
            <dd class="font-semibold">{{ $email['subject'] ?? '' }}</dd>
        </dl>
        <div class="px-4 py-4 overflow-x-auto">
            {{-- Authored bank content (resources/tutor/emails-m4.json), not user input. --}}
            {!! $email['body_html'] ?? '' !!}
        </div>
        @if (! empty($email['attachments']))
            <ul class="px-4 pb-3 text-sm flex flex-wrap gap-2" aria-label="Attachments">
                @foreach ($email['attachments'] as $attachment)
                    <li class="border border-gray-300 rounded px-2 py-1 bg-gray-50">
                        &#128206; {{ is_array($attachment) ? ($attachment['filename'] ?? '') : $attachment }}
                        @if (is_array($attachment) && isset($attachment['size_kb']))
                            <span class="text-gray-500">({{ $attachment['size_kb'] }} KB)</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="px-4 py-1 text-xs bg-gray-100 border-t border-gray-200 text-gray-700 font-mono truncate" aria-hidden="true"
             x-show="hover" x-text="hover" style="display: none"></div>
    </article>

    {{-- Conversation with the tutor. --}}
    <section aria-label="Conversation with the tutor" class="mb-4">
        <ol class="space-y-3" role="log" aria-live="polite" aria-relevant="additions">
            @foreach ($transcript as $turn)
                @if ($turn['author'] === 'user')
                    <li class="flex justify-end">
                        <div class="max-w-[80%] rounded-2xl rounded-br-sm bg-blue-600 text-white px-4 py-2">
                            <span class="sr-only">You:</span>
                            @if ($turn['classification'])
                                <span class="block text-xs uppercase tracking-wide opacity-80">{{ $turn['classification'] === 'phishing' ? 'Phishing' : 'Legitimate' }}</span>
                            @endif
                            {!! nl2br(e($turn['text'])) !!}
                        </div>
                    </li>
                @else
                    <li class="flex justify-start">
                        <div class="max-w-[80%] rounded-2xl rounded-bl-sm bg-gray-100 text-gray-900 px-4 py-2">
                            <span class="block text-xs font-semibold text-gray-500">Tutor</span>
                            {!! nl2br(e($turn['text'])) !!}
                        </div>
                    </li>
                @endif
            @endforeach
        </ol>
    </section>

    @if ($closing)
        {{-- Closing: verdict from the card, tutor feedback, annotated clues (shown only after the dialog is closed). --}}
        <section aria-label="Solution of this email" aria-live="polite" class="border-2 rounded-xl p-4 mb-4 {{ $closing['verdict'] === 'Phishing' ? 'border-red-300 bg-red-50' : 'border-green-300 bg-green-50' }}">
            <p class="text-lg font-bold mb-1">This email is {{ $closing['verdict'] === 'Phishing' ? 'a phishing email' : 'legitimate' }}.</p>
            <p class="mb-3 text-sm">{{ $closing['correct'] ? 'Your final answer was correct.' : 'Your final answer was not correct.' }}</p>
            <div class="mb-4 rounded-lg bg-white px-4 py-2">
                <span class="block text-xs font-semibold text-gray-500">Tutor</span>
                {!! nl2br(e($closing['message'])) !!}
            </div>
            @foreach (['found' => 'What you noticed', 'missed' => 'What you missed'] as $group => $title)
                @if (! empty($closing[$group]))
                    <h4 class="font-semibold mt-2 mb-1">{{ $title }}</h4>
                    <ul class="space-y-2 mb-2">
                        @foreach ($closing[$group] as $item)
                            <li class="rounded-lg bg-white px-3 py-2 text-sm">
                                <span aria-hidden="true">{{ $group === 'found' ? '✓' : '•' }}</span>
                                <span class="font-semibold">{{ $item['label'] }}</span>
                                @if ($item['field'])
                                    <span class="text-gray-500">— {{ $fieldLabels[$item['field']] ?? $item['field'] }}@if ($item['text']): <mark class="bg-yellow-200">{{ $item['text'] }}</mark>@endif</span>
                                @endif
                                <p class="text-gray-700 mt-1">{{ $item['explanation'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach

            @if ($completed)
                <p class="mt-4 font-semibold" role="status">You have completed all the exercises. You can continue with the training.</p>
            @elseif (! $isLast)
                <div class="mt-4 text-right">
                    <button type="button" wire:click="nextEmail" wire:loading.attr="disabled"
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-400 disabled:opacity-50">
                        Next email
                    </button>
                </div>
            @endif
        </section>
    @endif

    @if ($notice)
        <p class="mb-3 rounded-md bg-yellow-50 border border-yellow-300 px-3 py-2 text-sm" role="status" aria-live="polite">{{ $notice }}</p>
    @endif

    @if ($accepting)
        <form wire:submit.prevent="send" class="space-y-3" aria-label="Your answer">
            <fieldset>
                <legend class="font-semibold mb-1">Your classification</legend>
                <div class="flex gap-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tutor-choice" value="phishing" wire:model="choice" class="text-blue-600">
                        <span>Phishing</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tutor-choice" value="legitimate" wire:model="choice" class="text-blue-600">
                        <span>Legitimate</span>
                    </label>
                </div>
            </fieldset>
            <div>
                <label for="tutor-message" class="font-semibold block mb-1">
                    {{ $awaitingFirst ? 'Why? Explain your reasoning' : 'Your reply' }}
                </label>
                <textarea id="tutor-message" wire:model.defer="message" rows="3" maxlength="{{ $maxChars }}"
                          aria-describedby="tutor-message-help @error('message') tutor-message-error @enderror"
                          class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-400 focus:ring focus:ring-blue-200"></textarea>
                <p id="tutor-message-help" class="text-xs text-gray-500">
                    {{ $awaitingFirst ? 'At least 4 words. It is fine to be unsure.' : 'Answer the tutor, or say what else you notice.' }}
                </p>
                @error('message')
                    <p id="tutor-message-error" class="text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-center justify-end gap-3">
                <span wire:loading wire:target="send" class="text-sm text-gray-500" role="status">The tutor is thinking…</span>
                <button type="submit" wire:loading.attr="disabled" wire:target="send"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-400 disabled:opacity-50">
                    Send
                </button>
            </div>
        </form>
    @endif
</div>
