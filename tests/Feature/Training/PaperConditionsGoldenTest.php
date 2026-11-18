<?php

namespace Tests\Feature\Training;

use App\Http\Controllers\TrainingController;
use App\Models\Training;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Llm\ProcessTrainingTest;
use Tests\TestCase;

/**
 * The training page of the paper's conditions must stay byte-identical to the one rendered before the
 * interactive modules were added. The golden files in tests/fixtures/pages/ were captured on 6fbb5d7
 * (feat/interactive-training, before lot C2); only the per-request CSRF token is normalised.
 * Regenerate (only after an intentional change to the paper path) with UPDATE_GOLDEN=1.
 */
class PaperConditionsGoldenTest extends TestCase
{
    use RefreshDatabase;

    /** Section HTML exercising every rule of TrainingController::addCSS (headings, p, div, button, lists, script). */
    public const SECTIONS = [
        'introduction' => '<div><h1>Welcome [User Name]</h1><p>Intro &amp; text with &quot;entities&quot; and à è.</p><ul><li>One</li><li>Two</li></ul></div>',
        'scenario' => '<div><h2>Scenario</h2><h3>Email</h3><p>From: <a href="https://example.test/x">link</a></p>'
            . '<div data-role="decision-point"><form id="decisionForm"><label><input type="radio" name="d" value="click"> Click</label>'
            . '<button type="submit">Submit</button></form><div id="feedback"></div></div>'
            . '<script>document.getElementById("decisionForm").addEventListener("submit", function(e){e.preventDefault();});</script></div>',
        'defense_strategies' => '<div><h2>Strategies</h2><ol><li>Check the sender</li><li>Hover links</li></ol><p>Text.</p></div>',
        'exercises' => '<div><h2>Exercises</h2><h3>Email 1</h3><button onclick="check()">Check</button><p>Body</p></div>',
        'conclusions' => '<div><h2>Recap</h2><p>Thanks.</p></div>',
    ];

    public function test_paper_conditions_page_is_byte_identical_to_the_golden_file(): void
    {
        foreach (['no', 'yes', 'primed', 'few_shot', 'table'] as $personalization) {
            $user = ProcessTrainingTest::participant($personalization);
            Training::create(['user_id' => $user->id, 'generated' => true] + self::SECTIONS);
            $this->actingAs($user->fresh());

            $html = str_replace(csrf_token(), 'CSRF', app(TrainingController::class)->showTraining()->render());
            $file = base_path("tests/fixtures/pages/training-show-{$personalization}.html");
            if (getenv('UPDATE_GOLDEN') === '1') {
                @mkdir(dirname($file), 0777, true);
                file_put_contents($file, $html);
            }
            $this->assertFileExists($file);
            $this->assertSame(file_get_contents($file), $html, "Paper condition '$personalization' page changed");
        }
    }
}
