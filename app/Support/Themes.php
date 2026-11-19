<?php

namespace App\Support;

final class Themes
{
    public const LABELS = ['blue' => 'Classic Blue', 'purple' => 'Clean Purple', 'teal' => 'Modern Teal', 'slate' => 'Neutral Slate', 'custom' => 'Custom'];

    public const TOKENS = [
        'primary' => 'Primary', 'secondary' => 'Secondary', 'accent' => 'Accent', 'link' => 'Link',
        'button' => 'Button', 'button_text' => 'Button text', 'header' => 'Header', 'page' => 'Page',
        'card' => 'Card', 'border' => 'Border', 'text' => 'Text', 'muted' => 'Muted text', 'hover' => 'Hover',
    ];

    public static function preset(string $name): array
    {
        $colors = match ($name) {
            'purple' => ['#7C3AED', '#C4B5FD', '#F3EBFF', '#302543'],
            'teal' => ['#0F766E', '#5EEAD4', '#DCF7F1', '#143630'],
            'slate' => ['#475569', '#CBD5E1', '#EAF0F5', '#293447'],
            default => ['#1169C3', '#75BFFF', '#E8F3FF', '#1B344E'],
        };
        [$light, $dark, $wash, $darkWash] = $colors;

        return [
            'light' => ['primary' => $light, 'secondary' => $wash, 'accent' => $light, 'link' => $light,
                'button' => $light, 'button_text' => '#FFFFFF', 'header' => '#FFFFFF', 'page' => '#F6F8FB',
                'card' => '#FFFFFF', 'border' => '#DFE6EE', 'text' => '#122339', 'muted' => '#51647A', 'hover' => $wash],
            'dark' => ['primary' => $dark, 'secondary' => $darkWash, 'accent' => $dark, 'link' => $dark,
                'button' => $dark, 'button_text' => '#112339', 'header' => '#121C2A', 'page' => '#0E1622',
                'card' => '#172334', 'border' => '#343F51', 'text' => '#EAF1F8', 'muted' => '#AABBD0', 'hover' => $darkWash],
        ];
    }

    public static function contrast(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (string $value): float {
                $value = hexdec($value) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));

            return $channels[0] * .2126 + $channels[1] * .7152 + $channels[2] * .0722;
        };

        $values = [$luminance($foreground), $luminance($background)];

        return (max($values) + .05) / (min($values) + .05);
    }
}
