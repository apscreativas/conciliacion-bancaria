<?php

namespace App\Services\Reconciliation;

use App\Models\Conciliacion;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatcherService
{
    private DescriptionParser $parser;

    public function __construct()
    {
        $this->parser = new DescriptionParser;
    }

    /**
     * Find matches for a given team within a specific Month/Year.
     * Uses multi-signal scoring: amount uniqueness, RFC, UUID, name, date, exactness.
     */
    public function findMatches(int $teamId, float $toleranceAmount, int $month, int $year): array
    {
        $team = Team::find($teamId);
        $teamRfc = $team?->rfc;

        $unreconciledInvoices = Factura::where('team_id', $teamId)
            ->whereMonth('fecha_emision', $month)
            ->whereYear('fecha_emision', $year)
            ->doesntHave('conciliaciones')
            ->get();

        $unreconciledMovements = Movimiento::where('team_id', $teamId)
            ->whereMonth('fecha', $month)
            ->whereYear('fecha', $year)
            ->where(function ($query) {
                $query->where('tipo', 'abono')
                    ->orWhere('tipo', 'Abono');
            })
            ->doesntHave('conciliaciones')
            ->get();

        // Pre-parse all movement descriptions once
        $parsedDescriptions = [];
        foreach ($unreconciledMovements as $movement) {
            $parsedDescriptions[$movement->id] = $this->parser->parse(
                $movement->descripcion ?? '',
                $teamRfc
            );
        }

        // Build amount frequency map for uniqueness detection
        // Count how many invoices match each movement's amount (within tolerance)
        $amountMatchCounts = [];
        foreach ($unreconciledMovements as $movement) {
            $count = 0;
            foreach ($unreconciledInvoices as $invoice) {
                if (abs($invoice->monto - $movement->monto) <= $toleranceAmount) {
                    $count++;
                }
            }
            $amountMatchCounts[$movement->id] = $count;
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

                // Base score: every match that passes the amount filter gets +50
                $score = 50;

                // 1. Amount uniqueness: +20 bonus if only ONE invoice matches this movement
                if ($amountMatchCounts[$movement->id] === 1) {
                    $score += 20;
                    $reasons[] = 'monto_unico';
                }

                // 2. RFC match: +30 if movement description contains the invoice's RFC
                $invoiceRfc = $invoice->rfc ? strtoupper($invoice->rfc) : null;
                if ($invoiceRfc && ! empty($parsed['rfcs'])) {
                    foreach ($parsed['rfcs'] as $descRfc) {
                        if ($descRfc === $invoiceRfc) {
                            $score += 30;
                            $reasons[] = 'rfc';
                            break;
                        }
                    }
                }

                // 3. UUID match: +25 if movement description contains a fragment of the invoice UUID
                $invoiceUuid = $invoice->uuid ? strtoupper($invoice->uuid) : null;
                if ($invoiceUuid && ! empty($parsed['uuid_fragments'])) {
                    foreach ($parsed['uuid_fragments'] as $fragment) {
                        $uuidNoDashes = str_replace('-', '', $invoiceUuid);
                        $fragmentNoDashes = str_replace('-', '', $fragment);
                        if (str_contains($uuidNoDashes, $fragmentNoDashes) || str_contains($fragmentNoDashes, $uuidNoDashes)) {
                            $score += 25;
                            $reasons[] = 'uuid';
                            break;
                        }
                    }
                }

                // 4. Name fuzzy match: up to +15
                if (! empty($parsed['name_tokens'])) {
                    $invoiceName = $invoice->nombre ?? $invoice->nombre_emisor ?? '';
                    $nameScore = $this->parser->nameMatchScore($parsed['name_tokens'], $invoiceName);
                    if ($nameScore > 0) {
                        $score += (int) round($nameScore * 15);
                        $reasons[] = 'nombre';
                    }
                }

                // 5. Date proximity: 0-20 (same day = 20, 31 days apart = 0)
                $daysDiff = abs($invoice->fecha_emision->diffInDays($movement->fecha, false));
                $dateScore = max(0, 20 - (int) round($daysDiff * 20 / 31));
                $score += $dateScore;

                // 6. Amount exactness: 0-10 (exact = 10, at tolerance edge = 0)
                if ($toleranceAmount > 0) {
                    $exactnessScore = (int) round((1 - $diffAmount / $toleranceAmount) * 10);
                } else {
                    $exactnessScore = $diffAmount == 0 ? 10 : 0;
                }
                $score += $exactnessScore;

                // Confidence: High ≥100, Medium ≥50, Low <50
                $confidence = $score >= 100 ? 'high' : ($score >= 50 ? 'medium' : 'low');

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

            $invoices = Factura::where('team_id', $teamId)->findMany($invoiceIds);
            $movements = Movimiento::where('team_id', $teamId)->findMany($movementIds);

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
