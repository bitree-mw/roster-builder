<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders Blade views under resources/views/pdf to PDF files with dompdf (A4, landscape by default).
 *
 * Styling comes from resources/css/pdf/document.css. dompdf cannot resolve CSS custom properties, so every
 * var(--token) in that file is replaced with its value from resources/css/common/tokens.css — colours stay
 * defined in one place. Remote resources are disabled; the airline logo is embedded as a data URI. Every
 * page is numbered "Page X of Y" in the footer.
 */
class PdfService
{
    /**
     * Render a view to PDF bytes.
     *
     * @param  array<string, mixed>  $data  view data; "title" is used in the document header
     * @param  'landscape'|'portrait'  $orientation
     */
    public function render(string $view, array $data, string $orientation = 'landscape'): string
    {
        $tokens = $this->tokens();
        $html = view($view, [...$data, 'pdfStyles' => $this->stylesheet($tokens), 'logo' => $this->logo(), 'generatedAt' => now('UTC')->format('d M Y H:i').' UTC'])->render();
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(base_path());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();
        // Page numbers in the bottom-right corner, in the muted ink colour from the design tokens.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, $this->rgb($tokens['color-muted'] ?? '#56677a'));

        return $dompdf->output();
    }

    /**
     * The PDF stylesheet with var(--name) replaced by the token values.
     *
     * @param  array<string, string>  $tokens
     */
    private function stylesheet(array $tokens): string
    {
        $css = (string) file_get_contents(resource_path('css/pdf/document.css'));

        return preg_replace_callback('/var\(--([a-z0-9-]+)\)/i', fn (array $match): string => $tokens[$match[1]] ?? 'inherit', $css) ?? $css;
    }

    /**
     * Every custom property defined in tokens.css (name without the leading dashes => value).
     *
     * @return array<string, string>
     */
    private function tokens(): array
    {
        preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', (string) file_get_contents(resource_path('css/common/tokens.css')), $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => trim($match[2])])->all();
    }

    /** The airline logo as a data URI (or null when the file is missing or disabled). */
    private function logo(): ?string
    {
        $path = public_path('images/malawi-airlines-logo.png');

        return config('roster.pdf_logo') && is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    /**
     * "#56677a" -> [r, g, b] in 0..1 for the dompdf canvas.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }
}
