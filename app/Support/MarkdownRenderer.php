<?php

namespace App\Support;

use League\CommonMark\CommonMarkConverter;

class MarkdownRenderer
{
    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return (string) (new CommonMarkConverter)->convertToHtml($markdown);
    }
}
