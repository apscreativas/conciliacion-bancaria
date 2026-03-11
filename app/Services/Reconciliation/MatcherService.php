<?php

namespace App\Services\Reconciliation;

use App\Models\Conciliacion;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class MatcherService
{
    private DescriptionParser $parser;

    public function __construct()
    {
        $this->parser = new DescriptionParser;
    }

    /**
     * Find matches for a given team within a specific Month/Year.
     * Score 0-100% based on three equal pillars: Amount (33), Date (33), Description (34).
     */
    public function findMatches(int $teamId, float $toleranceAmount, int $month, int $year): array
    {
        $team = Team::find($teamId);
        $teamRfc = $team?->rfc;

        $maxRecords = 5000;

        $unreconciledInvoices = Factura::where('team_id', $teamId)
            ->whereMonth('fecha_emision', $month)
            ->whereYear('fecha_emision', $year)
            ->doesntHave('conciliaciones')
            ->limit($maxRecords)
            ->get();

        $unreconciledMovements = Movimiento::where('team_id', $teamId)
            ->whereMonth('fecha', $month)
            ->whereYear('fecha', $year)
            ->where(function ($query) {
                $query->where('tipo', 'abono')
                    ->orWhere('tipo', 'Abono');
            })
            ->doesntHave('conciliaciones')
            ->limit($maxRecords)
            ->get();

        // Pre-parse all movement descriptions once
        $parsedDescriptions = [];
        foreach ($unreconciledMovements as $movement) {
            $parsedDescriptions[$movement->id] = $this->parser->parse(
                $movement->descripcion ?? '',
                $teamRfc
            );
        }

        $matches = [];

        foreach ($unreconciledInvoices as $invoice) {
            foreach ($unreconciledMovements as $movement) {
                $diffAmount = abs($invoice->monto - $movement->monto);

                if ($diffAmount > $toleranceAmount) {
                    continue;
                }

                $parsed = $parsedDescriptions[$movement->id];
                $reasons = [];

                // === Pillar 1: Amount exactness (0-33) ===
                // Exact match = 33, at tolerance edge = 0
                if ($toleranceAmount > 0) {
                    $amountScore = (int) round((1 - $diffAmount / $toleranceAmount) * 33);
                } else {
                    $amountScore = $diffAmount == 0 ? 33 : 0;
                }

                // === Pillar 2: Date proximity (0-33) ===
                // Same day = 33, 30+ days apart = 0
                $daysDiff = abs($invoice->fecha_emision->diffInDays($movement->fecha, false));
                $dateScore = max(0, (int) round((1 - $daysDiff / 30) * 33));

                // === Pillar 3: Description evidence (0-34) ===
                // Best of RFC, UUID, or Name match (they don't stack)
                $descriptionScore = 0;

                // RFC match: full 34 if found
                $invoiceRfc = $invoice->rfc ? strtoupper($invoice->rfc) : null;
                if ($invoiceRfc && ! empty($parsed['rfcs'])) {
                    foreach ($parsed['rfcs'] as $descRfc) {
                        if ($descRfc === $invoiceRfc) {
                            $descriptionScore = 34;
                            $reasons[] = 'rfc';
                            break;
                        }
                    }
                }

                // UUID match: full 34 if found
                $invoiceUuid = $invoice->uuid ? strtoupper($invoice->uuid) : null;
                if ($descriptionScore < 34 && $invoiceUuid && ! empty($parsed['uuid_fragments'])) {
                    foreach ($parsed['uuid_fragments'] as $fragment) {
                        $uuidNoDashes = str_replace('-', '', $invoiceUuid);
                        $fragmentNoDashes = str_replace('-', '', $fragment);
                        if (str_contains($uuidNoDashes, $fragmentNoDashes) || str_contains($fragmentNoDashes, $uuidNoDashes)) {
                            $descriptionScore = 34;
                            $reasons[] = 'uuid';
                            break;
                        }
                    }
                }

                // Name match: full 34 if any name tokens match
                if ($descriptionScore < 34 && ! empty($parsed['name_tokens'])) {
                    $invoiceName = $invoice->nombre ?? $invoice->nombre_emisor ?? '';
                    $nameRatio = $this->parser->nameMatchScore($parsed['name_tokens'], $invoiceName);
                    if ($nameRatio > 0) {
                        $descriptionScore = 34;
                        $reasons[] = 'nombre';
                    }
                }

                // Total score capped at 100
                $score = min($amountScore + $dateScore + $descriptionScore, 100);

                // Confidence: High ≥80, Medium ≥50, Low <50
                $confidence = $score >= 80 ? 'high' : ($score >= 50 ? 'medium' : 'low');

                $matches[] = [
                    'invoice' => $invoice,
                    'movement' => $movement,
                    'score' => $score,
                    'difference' => $diffAmount,
                    'confidence' => $confidence,
                    'match_reasons' => $reasons,
                ];
            }
        }

        // Sort by score desc
        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Deduplicate: each invoice and movement used only once (best score wins)
        $uniqueMatches = [];
        $usedInvoiceIds = [];
        $usedMovementIds = [];

        foreach ($matches as $match) {
            $invId = $match['invoice']->id;
            $movId = $match['movement']->id;

            if (! isset($usedInvoiceIds[$invId]) && ! isset($usedMovementIds[$movId])) {
                $uniqueMatches[] = $match;
                $usedInvoiceIds[$invId] = true;
                $usedMovementIds[$movId] = true;
            }
        }

        return $uniqueMatches;
    }

    /**
     * Execute a reconciliation match.
     */
    public function reconcile(array $invoiceIds, array $movementIds, string $type = 'manual', ?string $date = null): void
    {
        DB::transaction(function () use ($invoiceIds, $movementIds, $type, $date) {
            $teamId = auth()->user()->current_team_id;
            $groupId = \Illuminate\Support\Str::uuid();

            $invoices = Factura::where('team_id', $teamId)->lockForUpdate()->findMany($invoiceIds);
            $movements = Movimiento::where('team_id', $teamId)->lockForUpdate()->findMany($movementIds);

            if ($invoices->count() !== count($invoiceIds) || $movements->count() !== count($movementIds)) {
                // If counts don't match, some IDs were invalid or belong to another team
                throw new \Exception('Invalid or unauthorized records selected.');
            }

            // Calculate totals
            $totalInvoices = $invoices->sum('monto');
            $totalMovements = $movements->sum('monto');

            // Logic: We create one Conciliacion record per pair?
            // Or one Conciliacion record linking M invoices to N movements?
            // The schema 'conciliacions' has 'factura_id' and 'movimiento_id' as Foreign Keys.
            // This implies Many-to-Many via the table itself (Association Entity).

            // Strategy: Link every invoice to every movement proportionally?
            // Or if it's 1-to-1, simple.
            // If 1-to-N (1 Invoice, 2 Payments): Link Invoice to Pay1, Invoice to Pay2.

            // Calculate total available amounts to prevent over-application
            $invoiceRemaining = [];
            foreach ($invoices as $inv) {
                $invoiceRemaining[$inv->id] = $inv->monto;
            }

            $movementRemaining = [];
            foreach ($movements as $mov) {
                $movementRemaining[$mov->id] = $mov->monto;
            }

            // Use a small epsilon to handle floating-point precision issues
            // e.g. 100.00 - 50.00 - 50.00 might yield 0.00000000001 instead of 0
            $epsilon = 0.001;

            foreach ($invoices as $invoice) {
                // If invoice is fully paid, skip
                if ($invoiceRemaining[$invoice->id] < $epsilon) {
                    continue;
                }

                foreach ($movements as $movement) {
                    // If movement is fully used, skip
                    if ($movementRemaining[$movement->id] < $epsilon) {
                        continue;
                    }

                    // Determine match amount based on remaining balances
                    $amountToApply = min($invoiceRemaining[$invoice->id], $movementRemaining[$movement->id]);

                    if ($amountToApply >= $epsilon) {
                        // Round to 2 decimal places to avoid float drift accumulation
                        $amountToApply = round($amountToApply, 2);

                        Conciliacion::create([
                            'group_id' => $groupId,
                            'user_id' => auth()->id(),
                            'team_id' => auth()->user()->current_team_id,
                            'factura_id' => $invoice->id,
                            'movimiento_id' => $movement->id,
                            'monto_aplicado' => $amountToApply,
                            'tipo' => $type,
                            'estatus' => 'conciliado',
                            'fecha_conciliacion' => $date ?? now(),
                        ]);

                        // Deduct applied amount from both sides
                        $invoiceRemaining[$invoice->id] = round($invoiceRemaining[$invoice->id] - $amountToApply, 2);
                        $movementRemaining[$movement->id] = round($movementRemaining[$movement->id] - $amountToApply, 2);

                        // Stop checking movements for this invoice if it's fully paid
                        if ($invoiceRemaining[$invoice->id] < $epsilon) {
                            break;
                        }
                    }
                }
            }
        });
    }
}
