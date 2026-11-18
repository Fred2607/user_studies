<?php

namespace Tests\Unit\Training;

use App\Services\Training\DefenseDiagram;
use App\Services\Training\ScenarioEmail;
use PHPUnit\Framework\TestCase;

class DefenseDiagramTest extends TestCase
{
    private static function email(): ScenarioEmail
    {
        return new ScenarioEmail(array_replace(InteractiveFixtures::email(), [
            'body_html' => '<p>Dear {{first_name}},</p><p>Your mailbox password expires in 2 hours.</p>'
                . '<p>Please confirm your password with the link below.</p>'
                . '<p><a data-link-id="l1" href="https://company-helpdesk.net/reset">Keep my password</a></p><p>IT Service Desk</p>',
        ]), 'Alice');
    }

    public function test_each_guideline_gets_a_marker_on_its_zone_and_a_numbered_text(): void
    {
        $guidelines = array_merge(InteractiveFixtures::guidelines(), [
            ['id' => 'g5', 'text' => 'Never send your password by email or web form.', 'email_part' => 'request'],
            ['id' => 'g6', 'text' => 'Do not open unexpected attachments.', 'email_part' => 'attachment'],  // none in the email
        ]);
        $diagram = DefenseDiagram::build($guidelines, self::email());
        $svg = $diagram['svg'];

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($diagram['items'], 'number'));
        $this->assertSame(['Sender', 'Link', 'Tone', 'General', 'Request', 'Attachment'], array_column($diagram['items'], 'part_label'));
        $this->assertSame(640, $svg['width']);
        $this->assertGreaterThanOrEqual(DefenseDiagram::MIN_HEIGHT, $svg['height']);
        $this->assertCount(6, $svg['items']);
        $this->assertNotNull($svg['general']);  // g4 "none" and g6 (no attachment in the email)

        $rows = array_column($svg['rows'], 'text');
        $this->assertContains('I', $rows);  // avatar initial of "IT Service Desk"
        $this->assertContains('Dear Alice,', $rows);  // greeting, placeholder filled
        $this->assertContains('IT Service Desk', $rows);  // signature
        $this->assertContains('Keep my password', $rows);  // link row

        $y = fn (int $n) => $svg['items'][$n - 1]['marker']['y'];
        // Body rows are truncated to the card width: looked up by prefix.
        $rowY = fn (string $prefix) => collect($svg['rows'])->first(fn ($r) => str_starts_with($r['text'], $prefix))['y'];
        $this->assertSame($rowY('Your mailbox password expires') - 4, $y(3));  // tone -> line with a deadline
        $this->assertSame($rowY('Please confirm your password') - 4, $y(5));  // request
        $this->assertSame($svg['items'][3]['marker']['y'], $y(6));  // both in the General box
        $this->assertNotSame($svg['items'][3]['marker']['x'], $svg['items'][5]['marker']['x']);  // side by side

        // Texts on the right, stacked without overlap, all inside the viewBox.
        $previousBottom = 0;
        foreach ($svg['items'] as $item) {
            $this->assertGreaterThan($previousBottom, $item['badge']['y'] - 10);
            $previousBottom = end($item['lines'])['y'];
            $this->assertLessThanOrEqual($svg['height'], $previousBottom);
            foreach ($item['lines'] as $line) {
                $this->assertLessThanOrEqual(40, mb_strlen($line['text']));
            }
        }
    }

    public function test_without_the_email_only_the_list_is_produced(): void
    {
        $diagram = DefenseDiagram::build(InteractiveFixtures::guidelines(), null);

        $this->assertNull($diagram['svg']);
        $this->assertCount(4, $diagram['items']);
    }
}
