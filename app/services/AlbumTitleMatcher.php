<?php

/**
 * Conservative album-title equivalence policy shared by scanner readiness
 * and final album identity resolution.
 *
 * PHP 7.4 compatible.
 *
 * Rules:
 * - normal punctuation/case normalization preserves the existing behaviour;
 * - otherwise both titles must expose one explicit FINAL qualifier in (), []
 *   or {};
 * - the base titles must normalize identically;
 * - the qualifiers must normalize identically;
 * - only a leading "the" inside the qualifier is ignored.
 *
 * Examples:
 *   Weezer [Red Album]       == Weezer (The Red Album)
 *   Weezer (Red Album)       != Weezer (Blue Album)
 *   Weezer                   != Weezer (Red Album)
 *   Album                    != Album (Deluxe Edition)
 *   OK Computer              != OK Computer OKNOTOK 1997 2017
 */
final class AlbumTitleMatcher
{
    /**
     * @return array{
     *   equivalent:bool,
     *   mode:string,
     *   left_base:string,
     *   right_base:string,
     *   left_qualifier:?string,
     *   right_qualifier:?string
     * }
     */
    public function compare(string $left, string $right): array
    {
        $left = $this->cleanDisplay($left);
        $right = $this->cleanDisplay($right);

        $baseResult = [
            'equivalent' => false,
            'mode' => 'mismatch',
            'left_base' => $left,
            'right_base' => $right,
            'left_qualifier' => null,
            'right_qualifier' => null,
        ];

        if ($left === '' || $right === '') {
            return $baseResult;
        }

        // Preserve the existing scanner identity semantics first:
        // case and punctuation do not make two titles different.
        $leftExact = $this->normalizeIdentity($left);
        $rightExact = $this->normalizeIdentity($right);

        if ($leftExact !== '' && hash_equals($leftExact, $rightExact)) {
            $baseResult['equivalent'] = true;
            $baseResult['mode'] = 'normalized-exact';
            return $baseResult;
        }

        $leftParts = $this->splitFinalQualifier($left);
        $rightParts = $this->splitFinalQualifier($right);

        $baseResult['left_base'] = $leftParts['base'];
        $baseResult['right_base'] = $rightParts['base'];
        $baseResult['left_qualifier'] = $leftParts['qualifier'];
        $baseResult['right_qualifier'] = $rightParts['qualifier'];

        // Do not infer an edition/alias when only one side is qualified.
        if (
            $leftParts['qualifier'] === null
            || $rightParts['qualifier'] === null
        ) {
            return $baseResult;
        }

        $leftBase = $this->normalizeIdentity($leftParts['base']);
        $rightBase = $this->normalizeIdentity($rightParts['base']);

        if (
            $leftBase === ''
            || $rightBase === ''
            || !hash_equals($leftBase, $rightBase)
        ) {
            return $baseResult;
        }

        $leftQualifier = $this->normalizeQualifier(
            (string)$leftParts['qualifier']
        );
        $rightQualifier = $this->normalizeQualifier(
            (string)$rightParts['qualifier']
        );

        if (
            $leftQualifier === ''
            || $rightQualifier === ''
            || !hash_equals($leftQualifier, $rightQualifier)
        ) {
            return $baseResult;
        }

        $baseResult['equivalent'] = true;
        $baseResult['mode'] = 'qualified-equivalent';

        return $baseResult;
    }

    public function equivalent(string $left, string $right): bool
    {
        $result = $this->compare($left, $right);
        return !empty($result['equivalent']);
    }

    /**
     * @return array{base:string,qualifier:?string}
     */
    private function splitFinalQualifier(string $title): array
    {
        $title = $this->cleanDisplay($title);

        $patterns = [
            '/^(.*?)\s*\(([^()]*)\)\s*$/u',
            '/^(.*?)\s*\[([^\[\]]*)\]\s*$/u',
            '/^(.*?)\s*\{([^{}]*)\}\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $m) !== 1) {
                continue;
            }

            $base = $this->cleanDisplay((string)$m[1]);
            $qualifier = $this->cleanDisplay((string)$m[2]);

            if ($base !== '' && $qualifier !== '') {
                return [
                    'base' => $base,
                    'qualifier' => $qualifier,
                ];
            }
        }

        return [
            'base' => $title,
            'qualifier' => null,
        ];
    }

    private function normalizeQualifier(string $value): string
    {
        $value = $this->cleanDisplay($value);

        if ($value === '') {
            return '';
        }

        // The article relaxation is intentionally scoped to the qualifier.
        // It is NOT applied to the album base title.
        $value = (string)preg_replace(
            '/^the(?:[\s._\-:]+|$)/iu',
            '',
            $value
        );

        return $this->normalizeIdentity($value);
    }

    private function cleanDisplay(string $value): string
    {
        $value = trim($value);
        $value = (string)preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }

    private function normalizeIdentity(string $value): string
    {
        $value = $this->cleanDisplay($value);

        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        return trim((string)$value);
    }
}
