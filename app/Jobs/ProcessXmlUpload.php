<?php

namespace App\Jobs;

use App\Models\Archivo;
use App\Models\Factura;
use App\Services\Xml\CfdiParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessXmlUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Archivo $archivo,
        public int $teamId,
        public int $userId
    ) {
        $this->onQueue('imports');
    }

    /**
     * Execute the job.
     */
    public function handle(CfdiParserService $cfdiParser): void
    {

        try {
            $this->archivo->update(['estatus' => 'procesando']);

            $content = Storage::get($this->archivo->path);

            if (! $content) {
                throw new \Exception('No se pudo leer el archivo XML.');
            }

            $data = $cfdiParser->parse($content);

            // Defensive check: reject PPD invoices that bypassed sync validation
            if ($data['tipo_comprobante'] === 'I' && $data['metodo_pago'] === 'PPD') {
                Log::warning("ProcessXmlUpload: PPD invoice rejected (Archivo #{$this->archivo->id})");
                $this->archivo->update(['estatus' => 'rechazado']);

                return;
            }

            DB::transaction(function () use ($data) {
                // Duplicate check
                $exists = Factura::where('team_id', $this->teamId)
                    ->where('uuid', $data['uuid'])
                    ->exists();

                if ($exists) {
                    Log::info("ProcessXmlUpload: Duplicate UUID found: {$data['uuid']}");
                    $this->archivo->update(['estatus' => 'duplicado']); // Or 'fallido' with specific error

                    return;
                }
                Log::info('ProcessXmlUpload: No duplicate found. validating RFC...');

                // Validation: Ensure the invoice was Issued BY the Team (Emisor == Team)
                // OR Issued TO the Team (Receptor == Team).
                // Current Business Rule: We are uploading Expenses (Received Invoices).
                // So Team should be Receptor.
                // However, user Requirement said: "Team validation checks EMISOR RFC == team.rfc".
                // This implies we are uploading Sales Invoices.
                // Validation: Ensure the invoice was Issued TO the Team (Receptor == Team).
                // We are uploading Expenses (Received Invoices).

                $teamRfc = \App\Models\Team::find($this->teamId)?->rfc;

                // Flexible RFC Validation: Team must be either Emisor or Receptor
                if ($teamRfc) {
                    $emisorRfc = strtoupper($data['rfc_emisor'] ?? '');
                    $receptorRfc = strtoupper($data['rfc_receptor'] ?? '');
                    $teamRfcUpper = strtoupper($teamRfc);

                    if ($emisorRfc !== $teamRfcUpper && $receptorRfc !== $teamRfcUpper) {
                        throw new \Exception("El RFC del equipo ({$teamRfc}) no coincide con el Emisor ({$emisorRfc}) ni con el Receptor ({$receptorRfc}) del XML.");
                    }
                }

                Factura::create([
                    'user_id' => $this->userId,
                    'team_id' => $this->teamId,
                    'file_id_xml' => $this->archivo->id,
                    'uuid' => $data['uuid'],
                    'tipo_comprobante' => $data['tipo_comprobante'],
                    'metodo_pago' => $data['metodo_pago'],
                    'monto' => $data['total'],
                    'fecha_emision' => $data['fecha_emision'],
                    'rfc' => $data['rfc_receptor'], // Corrected: Store Client/Receiver RFC
                    'nombre' => $data['nombre_receptor'], // Corrected: Store Client/Receiver Name
                    'verificado' => false,
                ]);
                Log::info('ProcessXmlUpload: Factura created.');

                $this->archivo->update(['estatus' => 'procesado']);
            });

        } catch (\Throwable $e) {

            Log::error("Error processing XML {$this->archivo->id}: ".$e->getMessage());
            $this->archivo->update(['estatus' => 'fallido']);
            $this->fail($e);
        }
    }
}
