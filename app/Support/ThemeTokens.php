<?php

namespace App\Support;

/**
 * The app's design tokens (resources/css/common/tokens.css) for output that cannot use CSS variables or
 * light-dark(): PDFs (dompdf) and emails (mail clients). Colours always resolve to the light theme value,
 * so documents and emails look the same whatever theme the sender uses.
 */
final class ThemeTokens
{
    /**
     * Every custom property in tokens.css (name without the leading dashes => value). The first definition
     * wins (later ones are theme overrides), and light-dark(light, dark) pairs resolve to the light value.
     *
     * @return array<string, string>
     */
    public static function values(): array
    {
        static $tokens = null;
        if ($tokens === null) {
            preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', (string) file_get_contents(resource_path('css/common/tokens.css')), $matches, PREG_SET_ORDER);
            $tokens = [];
            foreach ($matches as [, $name, $value]) {
                $tokens[$name] ??= preg_match('/^light-dark\(\s*([^,]+),/', trim($value), $pair) ? trim($pair[1]) : trim($value);
            }
        }

        return $tokens;
    }

    /**
     * A stylesheet with every var(--name) replaced by its token value (unknown tokens become "inherit").
     */
    public static function resolve(string $css): string
    {
        $tokens = self::values();

        return preg_replace_callback('/var\(--([a-z0-9-]+)\)/i', fn (array $match): string => $tokens[$match[1]] ?? 'inherit', $css) ?? $css;
    }
}
