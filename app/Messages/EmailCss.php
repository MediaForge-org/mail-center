<?php

namespace App\Messages;

use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Settings;
use Sabberworm\CSS\Value\Color;
use Sabberworm\CSS\Value\CSSString;
use Sabberworm\CSS\Value\RuleValueList;
use Sabberworm\CSS\Value\Size;
use Sabberworm\CSS\Value\Value;

/** Declaration-only CSS: parse structure, whitelist AST nodes, then validate property grammars. */
final class EmailCss
{
    public function sanitize(string $css): string
    {
        // Resource bounds, not CSS parsing. Escaped spellings are deliberately unsupported.
        if ($css === '' || strlen($css) > 8192 || str_contains($css, '\\') || str_contains($css, '{') || str_contains($css, '}') || substr_count($css, '(') > 32) {
            return '';
        }
        try {
            $document = (new Parser('email{'.$css.'}', Settings::create()->beStrict()))->parse();
            $blocks = $document->getContents();
            if (count($blocks) !== 1 || ! $blocks[0] instanceof DeclarationBlock
                || count($blocks[0]->getSelectors()) !== 1 || $blocks[0]->getSelectors()[0]->getSelector() !== 'email') {
                return '';
            }
            $safe = [];
            foreach (array_slice($blocks[0]->getDeclarations(), 0, 100) as $rule) {
                $property = strtolower($rule->getPropertyName());
                $value = $rule->getValue();
                if (! $this->safeNodes($value)) {
                    continue;
                }
                $text = $value instanceof Value ? $value->render(OutputFormat::createCompact()) : (string) $value;
                if ($this->allowed($property, strtolower($text))) {
                    // Reconstruct declarations; never copy raw CSS, comments or !important.
                    $safe[] = $property.':'.$text;
                }
            }

            return implode(';', $safe);
        } catch (\Throwable) {
            return '';
        }
    }

    private function safeNodes(mixed $value, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }
        if (is_string($value)) {
            return (bool) preg_match('/\A[a-zA-Z][a-zA-Z0-9 -]{0,127}\z/', $value);
        }
        if ($value instanceof Size) {
            $number = $value->getSize();
            $unit = strtolower((string) $value->getUnit());

            return is_finite($number) && $number >= 0 && $number <= 2048
                && in_array($unit, ['', 'px', 'pt', 'em', 'rem', '%'], true)
                && ($unit !== '%' || $number <= 100)
                && (! in_array($unit, ['em', 'rem'], true) || $number <= 64);
        }
        if ($value instanceof CSSString) {
            return (bool) preg_match('/\A[a-zA-Z][a-zA-Z0-9 -]{0,63}\z/', $value->getString());
        }
        if ($value instanceof Color || $value instanceof RuleValueList) {
            foreach ($value->getListComponents() as $child) {
                if (! $this->safeNodes($child, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        // URL, CSSFunction (except Color), calc/var, gradients, expressions and unknown nodes.
        return false;
    }

    private function allowed(string $property, string $value): bool
    {
        $number = '(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)';
        $length = '(?:0|'.$number.'(?:px|pt|em|rem|%))';
        $color = '(?:#[a-f0-9]{3,8}|(?:rgb|rgba|hsl|hsla)\([0-9.,% /]+\)|[a-z]+)';
        $borderStyle = '(?:none|solid|dashed|dotted|double|groove|ridge|inset|outset)';
        $pattern = match ($property) {
            'color', 'background-color' => $color,
            'font-family' => '[a-z0-9 ,\x22\x27-]+',
            'font-size' => '(?:'.$length.'|small|medium|large|x-small|x-large|xx-small|xx-large)',
            'font-weight' => '(?:normal|bold|bolder|lighter|[1-9]00)',
            'font-style' => '(?:normal|italic|oblique)',
            'line-height' => '(?:normal|'.$number.'|'.$length.')',
            'text-align' => '(?:left|right|center|justify|start|end)',
            'text-decoration' => '(?:none|underline|line-through|overline)(?: (?:underline|line-through|overline))*',
            'vertical-align' => '(?:baseline|top|middle|bottom|text-top|text-bottom|sub|super|'.$length.')',
            'margin' => '(?:auto|'.$length.')(?: (?:auto|'.$length.')){0,3}',
            'margin-top', 'margin-right', 'margin-bottom', 'margin-left' => '(?:auto|'.$length.')',
            'padding', 'border-width', 'border-radius' => $length.'(?: '.$length.'){0,3}',
            'padding-top', 'padding-right', 'padding-bottom', 'padding-left' => $length,
            'width', 'height' => '(?:auto|'.$length.')',
            'min-width' => $length,
            'max-width', 'max-height' => '(?:none|'.$length.')',
            'border', 'border-top', 'border-right', 'border-bottom', 'border-left' => '(?:'.$length.'|'.$color.'|'.$borderStyle.')(?: (?:'.$length.'|'.$color.'|'.$borderStyle.')){0,2}',
            'border-style' => $borderStyle.'(?: '.$borderStyle.'){0,3}',
            'border-color' => $color.'(?: '.$color.'){0,3}',
            'border-collapse' => '(?:collapse|separate)',
            'border-spacing' => $length.'(?: '.$length.')?',
            'display' => '(?:block|inline|inline-block|table|table-row|table-cell|table-row-group|table-header-group|table-footer-group)',
            default => null,
        };

        return $pattern !== null && (bool) preg_match('~\A(?:'.$pattern.')\z~D', $value);
    }
}
