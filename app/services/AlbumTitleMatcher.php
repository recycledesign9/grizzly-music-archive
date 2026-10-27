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
     * Classify the semantic relationship between two display titles without
     * broadening equivalent(). This is intentionally conservative and is meant
     * to be combined with independent evidence (artist/year/tracklist/MBID).
     *
     * Returned values:
     *   same-title       normal exact/qualified equivalence;
     *   catalog-alias    cataloguing-only representation difference
     *                    (numbered LP shorthand/suffix or exact year suffix);
     *   distinct-edition explicit edition/disambiguation boundary;
     *   unrelated        no safe title relationship.
     */
    public function relationship(
        string $left,
        string $right,
        string $artist = '',
        ?int $knownYear = null
    ): string {
        $leftInfo = $this->catalogTitleInfo($left, $artist, $knownYear);
        $rightInfo = $this->catalogTitleInfo($right, $artist, $knownYear);

        $leftBase = $this->normalizeIdentity($leftInfo['base']);
        $rightBase = $this->normalizeIdentity($rightInfo['base']);

        // Catalogue syntax must be classified before generic normalized title
        // equivalence. Otherwise "Artist LP3" and "Artist (LP3)" normalize to
        // the same string and the catalogue relationship is lost as same-title.
        if ($leftBase !== '' && $leftBase === $rightBase) {
            if ($leftInfo['kind'] === 'catalog' && $rightInfo['kind'] === 'catalog') {
                $leftKey = (string)$leftInfo['catalog_key'];
                $rightKey = (string)$rightInfo['catalog_key'];

                if ($leftKey !== '' && $rightKey !== '' && $leftKey !== $rightKey) {
                    $leftIsLp = strpos($leftKey, 'lp:') === 0;
                    $rightIsLp = strpos($rightKey, 'lp:') === 0;

                    // Two explicit numbered LP identities are distinct when the
                    // numbers differ. A known-year display qualifier, however,
                    // is only another catalogue representation of the same base
                    // and may still be proven by year + full tracklist.
                    if ($leftIsLp && $rightIsLp) {
                        return 'distinct-edition';
                    }
                }

                return 'catalog-alias';
            }

            if ($leftInfo['kind'] === 'catalog' || $rightInfo['kind'] === 'catalog') {
                return 'catalog-alias';
            }
        }

        // Preserve normal title equivalence after catalogue-only forms have
        // had a chance to identify themselves.
        if ($this->equivalent($left, $right)) {
            return 'same-title';
        }

        if ($leftInfo['kind'] === 'edition' || $rightInfo['kind'] === 'edition') {
            return 'distinct-edition';
        }

        // Longer branded titles with a base-title prefix are not silently
        // interchangeable aliases (for example OK Computer vs OKNOTOK).
        $a = $this->normalizeIdentity($left);
        $b = $this->normalizeIdentity($right);
        if ($a !== '' && $b !== '' && $a !== $b
            && (strpos($a, $b) === 0 || strpos($b, $a) === 0)) {
            return 'distinct-edition';
        }

        return 'unrelated';
    }

    /**
     * Parse only catalogue-style disambiguators. A bare "LP3" can be resolved
     * only when the caller also supplies the artist; this prevents the shorthand
     * from becoming a global fuzzy alias.
     *
     * @return array{base:string,kind:string,catalog_key:string}
     */
    private function catalogTitleInfo(
        string $title,
        string $artist,
        ?int $knownYear
    ): array {
        $title = $this->cleanDisplay($title);
        $artist = $this->cleanDisplay($artist);

        if ($title === '') {
            return ['base' => '', 'kind' => 'bare', 'catalog_key' => ''];
        }

        // Local shorthand: "LP3". It has meaning only in the scope of the
        // already-known artist.
        if (preg_match('/^LP\s*([2-9]|[1-9][0-9]+)$/iu', $title, $m) === 1) {
            return [
                'base' => $artist,
                'kind' => 'catalog',
                'catalog_key' => 'lp:' . (int)$m[1],
            ];
        }

        // Display form: "Artist LP3".
        if (preg_match('/^(.*?)\s+LP\s*([2-9]|[1-9][0-9]+)\s*$/iu', $title, $m) === 1
            && trim((string)$m[1]) !== '') {
            return [
                'base' => trim((string)$m[1]),
                'kind' => 'catalog',
                'catalog_key' => 'lp:' . (int)$m[2],
            ];
        }

        $parts = $this->splitFinalQualifier($title);
        if ($parts['qualifier'] !== null) {
            $qualifier = trim((string)$parts['qualifier']);

            if (preg_match('/^LP\s*([2-9]|[1-9][0-9]+)$/iu', $qualifier, $m) === 1) {
                return [
                    'base' => (string)$parts['base'],
                    'kind' => 'catalog',
                    'catalog_key' => 'lp:' . (int)$m[1],
                ];
            }

            if ($knownYear !== null
                && preg_match('/^(19|20)[0-9]{2}$/', $qualifier) === 1
                && (int)$qualifier === $knownYear) {
                return [
                    'base' => (string)$parts['base'],
                    'kind' => 'catalog',
                    'catalog_key' => 'year:' . $knownYear,
                ];
            }

            return [
                'base' => (string)$parts['base'],
                'kind' => 'edition',
                'catalog_key' => '',
            ];
        }

        return [
            'base' => $title,
            'kind' => 'bare',
            'catalog_key' => '',
        ];
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
