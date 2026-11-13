<?php

namespace Tests\Feature\Tutor;

use App\Http\Controllers\TrainingController;
use App\Models\Training;
use App\Models\TutorSession;
use App\Services\Tutor\FakeLlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Llm\ProcessTrainingTest;
use Tests\TestCase;

/** Training page of the "interactive" condition: Module 4 mounts the tutor chat and gates "Next" on its completion. */
class InteractiveTrainingPageTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDELINES = [
        ['id' => 'g1', 'text' => 'Check the domain after the @ in the sender address', 'email_part' => 'sender'],
        ['id' => 'g2', 'text' => 'Hover over links before clicking them', 'email_part' => 'link'],
    ];

    private function render(string $personalization, array $interactiveData = []): array
    {
        $this->app->instance('tutor.llm', new FakeLlmClient([])); // rendering never calls the model
        $user = ProcessTrainingTest::participant($personalization);
        Training::create([
            'user_id' => $user->id,
            'generated' => true,
            'introduction' => '<p>Intro</p>',
            'scenario' => '<p>Scenario</p>',
            'defense_strategies' => '<p>Strategies</p>',
            'exercises' => '<p>Exercises</p>',
            'conclusions' => '<p>Recap</p>',
            'interactive_data' => $interactiveData ?: null,
        ]);
        $this->actingAs($user = $user->fresh());

        return [$user, app(TrainingController::class)->showTraining()->render()];
    }

    public function test_interactive_page_mounts_the_tutor_with_guidelines_and_gates_next(): void
    {
        $sequence = ['m4-p1-icloud', 'm4-p2-airbnb', 'm4-l1-airbnb', 'm4-p3-coworker'];
        [$user, $html] = $this->render('interactive', ['defense_guidelines' => self::GUIDELINES, 'm4' => ['sequence' => $sequence, 'seed' => 7]]);

        // Chat mounted inside Module 4, not completed yet.
        $this->assertStringContainsString('wire:id', $html);
        $this->assertStringContainsString('Exercise 1 of 4', $html);
        $this->assertStringContainsString('data-tutor-completed="false"', $html);

        // Guidelines of the generated training passed as they are to the dialog (prompt input).
        $session = TutorSession::where('user_id', $user->id)->sole();
        $this->assertSame($sequence, $session->sequence);
        $this->assertSame(7, $session->seed);
        $this->assertEquals(self::GUIDELINES, $session->state['module3_guidelines']);

        // "Next" gated on Module 4 until tutor-completed (event or data attribute after a reload).
        $this->assertStringContainsString('x-on:tutor-completed.window="tutorDone = true"', $html);
        $this->assertStringContainsString(':disabled="step === 4 && !tutorDone"', $html);
        $this->assertStringContainsString('if (this.step === 4 && !this.tutorDone)', $html);
        $this->assertStringContainsString('document.querySelector(\'[data-tutor-completed="true"]\')', $html);
    }

    public function test_completed_module_is_flagged_on_reload(): void
    {
        [$user] = $this->render('interactive', ['defense_guidelines' => self::GUIDELINES]);
        TutorSession::where('user_id', $user->id)->update(['completed_at' => now()]);

        $html = app(TrainingController::class)->showTraining()->render();
        $this->assertStringContainsString('data-tutor-completed="true"', $html);
    }

    public function test_paper_conditions_have_no_tutor_and_no_gate(): void
    {
        foreach (['yes', 'no'] as $personalization) {
            [, $html] = $this->render($personalization);
            $this->assertStringNotContainsString('tutorDone', $html);
            $this->assertStringNotContainsString('data-tutor-completed', $html);
        }
        $this->assertSame(0, TutorSession::count());
    }
}
