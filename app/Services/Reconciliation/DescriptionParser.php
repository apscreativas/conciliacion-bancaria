<?php

namespace App\Services\Reconciliation;

class DescriptionParser
{
    /**
     * Noise words that should be stripped before extracting name tokens.
     * These are common bank transaction prefixes, bank names, and generic terms.
     */
    private const NOISE_WORDS = [
        'SPEI', 'RECIBIDO', 'RECIBIDA', 'TRANSFERENCIA', 'PAGO', 'PAGOS',
        'CUENTA', 'TERCERO', 'TERCEROS', 'BNET', 'DEPOSITO', 'DEPOSITOS',
        'DEPOSITAR', 'EFECTIVO', 'PRACTIC', 'COBRO', 'ABONO', 'CARGO',
        'REF', 'REFERENCIA', 'FOLIO', 'SUC', 'SUCURSAL', 'TERM', 'AUT',
        'BBVA', 'SANTANDER', 'BANAMEX', 'BANORTE', 'HSBC', 'SCOTIABANK',
        'INBURSA', 'BANCOPPEL', 'AFIRME', 'BANREGIO', 'BAJIO', 'AZTECA',
        'MULTIVA', 'MIFEL', 'INTERCAM', 'MONEX', 'COMPARTAMOS',
        'ELECTRONICA', 'ELECTRONICO', 'BANCARIA', 'BANCARIO',
        'APP', 'CELULAR', 'LINEA', 'INTERNET', 'MOVIL',
        'BMRCASH', 'PAYMENY', 'EMPLOYEE', 'TIME', 'CLOCK',
        'FAC', 'FACT', 'FACTURA',
    ];

    /**
     * Parse a bank movement description and extract identifying information.
     */
    public function parse(string $description, ?string $teamRfc = null): array
    {
        $normalized = $this->normalize($description);

        return [
            'rfcs' => $this->extractRfcs($normalized, $teamRfc),
            'uuid_fragments' => $this->extractUuidFragments($normalized),
            'name_tokens' => $this->extractNameTokens($normalized, $teamRfc),
        ];
    }

    /**
     * Extract RFC patterns from text.
     * Company: 3 letters + 6 digits + 3 alphanumeric (12 chars)
     * Individual: 4 letters + 6 digits + 3 alphanumeric (13 chars)
     */
    public function extractRfcs(string $text, ?string $teamRfc = null): array
    {
        $rfcs = [];

        // Individual RFC: 4 letters + 6 digits + 3 alphanumeric (13 chars)
        // Must check 13-char pattern first to avoid partial match on 12-char pattern
        if (preg_match_all('/\b([A-Z&Ñ]{4}\d{6}[A-Z0-9]{3})\b/u', $text, $matches)) {
            $rfcs = array_merge($rfcs, $matches[1]);
        }

        // Company RFC: 3 letters + 6 digits + 3 alphanumeric (12 chars)
        if (preg_match_all('/\b([A-Z&Ñ]{3}\d{6}[A-Z0-9]{3})\b/u', $text, $matches)) {
            foreach ($matches[1] as $match) {
                // Skip if this is already captured as part of a 13-char RFC
                $alreadyCaptured = false;
                foreach ($rfcs as $existing) {
                    if (str_contains($existing, $match)) {
                        $alreadyCaptured = true;
                        break;
                    }
                }
                if (! $alreadyCaptured) {
                    $rfcs[] = $match;
                }
            }
        }

        // Filter out the team's own RFC — it appears in every invoice and has no disambiguation value
        if ($teamRfc) {
            $teamRfcUpper = strtoupper($teamRfc);
            $rfcs = array_values(array_filter($rfcs, fn ($rfc) => $rfc !== $teamRfcUpper));
        }

        return array_values(array_unique($rfcs));
    }

    /**
     * Extract UUID fragments (hex patterns ≥6 chars that look like parts of a CFDI UUID).
     * UUIDs are 8-4-4-4-12 hex digits with dashes.
     */
    public function extractUuidFragments(string $text): array
    {
        $fragments = [];

        // Full or near-full UUIDs with dashes
        if (preg_match_all('/[0-9A-F]{8}(?:-[0-9A-F]{4}){1,3}(?:-[0-9A-F]{1,12})?/i', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $fragments[] = strtoupper($match);
            }
        }

        // Standalone hex blocks ≥6 chars (common in truncated descriptions)
        // Must not overlap with already-found fragments or look like RFCs/account numbers
        if (preg_match_all('/\b([0-9A-F]{6,12})\b/i', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $upper = strtoupper($match);

                // Skip if purely numeric (likely account/reference number, not UUID)
                if (ctype_digit($match)) {
                    continue;
                }

                // Skip if already captured as part of a longer fragment
                $alreadyCaptured = false;
                foreach ($fragments as $existing) {
                    if (str_contains($existing, $upper)) {
                        $alreadyCaptured = true;
                        break;
                    }
                }

                if (! $alreadyCaptured) {
                    $fragments[] = $upper;
                }
            }
        }

        return array_values(array_unique($fragments));
    }

    /**
     * Extract potential name tokens from description after stripping noise.
     * Returns uppercase words that might correspond to a person or company name.
     */
    public function extractNameTokens(string $text, ?string $teamRfc = null): array
    {
        // Remove RFC patterns (already extracted separately)
        $cleaned = preg_replace('/\b[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}\b/u', '', $text);

        // Remove hex-looking UUID fragments
        $cleaned = preg_replace('/\b[0-9A-F]{6,}\b/i', '', $cleaned);

        // Remove UUID patterns with dashes
        $cleaned = preg_replace('/[0-9A-F]{8}(?:-[0-9A-F]{4}){1,3}(?:-[0-9A-F]{1,12})?/i', '', $cleaned);

        // Remove pure numbers and account-like patterns
        $cleaned = preg_replace('/\b\d+\b/', '', $cleaned);

        // Remove slashes and special chars, keep spaces and letters
        $cleaned = preg_replace('/[\/\*\#\-\_\.\,\(\)]/', ' ', $cleaned);

        // Split into words
        $words = preg_split('/\s+/', trim($cleaned));

        // Filter out noise words and short tokens
        $tokens = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) < 3) {
                continue;
            }
            if (in_array($word, self::NOISE_WORDS, true)) {
                continue;
            }
            // Skip if it looks like the team RFC
            if ($teamRfc && $word === strtoupper($teamRfc)) {
                continue;
            }
            $tokens[] = $word;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Normalize text: uppercase, strip accents, collapse whitespace.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtoupper($text, 'UTF-8');

        // Strip accents
        $text = strtr($text, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
        ]);

        // Collapse whitespace
        return preg_replace('/\s+/', ' ', trim($text));
    }

    /**
     * Calculate fuzzy name similarity between description tokens and an invoice name.
     * Returns a score from 0.0 to 1.0.
     */
    public function nameMatchScore(array $descriptionTokens, string $invoiceName): float
    {
        if (empty($descriptionTokens) || empty($invoiceName)) {
            return 0.0;
        }

        $normalizedName = $this->normalize($invoiceName);
        $nameWords = preg_split('/\s+/', $normalizedName);

        // Filter short/noise words from invoice name too
        $nameWords = array_filter($nameWords, fn ($w) => strlen($w) >= 3 && ! in_array($w, self::NOISE_WORDS, true));
        $nameWords = array_values($nameWords);

        if (empty($nameWords)) {
            return 0.0;
        }

        // Count how many description tokens appear in the invoice name
        $matchCount = 0;
        foreach ($descriptionTokens as $token) {
            foreach ($nameWords as $nameWord) {
                // Exact word match or one contains the other (for abbreviations)
                if ($token === $nameWord || str_contains($nameWord, $token) || str_contains($token, $nameWord)) {
                    $matchCount++;
                    break;
                }
            }
        }

        if ($matchCount === 0) {
            return 0.0;
        }

        // Score based on proportion of invoice name words matched
        // Require at least 2 matching tokens or >50% of invoice name words
        $ratio = $matchCount / count($nameWords);

        if ($matchCount >= 2 || $ratio > 0.5) {
            return min($ratio, 1.0);
        }

        // Single word match with short name (1-2 words) — still useful
        if (count($nameWords) <= 2 && $matchCount >= 1) {
            return $ratio * 0.8; // Slightly discounted
        }

        return 0.0;
    }
}
