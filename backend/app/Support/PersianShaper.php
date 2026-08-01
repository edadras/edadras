<?php

namespace App\Support;

/**
 * Joins Persian and Arabic letters for PDF output.
 *
 * DomPDF draws the code points it is handed, in the order it is handed them.
 * Persian written straight into a PDF therefore comes out as isolated letters
 * running backwards. Two things have to happen first:
 *
 *  1. Each letter is swapped for its contextual presentation form — isolated,
 *     initial, medial or final — depending on whether its neighbours join.
 *  2. Right-to-left runs are reversed, while Latin and digits keep their own
 *     order, so "۱۵ کارت" does not come out with the number backwards.
 *
 * The general-purpose Arabic libraries shape the Arabic alphabet and leave
 * the six Persian-only letters — پ چ ژ ک گ ی — untouched, which is exactly
 * the set an Iranian club's invoice is full of. Hence this.
 */
class PersianShaper
{
    /**
     * codepoint => [isolated, final, initial, medial].
     * A null initial/medial marks a letter that never joins to its left.
     *
     * @var array<int, array{0:int, 1:int, 2:?int, 3:?int}>
     */
    protected const FORMS = [
        0x0621 => [0xFE80, null, null, null],       // ء
        0x0622 => [0xFE81, 0xFE82, null, null],     // آ
        0x0623 => [0xFE83, 0xFE84, null, null],     // أ
        0x0624 => [0xFE85, 0xFE86, null, null],     // ؤ
        0x0625 => [0xFE87, 0xFE88, null, null],     // إ
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], // ئ
        0x0627 => [0xFE8D, 0xFE8E, null, null],     // ا
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], // ب
        0x0629 => [0xFE93, 0xFE94, null, null],     // ة
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], // ت
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], // ث
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], // ج
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], // ح
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], // خ
        0x062F => [0xFEA9, 0xFEAA, null, null],     // د
        0x0630 => [0xFEAB, 0xFEAC, null, null],     // ذ
        0x0631 => [0xFEAD, 0xFEAE, null, null],     // ر
        0x0632 => [0xFEAF, 0xFEB0, null, null],     // ز
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], // س
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], // ش
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], // ص
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], // ض
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], // ط
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], // ظ
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], // ع
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0], // غ
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], // ف
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], // ق
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], // ك (Arabic kaf)
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], // ل
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], // م
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], // ن
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], // ه
        0x0648 => [0xFEED, 0xFEEE, null, null],     // و
        0x0649 => [0xFEEF, 0xFEF0, null, null],     // ى
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4], // ي (Arabic yeh)
        0x067E => [0xFB56, 0xFB57, 0xFB58, 0xFB59], // پ
        0x0686 => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D], // چ
        0x0698 => [0xFB8A, 0xFB8B, null, null],     // ژ
        0x06A9 => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91], // ک
        0x06AF => [0xFB92, 0xFB93, 0xFB94, 0xFB95], // گ
        0x06CC => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF], // ی
    ];

    /**
     * lam followed by one of these becomes a single ligature glyph. Writing
     * "لا" as two letters is wrong in print.
     *
     * @var array<int, array{0:int, 1:int}> alef => [isolated, final]
     */
    protected const LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
        0x0627 => [0xFEFB, 0xFEFC],
    ];

    /** Marks that sit on a letter and never break a join. */
    protected const TRANSPARENT = [
        0x064B, 0x064C, 0x064D, 0x064E, 0x064F, 0x0650, 0x0651, 0x0652,
        0x0653, 0x0654, 0x0655, 0x0670, 0x200C,
    ];

    public function shape(string $text): string
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $lines[] = $this->reorder($this->join($line));
        }

        return implode("\n", $lines);
    }

    /** True when the string contains anything in the Arabic script. */
    public static function isRtl(?string $text): bool
    {
        return filled($text) && preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1;
    }

    /** Swaps every letter for the presentation form its neighbours call for. */
    protected function join(string $line): string
    {
        $chars = $this->codepoints($line);
        $out = [];
        $count = count($chars);

        for ($i = 0; $i < $count; $i++) {
            $code = $chars[$i];

            if (! isset(self::FORMS[$code])) {
                $out[] = $code;

                continue;
            }

            $joinsBack = $this->joinsToPrevious($chars, $i);
            $next = $this->nextLetter($chars, $i);

            // Lam followed by an alef is one glyph, not two.
            if ($code === 0x0644 && $next !== null && isset(self::LAM_ALEF[$chars[$next]])) {
                [$isolated, $final] = self::LAM_ALEF[$chars[$next]];
                $out[] = $joinsBack ? $final : $isolated;
                $i = $next;

                continue;
            }

            $joinsForward = $next !== null
                && isset(self::FORMS[$chars[$next]])
                && self::FORMS[$code][2] !== null;

            $out[] = $this->form($code, $joinsBack, $joinsForward);
        }

        return $this->toUtf8($out);
    }

    protected function form(int $code, bool $joinsBack, bool $joinsForward): int
    {
        [$isolated, $final, $initial, $medial] = self::FORMS[$code];

        return match (true) {
            $joinsBack && $joinsForward => $medial ?? $final ?? $isolated,
            $joinsBack => $final ?? $isolated,
            $joinsForward => $initial ?? $isolated,
            default => $isolated,
        };
    }

    /**
     * A letter joins backwards when the letter before it — skipping marks —
     * is one that joins to its left.
     *
     * @param  array<int, int>  $chars
     */
    protected function joinsToPrevious(array $chars, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (in_array($chars[$i], self::TRANSPARENT, true)) {
                continue;
            }

            return isset(self::FORMS[$chars[$i]]) && self::FORMS[$chars[$i]][2] !== null;
        }

        return false;
    }

    /**
     * The next letter, skipping marks.
     *
     * @param  array<int, int>  $chars
     */
    protected function nextLetter(array $chars, int $index): ?int
    {
        for ($i = $index + 1, $count = count($chars); $i < $count; $i++) {
            if (! in_array($chars[$i], self::TRANSPARENT, true)) {
                return isset(self::FORMS[$chars[$i]]) ? $i : null;
            }
        }

        return null;
    }

    /**
     * Reverses the order of the runs so the line reads right to left, while
     * each Latin or numeric run keeps its own direction — a price, a phone
     * number or an invoice code embedded in Persian still reads forwards.
     *
     * Spaces and punctuation form runs of their own rather than being glued
     * to a neighbour, which is what keeps the gap in "INV-1 باشگاه" between
     * the two words instead of at the end of the line.
     */
    protected function reorder(string $line): string
    {
        $runs = [];
        $text = '';
        $direction = null;   // true rtl, false ltr, null neutral

        foreach ($this->split($line) as $char) {
            $charDirection = $this->direction($char);

            if ($text !== '' && $charDirection !== $direction) {
                $runs[] = [$direction, $text];
                $text = '';
            }

            $direction = $charDirection;
            $text .= $char;
        }

        if ($text !== '') {
            $runs[] = [$direction, $text];
        }

        $runs = $this->resolveNeutrals($runs);

        // Brackets and quotes point the other way once the line is flipped.
        $mirror = $this->isRtlLine($runs);

        return collect(array_reverse($runs))
            ->map(fn (array $run) => match ($run[0]) {
                true => $this->reverseChars($run[1]),
                false => $run[1],
                default => $mirror ? $this->mirror($this->reverseChars($run[1])) : $run[1],
            })
            ->implode('');
    }

    /**
     * Spaces and punctuation between two runs of the same direction belong to
     * that direction — otherwise "INV-000001" splits into "INV", "-" and
     * "000001" and comes out reversed as "000001-INV". Anything left over
     * stays neutral and follows the line.
     *
     * @param  array<int, array{0:?bool, 1:string}>  $runs
     * @return array<int, array{0:?bool, 1:string}>
     */
    protected function resolveNeutrals(array $runs): array
    {
        $count = count($runs);

        for ($i = 0; $i < $count; $i++) {
            if ($runs[$i][0] !== null) {
                continue;
            }

            $before = $i > 0 ? $runs[$i - 1][0] : null;
            $after = $i + 1 < $count ? $runs[$i + 1][0] : null;

            if ($before !== null && $before === $after) {
                $runs[$i][0] = $before;
            }
        }

        // Merge what now agrees, so a resolved run is reversed as one piece.
        $merged = [];

        foreach ($runs as $run) {
            $last = count($merged) - 1;

            if ($last >= 0 && $merged[$last][0] === $run[0]) {
                $merged[$last][1] .= $run[1];

                continue;
            }

            $merged[] = $run;
        }

        return $merged;
    }

    /** @param array<int, array{0:?bool, 1:string}> $runs */
    protected function isRtlLine(array $runs): bool
    {
        return collect($runs)->contains(fn (array $run) => $run[0] === true);
    }

    /** "(" opens on the right in a right-to-left line. */
    protected function mirror(string $run): string
    {
        return strtr($run, [
            '(' => ')', ')' => '(',
            '[' => ']', ']' => '[',
            '{' => '}', '}' => '{',
            '<' => '>', '>' => '<',
            '«' => '»', '»' => '«',
        ]);
    }

    /**
     * true for the Arabic script, false for anything that reads forwards —
     * Latin and every flavour of digit, including the Persian ones, which sit
     * inside the Arabic block but are numbers and must not be reversed — and
     * null for spaces and punctuation, which belong to neither.
     */
    protected function direction(string $char): ?bool
    {
        // Arabic-Indic (٠-٩) and Extended Arabic-Indic (۰-۹) digits.
        if (preg_match('/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $char)) {
            return false;
        }

        if (preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $char)) {
            return true;
        }

        if (preg_match('/[A-Za-z]/u', $char)) {
            return false;
        }

        return null;
    }

    protected function reverseChars(string $run): string
    {
        return implode('', array_reverse($this->split($run)));
    }

    /** @return array<int, string> */
    protected function split(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return array<int, int> */
    protected function codepoints(string $text): array
    {
        return array_map(
            fn (string $char) => (int) mb_ord($char, 'UTF-8'),
            $this->split($text)
        );
    }

    /** @param array<int, int> $codes */
    protected function toUtf8(array $codes): string
    {
        return implode('', array_map(fn (int $code) => mb_chr($code, 'UTF-8'), $codes));
    }
}
