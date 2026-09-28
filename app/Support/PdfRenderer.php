<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a Blade view to an A4 PDF. Remote resources are off: images
 * (the logo) are embedded as data URIs by the views themselves.
 */
final class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view($view, $data)->render());
        $pdf->setPaper('A4', $orientation);
        $pdf->render();

        // "1 / 3" in the bottom-right corner; CSS can't count pages in dompdf.
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $width = $pdf->getFontMetrics()->getTextWidth('99 / 99', $font, 6.5);
        $canvas->page_text($canvas->get_width() - 40 - $width, $canvas->get_height() - 45, '{PAGE_NUM} / {PAGE_COUNT}', $font, 6.5, [0.54, 0.53, 0.63]);

        return (string) $pdf->output();
    }

    /**
     * "12,345.67 EUR"; a negative amount gets a real minus sign.
     */
    public static function money(BigDecimal|string|int|float|null $amount, ?string $currency = null): string
    {
        $value = BigDecimal::of((string) ($amount ?? 0))->toScale(2, RoundingMode::HalfUp);
        [$int, $fraction] = explode('.', (string) $value->abs());
        $text = strrev(implode(',', str_split(strrev($int), 3))).'.'.$fraction;

        return ($value->isNegative() ? '−' : '').$text.($currency ? ' '.$currency : '');
    }

    /**
     * "3.6%" — trailing zeros dropped.
     */
    public static function percent(BigDecimal|string|int|float|null $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? 0))->toScale(4, RoundingMode::HalfUp)->strippedOfTrailingZeros().'%';
    }
}
