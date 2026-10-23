<?php

namespace Tests\Unit;

use App\Support\Utf8Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Utf8TextTruncateTest extends TestCase
{
    public function test_short_text_unchanged(): void
    {
        $this->assertSame('Hello world.', Utf8Text::truncateAtWordBoundary('Hello world.', 180));
    }

    public function test_english_does_not_cut_mid_word(): void
    {
        $src = 'Consolidated shipping means holding multiple China-domestic parcels at a warehouse, then packing them into one international carton. Typical levers: remove excess retail boxes, trim oversized cartons, and combine lines before export.';
        $out = Utf8Text::truncateAtWordBoundary($src, 180);
        $this->assertLessThanOrEqual(180, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
        $this->assertDoesNotMatchRegularExpression('/\btri…$/u', $out);
        $this->assertStringEndsWith('boxes…', $out);
    }

    public function test_chinese_truncates_on_character_boundary(): void
    {
        $src = '合箱寄送是指把多件国内包裹先存在仓库，再打成一个国际纸箱出口。常见手段包括去掉多余零售盒、裁切过大纸箱，以及合并线路后再出口。';
        $out = Utf8Text::truncateAtWordBoundary($src, 40);
        $this->assertLessThanOrEqual(40, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
        $this->assertGreaterThan(0, mb_strlen(rtrim($out, '…')));
    }

    public function test_empty_input(): void
    {
        $this->assertSame('', Utf8Text::truncateAtWordBoundary('   ', 20));
    }

    public static function midWordFixtures(): array
    {
        return [
            'tri' => [
                'Consolidated shipping means holding multiple China-domestic parcels at a warehouse, then packing them into one international carton. Typical levers: remove excess retail boxes, trim oversized cartons',
                180,
                'tri',
            ],
            'wit' => [
                'By Fetch China team Quick Answer: Fetch China is a China shopping proxy for Taobao and Xianyu also known internationally as Goofish . You create a free account, paste a listing with your options',
                180,
                'wit',
            ],
        ];
    }

    #[DataProvider('midWordFixtures')]
    public function test_known_mid_word_cases(string $src, int $limit, string $forbiddenStub): void
    {
        $out = Utf8Text::truncateAtWordBoundary($src, $limit);
        $this->assertStringEndsWith('…', $out);
        $this->assertStringNotContainsString($forbiddenStub.'…', $out);
        $this->assertDoesNotMatchRegularExpression('/'.$forbiddenStub.'$/u', rtrim($out, '…'));
    }
}
