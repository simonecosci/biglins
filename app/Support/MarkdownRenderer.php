<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class MarkdownRenderer
{
    private static ?GithubFlavoredMarkdownConverter $converter = null;

    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        self::$converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return (string) self::$converter->convertToHtml($markdown);
    }
}
