<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Services\MembershipService;
use App\Support\PersianShaper;
use App\Support\PersianText;
use Tests\ClubTestCase;

/** Persian has to be readable on the one page a club prints. */
class PersianPdfTest extends ClubTestCase
{
    protected PersianShaper $shaper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shaper = new PersianShaper;
    }

    public function test_letters_are_joined_into_their_contextual_forms(): void
    {
        $shaped = $this->shaper->shape('باشگاه');

        // Beh opens the word, so it takes its initial form.
        $this->assertStringContainsString("\u{FE91}", $shaped, 'beh should take its initial form');
        // Gaf sits between two letters that both join, so it is medial.
        $this->assertStringContainsString("\u{FB95}", $shaped, 'gaf should take its medial form');
        // The heh follows an alef, which never joins forward, so it stays
        // isolated — the join is genuinely broken there in Persian.
        $this->assertStringContainsString("\u{FEE9}", $shaped, 'heh after an alef stays isolated');
        $this->assertStringNotContainsString('ب', $shaped, 'no unshaped letters should survive');
    }

    /** The six letters the general Arabic libraries leave untouched. */
    public function test_the_persian_only_letters_are_shaped(): void
    {
        foreach (['پ' => "\u{FB57}", 'چ' => "\u{FB7B}", 'ژ' => "\u{FB8B}", 'ک' => "\u{FB8F}", 'گ' => "\u{FB93}", 'ی' => "\u{FBFD}"] as $letter => $finalForm) {
            $shaped = $this->shaper->shape("ب{$letter}");

            $this->assertStringContainsString(
                $finalForm,
                $shaped,
                "{$letter} should take its final form after a beh"
            );
        }
    }

    public function test_lam_alef_becomes_one_ligature(): void
    {
        $shaped = $this->shaper->shape('لاله');

        $this->assertStringContainsString("\u{FEFB}", $shaped, 'lam-alef should be a single glyph');
        // Four letters in, three glyphs out: the ligature swallowed one.
        $this->assertSame(3, mb_strlen($shaped));
    }

    public function test_the_line_is_reversed_for_a_renderer_that_draws_left_to_right(): void
    {
        $shaped = $this->shaper->shape('جمع جزء');

        // The last word of the source becomes the first thing drawn.
        $this->assertStringStartsWith("\u{FE80}", $shaped, 'the hamza ending جزء should be drawn first');
    }

    public function test_numbers_inside_persian_still_read_forwards(): void
    {
        $shaped = $this->shaper->shape('کیف پول ۱۵ تومان');

        $this->assertStringContainsString('۱۵', $shaped);
        $this->assertStringNotContainsString('۵۱', $shaped, 'digits must not be reversed');
    }

    public function test_latin_runs_keep_their_order_and_their_spacing(): void
    {
        $shaped = $this->shaper->shape('INV-000001 باشگاه');

        $this->assertStringContainsString('INV-000001', $shaped);
        $this->assertStringEndsWith(' INV-000001', $shaped, 'the gap belongs between the words');
    }

    public function test_brackets_point_the_other_way_in_a_persian_line(): void
    {
        $this->assertStringContainsString('(IRR)', $this->shaper->shape('جمع (IRR)'));
    }

    public function test_latin_only_text_is_left_exactly_as_it_is(): void
    {
        $this->assertSame('Total (USD) 1,500', PersianText::shape('Total (USD) 1,500'));
        $this->assertSame('', PersianText::shape(null));
    }

    public function test_an_invoice_pdf_renders_with_persian_content(): void
    {
        app()->setLocale('fa');

        $member = Member::create([
            'code' => '9201',
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'phone' => '09130057947',
        ]);

        app(MembershipService::class)->sell($member, $this->plan('duration'));
        $invoice = $member->invoices()->firstOrFail();

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->get("/api/v1/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
