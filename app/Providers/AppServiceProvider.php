<?php

namespace App\Providers;

use App\Services\Llm\AnthropicClient;
use App\Services\Llm\LlmClient;
use App\Services\Llm\OpenAiClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Training generation LLM, chosen by TRAINING_LLM_PROVIDER (default "openai" = paper setup).
        $this->app->bind(LlmClient::class, function () {
            $timeout = (int) config('services.llm.timeout', 180);

            if (config('services.llm.training_provider') === 'anthropic') {
                return new AnthropicClient(
                    apiKey: (string) config('services.anthropic.key'),
                    model: (string) config('services.anthropic.model', 'claude-opus-5-5'),
                    effort: (string) config('services.anthropic.effort', 'medium'),
                    timeout: $timeout,
                    maxTokens: (int) config('services.anthropic.max_tokens', 16000),
                    maxRetries: (int) config('services.anthropic.max_retries', 2),
                );
            }

            return new OpenAiClient(
                apiKey: (string) config('services.openai.key'),
                model: (string) config('services.openai.model', 'o3-mini'),
                reasoningEffort: (string) config('services.openai.reasoning_effort', 'medium'),
                timeout: $timeout,
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
