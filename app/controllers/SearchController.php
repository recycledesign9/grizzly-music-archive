<?php

class SearchController {

    private const MIN_QUERY_LENGTH = 2;
    private const MIN_STANDALONE_PREFIX_LENGTH = 4;
    private const MIN_ANCHORED_PREFIX_LENGTH = 2;

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function dispatch(string $action, ?int $id): void {
        $this->index();
    }

    public function index(): void {
        $q = trim((string)($_GET['q'] ?? ''));

        // Filtri avanzati (stessi dell'archivio)
        $filters = [
            'format_id' => (int)($_GET['format_id'] ?? 0),
            'genre_id'  => (int)($_GET['genre_id']  ?? 0),
            'label_id'  => (int)($_GET['label_id']  ?? 0),
            'year'      => (int)($_GET['year']      ?? 0),
        ];
        $hasFilters = (bool)array_filter($filters);

        // Lookup per i select del form di ricerca.
        // Non riutilizzare $formats durante l'elaborazione dei risultati.
        $albumModel = new Album();
        $formats = $albumModel->getFormats();
        $genres  = $albumModel->getGenres();
        $labels  = $albumModel->getLabels();

        $albums  = [];
        $artists = [];

        $normalizedQuery = $this->normalizeSearchText($q);
        $queryTokens     = $this->tokenize($normalizedQuery);

        $hasValidText = $normalizedQuery !== ''
            && $this->textLength($normalizedQuery) >= self::MIN_QUERY_LENGTH
            && !empty($queryTokens);

        $textQueryTooShort = $q !== '' && !$hasValidText;
        $didSearch = $hasValidText || $hasFilters;

        if ($didSearch) {
            $albums = $this->searchAlbums(
                $filters,
                $hasValidText,
                $normalizedQuery,
                $queryTokens
            );

            // Con soli filtri mostriamo gli album. Gli artisti vengono
            // cercati soltanto quando esiste una query testuale valida.
            if ($hasValidText) {
                $artists = $this->searchArtists($normalizedQuery, $queryTokens);
            }
        }

        require BASE_PATH . '/views/search/results.php';
    }

    /**
     * Recupera candidati SQL e applica il ranking rigoroso in PHP.
     *
     * SQL serve solo a ridurre il numero di righe da analizzare.
     * La decisione finale distingue token esatti, frasi e prefissi
     * controllati, evitando casi come "mac" dentro "machine".
     */
    private function searchAlbums(
        array $filters,
        bool $hasValidText,
        string $normalizedQuery,
        array $queryTokens
    ): array {
        $where  = [];
        $params = [];

        if ($hasValidText) {
            // Ogni token deve comparire almeno come candidato nel nome artista
            // oppure nel titolo. Il controllo sulle parole intere avviene poi
            // nel ranking PHP.
            foreach ($queryTokens as $token) {
                $like = '%' . $token . '%';
                $where[] = '(LOWER(ar.name) LIKE ? OR LOWER(a.title) LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
        }

        if ($filters['format_id']) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM album_formats afx
                WHERE afx.album_id = a.id
                  AND afx.format_id = ?
            )';
            $params[] = $filters['format_id'];
        }

        if ($filters['genre_id']) {
            $where[] = 'a.genre_id = ?';
            $params[] = $filters['genre_id'];
        }

        if ($filters['label_id']) {
            $where[] = 'a.label_id = ?';
            $params[] = $filters['label_id'];
        }

        if ($filters['year']) {
            $where[] = 'a.year = ?';
            $params[] = $filters['year'];
        }

        // $where non può essere vuoto: il metodo viene chiamato solo con
        // query valida oppure con almeno un filtro attivo.
        $sql = "
            SELECT
                a.*,
                ar.name AS artist_name,
                ar.id AS artist_id,
                f.name AS format_name,
                (
                    SELECT GROUP_CONCAT(
                        CONCAT(f2.id, ':::', f2.name)
                        ORDER BY f2.id SEPARATOR '|||'
                    )
                    FROM album_formats af2
                    JOIN formats f2 ON f2.id = af2.format_id
                    WHERE af2.album_id = a.id
                ) AS formats_raw
            FROM albums a
            JOIN artists ar ON ar.id = a.artist_id
            LEFT JOIN formats f ON f.id = a.format_id
            WHERE " . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];

        foreach ($rows as $row) {
            if ($hasValidText) {
                $match = $this->rankAlbum(
                    $normalizedQuery,
                    $queryTokens,
                    (string)$row['artist_name'],
                    (string)$row['title']
                );

                // Il candidato SQL conteneva le sottostringhe, ma non ha
                // superato il controllo su frase/token interi.
                if ($match === null) {
                    continue;
                }

                $row['_search_score'] = $match['score'];
                $row['_search_match'] = $match['type'];
                $row['_search_match_label'] = $match['label'];
            } else {
                $row['_search_score'] = 0;
                $row['_search_match'] = 'filters';
                $row['_search_match_label'] = '';
            }

            $albumFormats = [];

            if (!empty($row['formats_raw'])) {
                foreach (explode('|||', $row['formats_raw']) as $chunk) {
                    $parts = explode(':::', $chunk, 2);

                    if (count($parts) === 2 && $parts[0] !== '') {
                        $albumFormats[] = [
                            'id'   => (int)$parts[0],
                            'name' => $parts[1],
                        ];
                    }
                }
            }

            $row['formats'] = $albumFormats;
            unset($row['formats_raw']);
            $results[] = $row;
        }

        if ($hasValidText) {
            usort($results, function (array $left, array $right): int {
                $scoreComparison = $right['_search_score'] <=> $left['_search_score'];

                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $artistComparison = strcasecmp(
                    (string)$left['artist_name'],
                    (string)$right['artist_name']
                );

                if ($artistComparison !== 0) {
                    return $artistComparison;
                }

                $yearComparison = ((int)($left['year'] ?? 0)) <=> ((int)($right['year'] ?? 0));

                if ($yearComparison !== 0) {
                    return $yearComparison;
                }

                return strcasecmp((string)$left['title'], (string)$right['title']);
            });
        } else {
            usort($results, function (array $left, array $right): int {
                $artistComparison = strcasecmp(
                    (string)$left['artist_name'],
                    (string)$right['artist_name']
                );

                if ($artistComparison !== 0) {
                    return $artistComparison;
                }

                return ((int)($left['year'] ?? 0)) <=> ((int)($right['year'] ?? 0));
            });
        }

        return $results;
    }

    private function searchArtists(string $normalizedQuery, array $queryTokens): array {
        $where  = [];
        $params = [];

        foreach ($queryTokens as $token) {
            $where[] = 'LOWER(ar.name) LIKE ?';
            $params[] = '%' . $token . '%';
        }

        $stmt = $this->db->prepare("
            SELECT ar.*, COUNT(a.id) AS album_count
            FROM artists ar
            LEFT JOIN albums a ON a.artist_id = ar.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY ar.id
        ");
        $stmt->execute($params);

        $results = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $match = $this->rankArtist(
                $normalizedQuery,
                $queryTokens,
                (string)$row['name']
            );

            if ($match === null) {
                continue;
            }

            $row['_search_score'] = $match['score'];
            $row['_search_match'] = $match['type'];
            $row['_search_match_label'] = $match['label'];
            $results[] = $row;
        }

        usort($results, function (array $left, array $right): int {
            $scoreComparison = $right['_search_score'] <=> $left['_search_score'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return strcasecmp((string)$left['name'], (string)$right['name']);
        });

        return $results;
    }

    /**
     * Ranking album bilanciato.
     *
     * Ordine generale:
     * 1. nome/titolo esatto;
     * 2. frase completa su confini di parola;
     * 3. token esatti;
     * 4. prefissi di token ammessi dalla soglia dinamica;
     * 5. corrispondenza distribuita tra artista e titolo.
     *
     * Non vengono mai accettati frammenti interni alle parole:
     * "case" non corrisponde a "snapcase" e "mac" non corrisponde
     * automaticamente a "machine".
     */
    private function rankAlbum(
        string $query,
        array $queryTokens,
        string $artist,
        string $title
    ): ?array {
        $artistNormalized = $this->normalizeSearchText($artist);
        $titleNormalized  = $this->normalizeSearchText($title);

        $artistTokens = $this->tokenize($artistNormalized);
        $titleTokens  = $this->tokenize($titleNormalized);

        $artistAndTitle = trim($artistNormalized . ' ' . $titleNormalized);
        $titleAndArtist = trim($titleNormalized . ' ' . $artistNormalized);

        if ($artistNormalized === $query) {
            return $this->match(1200, 'exact_artist', 'Artista esatto');
        }

        if ($titleNormalized === $query) {
            return $this->match(1180, 'exact_title', 'Titolo esatto');
        }

        if ($artistAndTitle === $query || $titleAndArtist === $query) {
            return $this->match(1160, 'exact_combined', 'Corrispondenza esatta');
        }

        if ($this->containsPhrase($artistNormalized, $query)) {
            return $this->match(1120, 'phrase_artist', 'Frase nell’artista');
        }

        if ($this->containsPhrase($titleNormalized, $query)) {
            return $this->match(1100, 'phrase_title', 'Frase nel titolo');
        }

        if ($this->containsPhrase($artistAndTitle, $query)
            || $this->containsPhrase($titleAndArtist, $query)) {
            return $this->match(1080, 'phrase_combined', 'Frase completa');
        }

        $artistMatch = $this->analyzeTokenMatch($queryTokens, $artistTokens);
        $titleMatch  = $this->analyzeTokenMatch($queryTokens, $titleTokens);

        $sameFieldMatches = [];

        if ($artistMatch !== null) {
            $sameFieldMatches[] = $this->tokenMatchResult(
                $artistMatch,
                1010,
                'artist',
                'nell’artista'
            );
        }

        if ($titleMatch !== null) {
            $sameFieldMatches[] = $this->tokenMatchResult(
                $titleMatch,
                990,
                'title',
                'nel titolo'
            );
        }

        if (!empty($sameFieldMatches)) {
            usort($sameFieldMatches, function (array $left, array $right): int {
                return $right['score'] <=> $left['score'];
            });

            return $sameFieldMatches[0];
        }

        // Ultimo livello: i termini possono essere distribuiti tra artista
        // e titolo, per esempio "mac circles" -> Mac Miller / Circles.
        // Proviamo entrambi gli ordini per non penalizzare la query inversa.
        $combinedForward = $this->analyzeTokenMatch(
            $queryTokens,
            array_merge($artistTokens, $titleTokens)
        );
        $combinedReverse = $this->analyzeTokenMatch(
            $queryTokens,
            array_merge($titleTokens, $artistTokens)
        );

        $combinedMatch = $this->bestTokenAnalysis($combinedForward, $combinedReverse);

        if ($combinedMatch !== null) {
            return $this->tokenMatchResult(
                $combinedMatch,
                820,
                'combined',
                'tra artista e titolo'
            );
        }

        return null;
    }

    private function rankArtist(
        string $query,
        array $queryTokens,
        string $artist
    ): ?array {
        $artistNormalized = $this->normalizeSearchText($artist);
        $artistTokens = $this->tokenize($artistNormalized);

        if ($artistNormalized === $query) {
            return $this->match(1200, 'exact_artist', 'Corrispondenza esatta');
        }

        if ($this->containsPhrase($artistNormalized, $query)) {
            return $this->match(1100, 'phrase_artist', 'Frase completa');
        }

        $analysis = $this->analyzeTokenMatch($queryTokens, $artistTokens);

        if ($analysis === null) {
            return null;
        }

        return $this->tokenMatchResult(
            $analysis,
            1000,
            'artist',
            'nell’artista'
        );
    }

    /**
     * Confronta ogni token cercato con un token distinto del candidato.
     * Sono ammessi soltanto:
     * - token identici;
     * - prefissi che iniziano dal primo carattere del token candidato.
     *
     * La ricerca preferisce sempre un token esatto. I prefissi brevi
     * (2-3 caratteri) sono ammessi solo se un altro termine significativo
     * della query ha già una corrispondenza esatta.
     */
    private function analyzeTokenMatch(array $queryTokens, array $targetTokens): ?array {
        if (empty($queryTokens) || empty($targetTokens)) {
            return null;
        }

        $usedTargetIndexes = [];
        $positions = [];
        $exactCount = 0;
        $prefixCount = 0;
        $exactAnchorCount = $this->countExactAnchors($queryTokens, $targetTokens);

        foreach ($queryTokens as $queryToken) {
            $matchedIndex = $this->findExactTokenIndex(
                $queryToken,
                $targetTokens,
                $usedTargetIndexes
            );

            if ($matchedIndex !== null) {
                $usedTargetIndexes[$matchedIndex] = true;
                $positions[] = $matchedIndex;
                $exactCount++;
                continue;
            }

            $matchedIndex = $this->findPrefixTokenIndex(
                $queryToken,
                $targetTokens,
                $usedTargetIndexes,
                $exactAnchorCount
            );

            if ($matchedIndex === null) {
                return null;
            }

            $usedTargetIndexes[$matchedIndex] = true;
            $positions[] = $matchedIndex;
            $prefixCount++;
        }

        $inOrder = $this->positionsAreInOrder($positions);
        $contiguous = $inOrder && $this->positionsAreContiguous($positions);
        $startsAtBeginning = !empty($positions) && $positions[0] === 0;

        return [
            'exact_count'        => $exactCount,
            'prefix_count'       => $prefixCount,
            'in_order'           => $inOrder,
            'contiguous'         => $contiguous,
            'starts_at_beginning'=> $startsAtBeginning,
            'target_token_count' => count($targetTokens),
            'query_token_count'  => count($queryTokens),
        ];
    }

    private function countExactAnchors(array $queryTokens, array $targetTokens): int {
        $targetLookup = array_fill_keys($targetTokens, true);
        $count = 0;

        foreach ($queryTokens as $token) {
            if (isset($targetLookup[$token]) && !$this->isStopWord($token)) {
                $count++;
            }
        }

        return $count;
    }

    private function findExactTokenIndex(
        string $queryToken,
        array $targetTokens,
        array $usedTargetIndexes
    ): ?int {
        foreach ($targetTokens as $index => $targetToken) {
            if (isset($usedTargetIndexes[$index])) {
                continue;
            }

            if ($queryToken === $targetToken) {
                return (int)$index;
            }
        }

        return null;
    }

    private function findPrefixTokenIndex(
        string $queryToken,
        array $targetTokens,
        array $usedTargetIndexes,
        int $exactAnchorCount
    ): ?int {
        if (!$this->prefixMayExpand($queryToken, $exactAnchorCount)) {
            return null;
        }

        $bestIndex = null;
        $bestLengthDifference = PHP_INT_MAX;

        foreach ($targetTokens as $index => $targetToken) {
            if (isset($usedTargetIndexes[$index])) {
                continue;
            }

            if (!$this->startsWith($targetToken, $queryToken)) {
                continue;
            }

            // Un token uguale sarebbe già stato intercettato dal passaggio
            // degli exact match; qui consideriamo soltanto prefissi reali.
            if ($targetToken === $queryToken) {
                continue;
            }

            $lengthDifference = $this->textLength($targetToken)
                - $this->textLength($queryToken);

            if ($lengthDifference < $bestLengthDifference) {
                $bestLengthDifference = $lengthDifference;
                $bestIndex = (int)$index;
            }
        }

        return $bestIndex;
    }

    /**
     * Politica di espansione dei prefissi:
     * - 4+ caratteri: sempre ammessi (snap -> snapcase);
     * - 2-3 caratteri: ammessi solo con almeno un altro token esatto
     *   e significativo (pink fl -> pink floyd);
     * - 1 carattere: mai ammesso come prefisso.
     */
    private function prefixMayExpand(string $queryToken, int $exactAnchorCount): bool {
        $length = $this->textLength($queryToken);

        if ($length >= self::MIN_STANDALONE_PREFIX_LENGTH) {
            return true;
        }

        return $length >= self::MIN_ANCHORED_PREFIX_LENGTH
            && $exactAnchorCount > 0;
    }

    private function tokenMatchResult(
        array $analysis,
        int $baseScore,
        string $scope,
        string $scopeLabel
    ): array {
        $hasPrefixes = $analysis['prefix_count'] > 0;

        $score = $baseScore;
        $score += $analysis['exact_count'] * 35;
        $score += $analysis['prefix_count'] * 15;

        if ($analysis['contiguous']) {
            $score += 55;
        } elseif ($analysis['in_order']) {
            $score += 25;
        }

        if ($analysis['starts_at_beginning']) {
            $score += 15;
        }

        // A parità di qualità, privilegia il nome/titolo più vicino alla
        // quantità di termini cercati.
        $extraTokens = max(
            0,
            $analysis['target_token_count'] - $analysis['query_token_count']
        );
        $score -= min(20, $extraTokens * 2);

        if ($hasPrefixes) {
            $type = 'prefix_' . $scope;
            $label = $analysis['contiguous']
                ? 'Prefisso ' . $scopeLabel
                : 'Termini e prefissi ' . $scopeLabel;
        } else {
            $type = 'tokens_' . $scope;
            $label = $analysis['contiguous']
                ? 'Termini consecutivi ' . $scopeLabel
                : 'Tutti i termini ' . $scopeLabel;
        }

        return $this->match($score, $type, $label);
    }

    private function bestTokenAnalysis(?array $left, ?array $right): ?array {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        $leftQuality = $this->tokenAnalysisQuality($left);
        $rightQuality = $this->tokenAnalysisQuality($right);

        return $leftQuality >= $rightQuality ? $left : $right;
    }

    private function tokenAnalysisQuality(array $analysis): int {
        $quality = $analysis['exact_count'] * 100;
        $quality += $analysis['prefix_count'] * 40;
        $quality += $analysis['contiguous'] ? 30 : 0;
        $quality += $analysis['in_order'] ? 15 : 0;
        $quality += $analysis['starts_at_beginning'] ? 5 : 0;

        return $quality;
    }

    private function positionsAreInOrder(array $positions): bool {
        $previous = -1;

        foreach ($positions as $position) {
            if ($position <= $previous) {
                return false;
            }

            $previous = $position;
        }

        return true;
    }

    private function positionsAreContiguous(array $positions): bool {
        if (count($positions) <= 1) {
            return true;
        }

        for ($index = 1, $count = count($positions); $index < $count; $index++) {
            if ($positions[$index] !== $positions[$index - 1] + 1) {
                return false;
            }
        }

        return true;
    }

    private function startsWith(string $text, string $prefix): bool {
        if ($prefix === '') {
            return false;
        }

        return strpos($text, $prefix) === 0;
    }

    private function isStopWord(string $token): bool {
        static $stopWords = [
            'a' => true, 'an' => true, 'and' => true, 'the' => true,
            'of' => true, 'in' => true, 'on' => true, 'for' => true,
            'il' => true, 'lo' => true, 'la' => true, 'i' => true,
            'gli' => true, 'le' => true, 'un' => true, 'uno' => true,
            'una' => true, 'di' => true, 'del' => true, 'della' => true,
            'dei' => true, 'degli' => true, 'delle' => true, 'e' => true,
            'de' => true, 'des' => true, 'du' => true, 'les' => true,
        ];

        return isset($stopWords[$token]);
    }

    private function match(int $score, string $type, string $label): array {
        return [
            'score' => $score,
            'type'  => $type,
            'label' => $label,
        ];
    }

    /**
     * Confronto di frase su testo già normalizzato.
     * Gli spazi sentinella impediscono match dentro una singola parola.
     */
    private function containsPhrase(string $text, string $phrase): bool {
        if ($text === '' || $phrase === '') {
            return false;
        }

        return strpos(' ' . $text . ' ', ' ' . $phrase . ' ') !== false;
    }

    /**
     * Normalizza maiuscole, accenti, punteggiatura e spazi.
     * Esempi:
     * - "Sonic   Youth" -> "sonic youth"
     * - "AC/DC"         -> "ac dc"
     * - "Beyoncé"       -> "beyonce"
     */
    private function normalizeSearchText(string $value): string {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }

    private function tokenize(string $normalizedText): array {
        if ($normalizedText === '') {
            return [];
        }

        $tokens = explode(' ', $normalizedText);
        $tokens = array_filter($tokens, function (string $token): bool {
            return $token !== '';
        });

        return array_values(array_unique($tokens));
    }

    private function textLength(string $value): int {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}
