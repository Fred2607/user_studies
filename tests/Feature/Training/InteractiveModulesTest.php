<?php

namespace Tests\Feature\Training;

use App\Http\Controllers\TrainingController;
use App\Http\Livewire\ScenarioClues;
use App\Http\Livewire\ScenarioQuestion;
use App\Models\Training;
use App\Models\TrainingResponse;
use App\Models\User;
use App\Services\Tutor\FakeLlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Feature\Llm\ProcessTrainingTest;
use Tests\TestCase;
use Tests\Unit\Training\InteractiveFixtures;

/** Modules 1, 2 and 3 of the interactive condition (lot C2): rendering, saving, gating, ownership, fallbacks. */
class InteractiveModulesTest extends TestCase
{
    use RefreshDatabase;

    private const SCENARIO = '<div><p>Imagine you are at work, {{first_name}}.</p>'
        . '<div data-role="decision-point"><p>What would you do?</p>'
        . '<label><input type="radio" name="d" value="click"> Click the link</label>'
        . '<label><input type="radio" name="d" value="report"> Report it to IT</label></div>'
        . '<div id="m2-feedback">Feedback area</div></div>';

    private static function data(array $overrides = []): array
    {
        return array_replace([
            'scenario_header' => InteractiveFixtures::header(),
            'scenario_email' => InteractiveFixtures::email(),
            'defense_guidelines' => InteractiveFixtures::guidelines(),
            'm4' => ['sequence' => ['m4-p1-icloud', 'm4-p2-airbnb', 'm4-l1-airbnb', 'm4-p3-coworker'], 'seed' => 7],
        ], $overrides);
    }

    private function participant(?array $data = null, string $scenario = self::SCENARIO, string $personalization = 'interactive'): User
    {
        $this->app->instance('tutor.llm', new FakeLlmClient([]));  // the page mounts the tutor chat; never called
        $user = ProcessTrainingTest::participant($personalization);
        Training::create([
            'user_id' => $user->id,
            'generated' => true,
            'introduction' => '<div><p>Hello {{first_name}}.</p></div>',
            'scenario' => $scenario,
            'defense_strategies' => '<div><p>Why the strategies work.</p></div>',
            'exercises' => '<div><p>Exercises.</p></div>',
            'conclusions' => '<div><p>Recap.</p></div>',
            'interactive_data' => $data ?? self::data(),
        ]);

        return $user->fresh();
    }

    private function page(User $user): string
    {
        $this->actingAs($user);

        return app(TrainingController::class)->showTraining()->render();
    }

    private static function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        self::assertNotFalse($start, "Missing $from");

        return substr($html, $start, strpos($html, $to, $start) - $start);
    }

    // ---------------------------------------------------------------- Module 1

    public function test_m1_shows_the_scenario_header_and_the_fixed_question_after_the_intro(): void
    {
        $html = $this->page($this->participant(self::data(['scenario_header' => ['subject' => '<b>Action</b> required'] + InteractiveFixtures::header()])));
        $intro = self::between($html, 'id="section-intro"', 'Section 1 of 5');

        $this->assertStringContainsString('Hello Alice.', $intro);
        $this->assertLessThan(strpos($intro, 'You are at your desk'), strpos($intro, 'Hello Alice.'));
        foreach (['You are at your desk on Monday morning', 'IT Service Desk', 'it-support@company-helpdesk.net',
                     'Your mailbox password expires in 2 hours.', 'What would make you suspicious about this message?'] as $text) {
            $this->assertStringContainsString($text, $intro);
        }
        $this->assertStringContainsString('&lt;b&gt;Action&lt;/b&gt; required', $intro);  // model strings escaped
        $this->assertStringNotContainsString('<b>Action</b>', $intro);
        $this->assertStringContainsString('maxlength="1000"', $intro);
        $this->assertStringContainsString('data-m1-completed="false"', $intro);
        // Next gated on Module 1 until the answer is saved.
        $this->assertStringContainsString('x-on:m1-completed.window="m1Done = true"', $html);
        $this->assertStringContainsString('this.m1Done = document.querySelector(\'[data-m1-completed="false"]\') === null;', $html);
        $this->assertStringContainsString('return (this.step === 1 && !this.m1Done) || (this.step === 2 && !this.m2Done)', $html);
    }

    public function test_m1_answer_is_saved_once_per_user_and_reopens_as_completed(): void
    {
        $this->actingAs($user = $this->participant());

        Livewire::test(ScenarioQuestion::class)
            ->assertSet('saved', false)
            ->set('answer', '  The sender domain is not ours.  ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertDispatchedBrowserEvent('m1-completed');
        Livewire::test(ScenarioQuestion::class)
            ->assertSet('answer', 'The sender domain is not ours.')
            ->assertSet('saved', true)
            ->assertDispatchedBrowserEvent('m1-completed')
            ->set('answer', 'Also the deadline.')
            ->call('save');

        $row = TrainingResponse::where('user_id', $user->id)->where('module', 1)->sole();
        $this->assertSame('suspicion', $row->item_key);
        $this->assertSame('Also the deadline.', $row->response_text);
        $this->assertSame($user->training->id, $row->training_id);
        $this->assertStringContainsString('data-m1-completed="true"', $this->page($user));
    }

    public function test_m1_rejects_empty_and_too_long_answers(): void
    {
        $this->actingAs($this->participant());

        Livewire::test(ScenarioQuestion::class)->set('answer', "   \n ")->call('save')
            ->assertHasErrors(['answer' => 'required'])->assertSet('saved', false)->assertNotDispatchedBrowserEvent('m1-completed');
        Livewire::test(ScenarioQuestion::class)->set('answer', str_repeat('a', 1001))->call('save')
            ->assertHasErrors(['answer' => 'max']);
        $this->assertSame(0, TrainingResponse::count());
    }

    public function test_m1_writes_only_the_authenticated_users_answer(): void
    {
        $other = $this->participant();
        TrainingResponse::record($other->id, $other->training->id, 1, 'suspicion', 'Other answer');
        $this->actingAs($user = $this->participant());

        Livewire::test(ScenarioQuestion::class)
            ->assertSet('answer', '')  // the other user's answer is never loaded
            ->set('answer', 'Mine')->call('save');

        $this->assertSame('Other answer', TrainingResponse::where('user_id', $other->id)->sole()->response_text);
        $this->assertSame('Mine', TrainingResponse::where('user_id', $user->id)->sole()->response_text);
    }

    public function test_m1_requires_authentication(): void
    {
        Livewire::test(ScenarioQuestion::class)->assertForbidden();
    }

    public function test_m1_without_a_valid_header_is_not_shown_nor_gated(): void
    {
        $data = self::data(['scenario_header' => ['subject' => 'only a subject']]);
        $html = $this->page($user = $this->participant($data));

        $intro = self::between($html, 'id="section-intro"', 'Section 1 of 5');
        $this->assertStringNotContainsString('What would make you suspicious', $intro);
        $this->assertStringNotContainsString('data-m1-completed', $intro);  // the gate script finds no pending activity
        $this->actingAs($user);
        Livewire::test(ScenarioQuestion::class)->set('answer', 'x')->call('save')->assertForbidden();
        $this->assertSame(0, TrainingResponse::where('module', 1)->count());
    }

    // ---------------------------------------------------------------- Module 2

    public function test_m2_renders_the_email_with_the_first_clue_and_hides_the_decision_point(): void
    {
        $email = InteractiveFixtures::email();
        $email['body_html'] = '<p>Hi {{first_name}}, your mailbox password expires in 2 hours.</p><script>alert(1)</script>'
            . '<p onclick="x()"><a data-link-id="l1" href="https://company-helpdesk.net/reset">Keep my password</a></p>';
        $html = $this->page($this->participant(self::data(['scenario_email' => $email])));
        $section = self::between($html, 'id="section-scenario"', 'Section 2 of 5');

        // Scenario text, then the email, then the (hidden) decision point and what follows it.
        $this->assertLessThan(strpos($section, 'aria-label="Phishing scenario email"'), strpos($section, 'Imagine you are at work, Alice.'));
        $this->assertLessThan(strpos($section, 'data-role="decision-point"'), strpos($section, 'aria-label="Phishing scenario email"'));
        $this->assertStringContainsString('<div class="hidden" data-m2-decision', $section);
        $this->assertMatchesRegularExpression('/<div class="hidden">\s*<div wire:ignore><div class="mb-10"><div id="m2-feedback"/', $section);
        $this->assertStringContainsString('data-m2-completed="false"', $section);

        // Email fields; body sanitized, name filled, link not navigating.
        $this->assertStringContainsString('Hi Alice, your mailbox password', $section);
        $this->assertStringNotContainsString('alert(1)', $section);
        $this->assertStringNotContainsString('onclick', $section);
        $this->assertStringContainsString('data-href="https://company-helpdesk.net/reset"', $section);
        $this->assertStringNotContainsString('href="https://company-helpdesk.net/reset"', str_replace('data-href', '', $section));

        // Only the first clue (order 1) is highlighted; its explanation is not on the page yet.
        $this->assertStringContainsString('it-support@<mark data-clue="1" data-clue-state="current" data-clue-current="" role="button"', $section);
        $this->assertStringNotContainsString('data-clue="2"', $section);
        $this->assertStringNotContainsString('The domain is not the company one.', $section);
        $this->assertStringContainsString('Suspicious elements opened: 0 of 2', $section);
        $this->assertStringContainsString('x-on:m2-completed.window="m2Done = true"', $html);
    }

    public function test_m2_clues_open_in_order_then_the_decision_is_saved_and_completes_the_module(): void
    {
        $this->actingAs($user = $this->participant());

        $c = Livewire::test(ScenarioClues::class)->assertNotDispatchedBrowserEvent('m2-completed');
        // The decision cannot be answered before all clues are opened.
        $c->call('saveDecision', 1)->assertForbidden();
        $c = Livewire::test(ScenarioClues::class);

        $c->call('openNext')
            ->assertSee('The domain is not the company one.')
            ->assertDontSee('The link points outside the company.')
            ->assertSeeHtml('data-clue="2" data-clue-state="current"')
            ->assertSeeHtml('<div class="hidden" data-m2-decision')
            ->call('openNext')
            ->assertSee('The link points outside the company.')
            ->assertSee('You have opened all the suspicious elements.')
            ->assertDontSeeHtml('<div class="hidden" data-m2-decision')
            ->assertSeeHtml('data-m2-completed="false"')
            ->assertNotDispatchedBrowserEvent('m2-completed')
            ->call('openNext');  // nothing left: no-op

        $progress = TrainingResponse::where('user_id', $user->id)->where('module', 2)->where('item_key', 'clues')->sole()->response_json;
        $this->assertSame(['c1', 'c2'], $progress['opened']);
        $this->assertSame([], $progress['unlocated']);

        $c->call('saveDecision', 5)->assertStatus(422);
        $this->assertSame(0, TrainingResponse::where('item_key', 'decision')->count());
        $c = Livewire::test(ScenarioClues::class);
        $c->call('saveDecision', 1)->assertDispatchedBrowserEvent('m2-completed')->assertSee('Your answer has been saved.');
        $c->call('saveDecision', 0);

        $decision = TrainingResponse::where('user_id', $user->id)->where('module', 2)->where('item_key', 'decision')->sole();
        $this->assertSame('Click the link', $decision->response_text);  // label read by the server
        $this->assertSame(['index' => 1, 'value' => 'report'], array_intersect_key($decision->response_json['first'], ['index' => 0, 'value' => 0]));
        $this->assertSame(1, $decision->response_json['changes']);

        $this->assertStringContainsString('data-m2-completed="true"', $this->page($user));
    }

    public function test_m2_unlocated_clue_is_logged_once_and_shown_without_highlight(): void
    {
        $email = InteractiveFixtures::email();
        $email['clues'][0]['target']['text'] = 'not-in-the-address.example';
        Log::spy();
        $this->actingAs($user = $this->participant(self::data(['scenario_email' => $email])));

        Livewire::test(ScenarioClues::class)
            ->assertSee('Element 1 could not be highlighted in the email')
            ->assertDontSeeHtml('<mark')
            ->call('openNext')
            ->assertSee('The domain is not the company one.');
        Livewire::test(ScenarioClues::class);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'Module 2 clue target not found')
            && $context['clue_ids'] === ['c1'] && $context['user_id'] === $user->id);
        $progress = TrainingResponse::where('user_id', $user->id)->where('item_key', 'clues')->sole()->response_json;
        $this->assertSame(['c1'], $progress['unlocated']);
    }

    public function test_m2_without_radios_completes_when_all_clues_are_opened(): void
    {
        $this->actingAs($this->participant(null, '<div><p>Scenario.</p><div data-role="decision-point"><p>Think about it.</p></div></div>'));

        Livewire::test(ScenarioClues::class)->call('openNext')->assertNotDispatchedBrowserEvent('m2-completed')
            ->call('openNext')->assertDispatchedBrowserEvent('m2-completed')->assertSeeHtml('data-m2-completed="true"');
    }

    public function test_m2_progress_belongs_to_the_authenticated_user_only(): void
    {
        $other = $this->participant();
        TrainingResponse::record($other->id, $other->training->id, 2, 'clues', null, ['opened' => ['c1', 'c2'], 'opened_at' => [], 'unlocated' => []]);
        $this->actingAs($user = $this->participant());

        $c = Livewire::test(ScenarioClues::class)->assertSee('Suspicious elements opened: 0 of 2');
        $c->call('saveDecision', 0)->assertForbidden();  // the other user's opened clues do not unlock this user's decision
        $this->assertSame(0, TrainingResponse::where('item_key', 'decision')->count());
        $this->assertSame(['c1', 'c2'], TrainingResponse::where('user_id', $other->id)->sole()->response_json['opened']);
    }

    public function test_m2_with_invalid_email_data_falls_back_to_the_section_html(): void
    {
        $email = InteractiveFixtures::email();
        unset($email['clues']);
        $html = $this->page($this->participant(self::data(['scenario_email' => $email])));
        $section = self::between($html, 'id="section-scenario"', 'Section 2 of 5');

        $this->assertStringContainsString('Imagine you are at work, Alice.', $section);
        $this->assertStringContainsString('data-role="decision-point"', $section);
        $this->assertStringNotContainsString('data-m2-completed', $section);
        $this->assertStringNotContainsString('Phishing scenario email', $section);
        $this->assertStringNotContainsString('class="hidden"', $section);

        Livewire::test(ScenarioClues::class)->call('openNext')->assertForbidden();
        $this->assertSame(0, TrainingResponse::count());
    }

    // ---------------------------------------------------------------- Module 3

    public function test_m3_draws_the_diagram_with_escaped_guidelines_and_a_text_alternative(): void
    {
        $guidelines = InteractiveFixtures::guidelines();
        $guidelines[0]['text'] = 'Check the <script>domain</script> after the @';
        $html = $this->page($this->participant(self::data(['defense_guidelines' => $guidelines])));
        $section = self::between($html, 'id="section-strategies"', 'Section 3 of 5');

        $this->assertLessThan(strpos($section, '<figure'), strpos($section, 'Why the strategies work.'));
        $this->assertMatchesRegularExpression('/<svg viewBox="0 0 640 \d+" width="100%"/', $section);
        $this->assertStringContainsString('role="img" aria-labelledby="m3-diagram-title" aria-describedby="m3-guidelines"', $section);
        $this->assertStringNotContainsString('<script>domain', $section);
        $this->assertStringContainsString('&lt;script&gt;domain&lt;/script&gt;', $section);
        $this->assertStringContainsString('>General</text>', $section);  // g4 email_part "none"
        $this->assertSame(4 * 2, substr_count($section, 'r="10" fill="#1d4ed8"'));  // marker + badge per guideline
        $this->assertStringContainsString('<ol id="m3-guidelines" class="mt-4 md:sr-only', $section);
        $this->assertStringContainsString('Recognize a genuine email by its official domain. <span class="text-gray-600">(General)</span>', $section);
        $this->assertStringNotContainsString('<animate', $section);
    }

    public function test_m3_without_email_shows_only_the_list_and_without_guidelines_nothing(): void
    {
        $html = $this->page($this->participant(self::data(['scenario_email' => null])));
        $section = self::between($html, 'id="section-strategies"', 'Section 3 of 5');
        $this->assertStringNotContainsString('<svg', $section);
        $this->assertStringContainsString('<ol id="m3-guidelines" class=" space-y-2', $section);
        $this->assertStringContainsString('Hover over links and read the real address.', $section);

        $html = $this->page($this->participant(self::data(['defense_guidelines' => [['id' => 'g1', 'text' => 'x', 'email_part' => 'bogus']]])));
        $section = self::between($html, 'id="section-strategies"', 'Section 3 of 5');
        $this->assertStringNotContainsString('<figure', $section);
        $this->assertStringContainsString('Why the strategies work.', $section);
    }
}
