<?php

/**
 * Conservative tracklist identity matcher for Grizzly.
 *
 * Goals:
 * - preserve the old multiset behaviour (track order is not identity evidence);
 * - require the same total number of tracks;
 * - prefer exact normalized title matches;
 * - tolerate only a small number of conservative title variants;
 * - never decide that a partial tracklist equals a complete one.
 */
class TrackMatcher
{
    private const VARIANT_RATIO = 0.20;
    private const PREFIX_MIN_COMPACT_LENGTH = 10;
    private const PREFIX_MIN_RATIO = 0.60;
    private const TYPO_MIN_LENGTH = 6;

    /**
     * @param array<int,string> $candidateTracks
     * @param array<int,string> $localTracks
     * @return array<string,mixed>
     */
    public function compare(array $candidateTracks, array $localTracks): array
    {
        $candidateTracks = array_values(array_map('strval', $candidateTracks));
        $localTracks = array_values(array_map('strval', $localTracks));

        $total = count($candidateTracks);

        if ($total === 0 || count($localTracks) === 0) {
            return $this->result(false, 'empty-tracklist', $total, 0, 0, []);
        }

        if ($total !== count($localTracks)) {
            return $this->result(false, 'track-count-mismatch', $total, 0, 0, []);
        }

        $candidate = [];
        foreach ($candidateTracks as $index => $title) {
            $row = $this->prepareTitle($title);
            if ($row['compact'] === '') {
                return $this->result(false, 'empty-normalized-title', $total, 0, 0, [
                    ['side' => 'candidate', 'index' => $index, 'title' => $title],
                ]);
            }
            $candidate[$index] = $row;
        }

        $local = [];
        foreach ($localTracks as $index => $title) {
            $row = $this->prepareTitle($title);
            if ($row['compact'] === '') {
                return $this->result(false, 'empty-normalized-title', $total, 0, 0, [
                    ['side' => 'local', 'index' => $index, 'title' => $title],
                ]);
            }
            $local[$index] = $row;
        }

        $remainingLocal = array_fill_keys(array_keys($local), true);
        $unmatchedCandidate = [];
        $exactCount = 0;

        /*
         * Phase 1: consume exact normalized matches as a multiset.
         * This preserves the resolver's previous order-independent behaviour,
         * including multidisc albums whose DB schema has no disc column.
         */
        foreach ($candidate as $candidateIndex => $candidateRow) {
            $matchedLocalIndex = null;

            foreach ($remainingLocal as $localIndex => $_) {
                if ($candidateRow['compact'] === $local[$localIndex]['compact']) {
                    $matchedLocalIndex = $localIndex;
                    break;
                }
            }

            if ($matchedLocalIndex !== null) {
                unset($remainingLocal[$matchedLocalIndex]);
                $exactCount++;
                continue;
            }

            $unmatchedCandidate[] = $candidateIndex;
        }

        if (empty($unmatchedCandidate)) {
            return $this->result(true, 'exact', $total, $exactCount, 0, []);
        }

        $compatibleCount = count($unmatchedCandidate);
        $maxCompatible = $this->maxCompatibleVariants($total);

        /*
         * Small/single-track releases and tracklists with too many non-exact
         * titles are intentionally not auto-reconciled.
         */
        if ($compatibleCount > $maxCompatible) {
            return $this->result(false, 'too-many-title-variants', $total, $exactCount, $compatibleCount, []);
        }

        $edges = [];
        $diagnostics = [];

        foreach ($unmatchedCandidate as $candidateIndex) {
            $edges[$candidateIndex] = [];

            foreach (array_keys($remainingLocal) as $localIndex) {
                $score = $this->compatibilityScore(
                    $candidate[$candidateIndex],
                    $local[$localIndex]
                );

                if ($score <= 0.0) {
                    continue;
                }

                $edges[$candidateIndex][$localIndex] = $score;
            }

            arsort($edges[$candidateIndex], SORT_NUMERIC);

            if (empty($edges[$candidateIndex])) {
                $diagnostics[] = [
                    'candidate_index' => $candidateIndex,
                    'candidate_title' => $candidateTracks[$candidateIndex],
                    'reason' => 'no-compatible-local-title',
                ];

                return $this->result(
                    false,
                    'title-mismatch',
                    $total,
                    $exactCount,
                    $compatibleCount,
                    $diagnostics
                );
            }
        }

        /*
         * One-to-one bipartite matching prevents one local track from proving
         * two candidate tracks. Candidate rows with fewer alternatives are
         * matched first to reduce accidental ambiguity.
         */
        usort($unmatchedCandidate, function (int $a, int $b) use ($edges): int {
            $countCmp = count($edges[$a]) <=> count($edges[$b]);
            if ($countCmp !== 0) {
                return $countCmp;
            }

            $aBest = empty($edges[$a]) ? 0.0 : max($edges[$a]);
            $bBest = empty($edges[$b]) ? 0.0 : max($edges[$b]);
            return $bBest <=> $aBest;
        });

        $matchedLocal = [];
        $matchedCandidate = [];

        $tryMatch = function (int $candidateIndex, array &$seen) use (&$tryMatch, &$matchedLocal, &$matchedCandidate, $edges): bool {
            foreach ($edges[$candidateIndex] as $localIndex => $score) {
                if (isset($seen[$localIndex])) {
                    continue;
                }

                $seen[$localIndex] = true;

                if (!isset($matchedLocal[$localIndex])) {
                    $matchedLocal[$localIndex] = $candidateIndex;
                    $matchedCandidate[$candidateIndex] = [
                        'local_index' => $localIndex,
                        'score' => $score,
                    ];
                    return true;
                }

                $otherCandidate = $matchedLocal[$localIndex];
                if ($tryMatch($otherCandidate, $seen)) {
                    $matchedLocal[$localIndex] = $candidateIndex;
                    $matchedCandidate[$candidateIndex] = [
                        'local_index' => $localIndex,
                        'score' => $score,
                    ];
                    return true;
                }
            }

            return false;
        };

        foreach ($unmatchedCandidate as $candidateIndex) {
            $seen = [];
            if (!$tryMatch($candidateIndex, $seen)) {
                return $this->result(
                    false,
                    'ambiguous-title-variants',
                    $total,
                    $exactCount,
                    $compatibleCount,
                    []
                );
            }
        }

        foreach ($matchedCandidate as $candidateIndex => $match) {
            $localIndex = (int)$match['local_index'];
            $diagnostics[] = [
                'candidate_index' => $candidateIndex,
                'candidate_title' => $candidateTracks[$candidateIndex],
                'local_index' => $localIndex,
                'local_title' => $localTracks[$localIndex],
                'score' => $match['score'],
            ];
        }

        return $this->result(
            true,
            'compatible',
            $total,
            $exactCount,
            $compatibleCount,
            $diagnostics
        );
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function compatibilityScore(array $a, array $b): float
    {
        if ($a['compact'] === $b['compact']) {
            return 1.0;
        }

        // Deterministic orthographic equivalence only. This is deliberately
        // narrower than fuzzy matching: normalize common tagging fallbacks
        // such as Italian accented-vowel apostrophes (E' -> È) and Latin
        // ligatures (ae <-> æ), while preserving actual accent distinctions.
        if (
            $a['orthographic_compact'] !== ''
            && $a['orthographic_compact'] === $b['orthographic_compact']
        ) {
            return 0.99;
        }

        if ($this->isConservativePrefixVariant($a, $b)) {
            return 0.97;
        }

        if ($this->isMinorTypo($a['chars'], $b['chars'])) {
            return 0.94;
        }

        return 0.0;
    }

    /**
     * A longer title may contain a short annotation/hidden-title suffix, but
     * the shorter title must be a substantial exact word-prefix of the longer
     * title. This is structural and does not depend on one separator such as '/'.
     *
     * Examples accepted when only a small minority of the album needs it:
     *   Kitchenware & Candybars
     *   Kitchenware & Candybars / Second Album
     *
     * Short/generic prefixes such as "Big Empty" -> "Big Empty Live" are
     * deliberately rejected by the minimum compact length guard.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function isConservativePrefixVariant(array $a, array $b): bool
    {
        $aWords = $a['words'];
        $bWords = $b['words'];

        if (count($aWords) === count($bWords)) {
            return false;
        }

        $short = count($aWords) < count($bWords) ? $a : $b;
        $long = count($aWords) < count($bWords) ? $b : $a;

        if (count($short['words']) < 2) {
            return false;
        }

        if ($this->stringLength($short['compact']) < self::PREFIX_MIN_COMPACT_LENGTH) {
            return false;
        }

        $longLength = $this->stringLength($long['compact']);
        if ($longLength === 0) {
            return false;
        }

        $ratio = $this->stringLength($short['compact']) / $longLength;
        if ($ratio < self::PREFIX_MIN_RATIO) {
            return false;
        }

        foreach ($short['words'] as $index => $word) {
            if (!isset($long['words'][$index]) || $long['words'][$index] !== $word) {
                return false;
            }
        }

        return true;
    }

    /**
     * Only tiny spelling/tagging differences are tolerated: one edit for
     * ordinary titles, at most two edits for long titles. Short titles are
     * never fuzzy-matched.
     *
     * @param array<int,string> $aChars
     * @param array<int,string> $bChars
     */
    private function isMinorTypo(array $aChars, array $bChars): bool
    {
        $aLength = count($aChars);
        $bLength = count($bChars);
        $minLength = min($aLength, $bLength);
        $maxLength = max($aLength, $bLength);

        if ($minLength < self::TYPO_MIN_LENGTH) {
            return false;
        }

        $maxDistance = $maxLength >= 20 ? 2 : 1;
        return $this->unicodeLevenshtein($aChars, $bChars, $maxDistance) <= $maxDistance;
    }

    /**
     * Bounded Unicode-aware Levenshtein distance.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     */
    private function unicodeLevenshtein(array $a, array $b, int $cutoff): int
    {
        $aLength = count($a);
        $bLength = count($b);

        if (abs($aLength - $bLength) > $cutoff) {
            return $cutoff + 1;
        }

        $previous = range(0, $bLength);

        for ($i = 1; $i <= $aLength; $i++) {
            $current = [$i];
            $rowMin = $i;

            for ($j = 1; $j <= $bLength; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $current[$j - 1] + 1,
                    $previous[$j] + 1,
                    $previous[$j - 1] + $cost
                );
                $rowMin = min($rowMin, $current[$j]);
            }

            if ($rowMin > $cutoff) {
                return $cutoff + 1;
            }

            $previous = $current;
        }

        return $previous[$bLength];
    }

    /**
     * @return array<string,mixed>
     */
    private function prepareTitle(string $title): array
    {
        $title = trim($title);

        if (function_exists('mb_strtolower')) {
            $lower = mb_strtolower($title, 'UTF-8');
        } else {
            $lower = strtolower($title);
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            $words = [];
        }

        $compact = implode('', $words);
        $chars = $compact === ''
            ? []
            : preg_split('//u', $compact, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($chars)) {
            $chars = str_split($compact);
        }

        // GRIZZLY_PUNCTUATION_ONLY_TITLE_FIX_V1
        // A real track title may consist solely of punctuation (e.g. "*#..").
        // An empty alphanumeric key must not invalidate an otherwise exact
        // tracklist. Give such titles an exact-only, collision-free key.
        // Keep $words/$chars empty: prefix and fuzzy matching stay disabled.
        // Empty/whitespace-only titles are still rejected.
        if ($compact === '' && $title !== '') {
            $compact = 'punct:' . bin2hex($title);
        }

        $orthographic = $this->normalizeOrthographicVariants($lower);
        $orthographicWords = preg_split(
            '/[^\p{L}\p{N}]+/u',
            $orthographic,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($orthographicWords)) {
            $orthographicWords = [];
        }

        return [
            'raw' => $title,
            'words' => array_values($words),
            'compact' => $compact,
            'chars' => array_values($chars),
            'orthographic_compact' => implode('', $orthographicWords),
        ];
    }

    /**
     * Normalize only deterministic orthographic variants that commonly arise
     * from metadata/tagging limitations. Do not perform generic accent folding:
     * "resume" and "résumé", for example, must remain different strings.
     */
    private function normalizeOrthographicVariants(string $value): string
    {
        if (class_exists('Normalizer')) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        // PHP builds without mbstring do not lowercase non-ASCII characters.
        // Keep this key deterministic by lowercasing only the Unicode forms we
        // intentionally handle below; this does not broaden matching rules.
        $value = strtr($value, [
            'À' => 'à', 'Á' => 'á', 'Â' => 'â', 'Ã' => 'ã', 'Ä' => 'ä', 'Å' => 'å',
            'È' => 'è', 'É' => 'é', 'Ê' => 'ê', 'Ë' => 'ë',
            'Ì' => 'ì', 'Í' => 'í', 'Î' => 'î', 'Ï' => 'ï',
            'Ò' => 'ò', 'Ó' => 'ó', 'Ô' => 'ô', 'Õ' => 'õ', 'Ö' => 'ö',
            'Ù' => 'ù', 'Ú' => 'ú', 'Û' => 'û', 'Ü' => 'ü',
            'Ý' => 'ý', 'Ÿ' => 'ÿ',
            'Æ' => 'æ', 'Œ' => 'œ',
        ]);

        // Common Latin ligature fallbacks used by tags/file names.
        $value = strtr($value, [
            'æ' => 'ae',
            'œ' => 'oe',
        ]);

        // Common Italian ASCII fallback for a final accented vowel:
        // E' aria -> È aria, perche' -> perchè. This is intentionally limited
        // to apostrophe-at-word-end forms and does not strip accents globally.
        $accentMap = [
            'a' => 'à',
            'e' => 'è',
            'i' => 'ì',
            'o' => 'ò',
            'u' => 'ù',
        ];

        $value = preg_replace_callback(
            "/([aeiou])['’](?=$|[^\p{L}\p{N}])/u",
            static function (array $m) use ($accentMap): string {
                $vowel = isset($m[1]) ? (string)$m[1] : '';
                return $accentMap[$vowel] ?? $m[0];
            },
            $value
        );

        return is_string($value) ? $value : '';
    }

    private function maxCompatibleVariants(int $trackCount): int
    {
        if ($trackCount < 3) {
            return 0;
        }

        return max(1, (int)floor($trackCount * self::VARIANT_RATIO));
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    /**
     * @param array<int,array<string,mixed>> $details
     * @return array<string,mixed>
     */
    private function result(
        bool $equivalent,
        string $reason,
        int $total,
        int $exactCount,
        int $compatibleCount,
        array $details
    ): array {
        return [
            'equivalent' => $equivalent,
            'reason' => $reason,
            'total' => $total,
            'exact_count' => $exactCount,
            'compatible_count' => $compatibleCount,
            'details' => $details,
        ];
    }
}
