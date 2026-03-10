<?php

namespace App\Console\Commands;

use App\Models\Factura;
use App\Services\Xml\CfdiParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackfillCfdiTypes extends Command
{
    protected $signature = 'facturas:backfill-cfdi-types
                            {--dry-run : Show what would happen without making changes}
                            {--delete-ppd : Delete PPD invoices and their reconciliations}';

    protected $description = 'Re-parse stored XMLs to backfill tipo_comprobante and metodo_pago columns, optionally deleting PPD invoices.';

    public function handle(CfdiParserService $cfdiParser): int
    {
        $dryRun = $this->option('dry-run');
        $deletePpd = $this->option('delete-ppd');

        if ($dryRun) {
            $this->info('--- DRY RUN MODE (no changes will be made) ---');
        }

        $facturas = Factura::with('archivoXml')
            ->whereNull('tipo_comprobante')
            ->get();

        $this->info("Found {$facturas->count()} facturas without tipo_comprobante.");

        $updated = 0;
        $deleted = 0;
        $failed = 0;
        $ppdWithReconciliation = 0;

        $bar = $this->output->createProgressBar($facturas->count());
        $bar->start();

        foreach ($facturas as $factura) {
            $bar->advance();

            $archivo = $factura->archivoXml;

            if (! $archivo || ! $archivo->path) {
                $this->newLine();
                $this->warn("Factura #{$factura->id} (UUID: {$factura->uuid}): No archivo found, skipping.");
                $failed++;

                continue;
            }

            try {
                $content = Storage::get($archivo->path);

                if (! $content) {
                    $this->newLine();
                    $this->warn("Factura #{$factura->id}: File not found at {$archivo->path}, skipping.");
                    $failed++;

                    continue;
                }

                $data = $cfdiParser->parse($content);
                $tipo = $data['tipo_comprobante'];
                $metodo = $data['metodo_pago'];

                $isPpd = $tipo === 'I' && $metodo === 'PPD';

                if ($isPpd && $deletePpd) {
                    $reconciliationCount = $factura->conciliaciones()->count();

                    if ($reconciliationCount > 0) {
                        $ppdWithReconciliation++;
                        $this->newLine();
                        $this->warn("Factura #{$factura->id} (UUID: {$factura->uuid}): PPD with {$reconciliationCount} reconciliation(s) — will be cascade-deleted.");
                    }

                    if (! $dryRun) {
                        $factura->delete(); // Cascade deletes conciliaciones
                        Log::info("BackfillCfdiTypes: Deleted PPD factura #{$factura->id} (UUID: {$factura->uuid})");
                    }

                    $deleted++;
                } else {
                    $updateData = [
                        'tipo_comprobante' => $tipo,
                        'metodo_pago' => $metodo,
                    ];

                    // For type P (Complemento de Pago), also fix monto and fecha_emision
                    // since the parser now extracts the real payment amount and date.
                    if ($tipo === 'P') {
                        $updateData['monto'] = $data['total'];
                        $updateData['fecha_emision'] = $data['fecha_emision'];
                    }

                    if (! $dryRun) {
                        $factura->update($updateData);
                    }

                    if ($tipo === 'P') {
                        $this->newLine();
                        $this->info("Factura #{$factura->id} (UUID: {$factura->uuid}): Type P — monto updated to \${$data['total']}, fecha to {$data['fecha_emision']}");
                    }

                    $updated++;
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Factura #{$factura->id}: Parse error — {$e->getMessage()}");
                $failed++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Results:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated', $updated],
                ['PPD Deleted', $deleted],
                ['PPD with reconciliations', $ppdWithReconciliation],
                ['Failed/Skipped', $failed],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a dry run. Re-run without --dry-run to apply changes.');
        }

        if ($ppdWithReconciliation > 0 && ! $dryRun) {
            $this->warn("WARNING: {$ppdWithReconciliation} PPD factura(s) had reconciliations that were cascade-deleted.");
            $this->warn('Those bank movements are now unreconciled and available for re-matching.');
        }

        return Command::SUCCESS;
    }
}
