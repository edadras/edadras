<?php

namespace App\Support;

use Throwable;

/**
 * Makes Persian and Arabic readable in a PDF.
 *
 * DomPDF draws glyphs exactly as they arrive: no contextual joining, no
 * right-to-left reordering. Persian written straight into a PDF therefore
 * comes out as disconnected letters running the wrong way — which is what an
 * invoice looked like before this. Running the text through the shaper first
 * substitutes the joined forms and reverses the runs, so the PDF shows what
 * the screen shows.
 *
 * Only PDF output needs this. Everywhere else — the apps, the panel, the API
 * — the renderer does its own shaping and must be left alone.
 */
class PersianText
{
    protected static ?PersianShaper $shaper = null;

    /** True when the string contains anything in the Arabic script. */
    public static function isRtl(?string $text): bool
    {
        return PersianShaper::isRtl($text);
    }

    /** Shapes one string for the PDF, leaving Latin text untouched. */
    public static function shape(?string $text): string
    {
        if (blank($text) || ! self::isRtl($text)) {
            return (string) $text;
        }

        try {
            return self::shaper()->shape($text);
        } catch (Throwable) {
            // A shaping failure must not cost the club its invoice.
            return $text;
        }
    }

    protected static function shaper(): PersianShaper
    {
        return self::$shaper ??= new PersianShaper;
    }
}
