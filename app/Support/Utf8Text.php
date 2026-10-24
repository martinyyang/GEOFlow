<?php

namespace App\Support;

final class Utf8Text
{
    /**
     * Truncate UTF-8 text on a word boundary (English) or character boundary (CJK),
     * appending an ellipsis when the source is longer than the limit.
     *
     * Never cuts mid Latin/Cyrillic word. Chinese/Japanese/Korean characters are
     * treated as standalone tokens, so a hard character cut is safe for CJK.
     */
    public static function truncateAtWordBoundary(string $text, int $limit, string $ellipsis = '…'): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? $text);
        if ($text === '' || $limit <= 0) {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $ellipsisLen = mb_strlen($ellipsis, 'UTF-8');
        $budget = max(1, $limit - $ellipsisLen);
        $slice = mb_substr($text, 0, $budget, 'UTF-8');

        // If we landed mid Latin/Cyrillic token, walk back to the prior boundary.
        if (self::endsInsideLatinWord($slice, $text, $budget)) {
            $boundary = self::lastWordBoundaryOffset($slice);
            if ($boundary > 0) {
                $slice = rtrim(mb_substr($slice, 0, $boundary, 'UTF-8'));
            } else {
                // Single oversized token: hard cut at budget (better than empty).
                $slice = mb_substr($text, 0, $budget, 'UTF-8');
            }
        }

        $slice = rtrim($slice, " \t\n\r\0\x0B,;:");

        if ($slice === '') {
            $slice = mb_substr($text, 0, $budget, 'UTF-8');
        }

        return $slice.$ellipsis;
    }

    private static function endsInsideLatinWord(string $slice, string $full, int $budget): bool
    {
        $last = mb_substr($slice, -1, 1, 'UTF-8');
        if ($last === '' || ! self::isLatinWordChar($last)) {
            return false;
        }

        $next = mb_substr($full, $budget, 1, 'UTF-8');

        // Mid-word when the next source char continues the same Latin token.
        return $next !== '' && self::isLatinWordChar($next);
    }

    private static function lastWordBoundaryOffset(string $slice): int
    {
        $len = mb_strlen($slice, 'UTF-8');
        for ($i = $len - 1; $i >= 0; $i--) {
            $ch = mb_substr($slice, $i, 1, 'UTF-8');
            if (! self::isLatinWordChar($ch)) {
                return $i + 1;
            }
        }

        return 0;
    }

    private static function isLatinWordChar(string $ch): bool
    {
        return preg_match('/^[A-Za-z0-9\'’]$/u', $ch) === 1;
    }
}
