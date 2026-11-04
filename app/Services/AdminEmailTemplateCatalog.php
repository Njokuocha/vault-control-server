<?php

namespace App\Services;

use InvalidArgumentException;

class AdminEmailTemplateCatalog
{
    /**
     * @return array<string, array{name: string, style: string, description: string, recommended_characters: int|null, surface: string, accent: string, text: string, muted: string, border: string, font: string}>
     */
    public function all(): array
    {
        return [
            'clean-white' => [
                'name' => 'Clean white',
                'style' => 'A crisp, spacious white layout with a restrained blue accent.',
                'description' => 'Bright, clean, and easy to scan.',
                'recommended_characters' => 1200,
                'surface' => '#ffffff',
                'accent' => '#315f88',
                'text' => '#243242',
                'muted' => '#687789',
                'border' => '#dce4ec',
                'font' => 'Arial, sans-serif',
            ],
            'midnight' => [
                'name' => 'Midnight',
                'style' => 'A dark, high-contrast design with a single warm highlight.',
                'description' => 'A polished dark presentation.',
                'recommended_characters' => 900,
                'surface' => '#172333',
                'accent' => '#d4ae63',
                'text' => '#f0f3f6',
                'muted' => '#b6c0cd',
                'border' => '#34475b',
                'font' => 'Arial, sans-serif',
            ],
            'classic-letter' => [
                'name' => 'Classic letter',
                'style' => 'A traditional letter format with generous margins and serif type.',
                'description' => 'Formal, timeless, and familiar.',
                'recommended_characters' => 1800,
                'surface' => '#fffdf8',
                'accent' => '#725a3a',
                'text' => '#352f28',
                'muted' => '#746b61',
                'border' => '#e8dfd1',
                'font' => 'Georgia, serif',
            ],
            'soft-sage' => [
                'name' => 'Soft sage',
                'style' => 'A calm neutral palette with subtle green details.',
                'description' => 'Balanced, calm, and welcoming.',
                'recommended_characters' => 1100,
                'surface' => '#f7faf7',
                'accent' => '#547568',
                'text' => '#2e3e38',
                'muted' => '#6d7f77',
                'border' => '#d9e5dd',
                'font' => 'Arial, sans-serif',
            ],
            'modern-blue' => [
                'name' => 'Modern blue',
                'style' => 'A structured layout with cool blue accents and clear spacing.',
                'description' => 'Contemporary with a clear hierarchy.',
                'recommended_characters' => 1400,
                'surface' => '#f3f7fb',
                'accent' => '#246b9a',
                'text' => '#263746',
                'muted' => '#62788a',
                'border' => '#d5e0e9',
                'font' => 'Arial, sans-serif',
            ],
            'editorial' => [
                'name' => 'Editorial',
                'style' => 'A quiet, text-forward design with a prominent title treatment.',
                'description' => 'Roomy and comfortable for longer notes.',
                'recommended_characters' => 2200,
                'surface' => '#fffefa',
                'accent' => '#8a6040',
                'text' => '#302e2b',
                'muted' => '#77716a',
                'border' => '#e7e1d8',
                'font' => 'Georgia, serif',
            ],
            'warm-stationery' => [
                'name' => 'Warm stationery',
                'style' => 'An understated paper-like look with copper details.',
                'description' => 'Warm, personal, and restrained.',
                'recommended_characters' => 1000,
                'surface' => '#fbf7f0',
                'accent' => '#a35f43',
                'text' => '#3c332d',
                'muted' => '#7d7066',
                'border' => '#e9ded1',
                'font' => 'Georgia, serif',
            ],
            'formal-frame' => [
                'name' => 'Formal frame',
                'style' => 'A neat framed composition with a strong opening and close.',
                'description' => 'A composed, formal presentation.',
                'recommended_characters' => 1600,
                'surface' => '#f6f7f8',
                'accent' => '#465d72',
                'text' => '#26323d',
                'muted' => '#667583',
                'border' => '#d5dce2',
                'font' => 'Arial, sans-serif',
            ],
            'slate-memo' => [
                'name' => 'Slate memo',
                'style' => 'A compact, modern memo with cool slate tones.',
                'description' => 'Direct and organized.',
                'recommended_characters' => 1500,
                'surface' => '#eef2f5',
                'accent' => '#526d82',
                'text' => '#293945',
                'muted' => '#657885',
                'border' => '#d2dde4',
                'font' => 'Arial, sans-serif',
            ],
            'open-letter' => [
                'name' => 'Open letter',
                'style' => 'An airy, minimal layout with no suggested message length.',
                'description' => 'Simple, open, and suitable for any length.',
                'recommended_characters' => null,
                'surface' => '#ffffff',
                'accent' => '#6d7280',
                'text' => '#343844',
                'muted' => '#747887',
                'border' => '#e7e8eb',
                'font' => 'Arial, sans-serif',
            ],
        ];
    }

    /**
     * @return array{name: string, style: string, description: string, recommended_characters: int|null, surface: string, accent: string, text: string, muted: string, border: string, font: string}
     */
    public function find(string $key): array
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException('Choose an available email template.');
    }

    public function render(string $key, string $bodyHtml): string
    {
        $template = $this->find($key);
        $background = $template['surface'];
        $accent = $template['accent'];
        $text = $template['text'];
        $muted = $template['muted'];
        $border = $template['border'];
        $font = $template['font'];

        $outerStyle = match ($key) {
            'midnight' => "background-color:{$background};padding:36px 18px;font-family:{$font};",
            'classic-letter', 'warm-stationery' => "background-color:{$background};padding:42px 28px;font-family:{$font};",
            'editorial' => "background-color:{$background};padding:38px 28px;font-family:{$font};",
            'formal-frame' => "background-color:{$background};padding:24px;border:1px solid {$border};font-family:{$font};",
            'slate-memo' => "background-color:{$background};padding:24px 20px;font-family:{$font};",
            'open-letter' => "background-color:{$background};padding:34px 26px;font-family:{$font};",
            default => "background-color:{$background};padding:28px 22px;font-family:{$font};",
        };
        $headingStyle = "margin:0 0 7px;color:{$accent};font-size:20px;font-weight:600;line-height:1.35;";
        $bodyStyle = "color:{$text};font-size:15px;line-height:1.75;";
        $footerStyle = "margin-top:24px;padding-top:12px;border-top:1px solid {$border};color:{$muted};font-size:12px;line-height:1.55;";
        $topRule = $key === 'formal-frame'
            ? ''
            : '<div style="height:3px;background-color:'.$accent.';margin:0 0 20px;"></div>';
        $outer = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background-color:'.$background.';"><tbody><tr><td style="'.$outerStyle.'">'
            .$topRule
            .'<p style="margin:0 0 8px;color:'.$muted.';font-size:11px;line-height:1.4;">CORRESPONDENCE</p>'
            .'<h2 style="'.$headingStyle.'">A message for you</h2>'
            .'<div style="'.$bodyStyle.'">'.$bodyHtml.'</div>'
            .'<p style="'.$footerStyle.'">Please contact the sender if you have any questions.</p>'
            .'</td></tr></tbody></table>';

        return app(AdminEmailFormatter::class)->sanitizeForEmail($outer);
    }

    public function preview(string $key, string $bodyHtml = ''): string
    {
        $content = $bodyHtml === ''
            ? '<p>Hello,</p><p>This is sample text to demonstrate the selected email design. Replace this message with your own when composing.</p><p>Kind regards,<br>Your name</p>'
            : app(AdminEmailFormatter::class)->sanitizeForEmail($bodyHtml);

        return $this->render($key, $content);
    }
}
