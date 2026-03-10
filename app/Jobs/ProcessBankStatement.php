<?php

namespace App\Jobs;

use App\Models\Archivo;
use App\Models\Movimiento;
use App\Services\Parsers\StatementParserFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessBankStatement implements ShouldQueue
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
    public function handle(): void
    {
        try {
            $this->archivo->update(['estatus' => 'procesando']);

            $fullPath = Storage::path($this->archivo->path);

            // Retrieve Bank via Archivo relationship
            $this->archivo->load('banco', 'bankFormat');

            if (! $this->archivo->banco) {
                throw new \Exception('El archivo no tiene un banco asociado.');
            }

            $parser = null;

            // 1. Try custom format first
            if ($this->archivo->bank_format_id) {
                // Pass the ID directly to the factory which handles dynamic formats
                $parser = StatementParserFactory::make((string) $this->archivo->bank_format_id, $this->teamId);
            }

            // 2. Fallback to Bank Code
            if (! $parser) {
                // ...

                $parser = StatementParserFactory::make($this->archivo->banco->codigo, $this->teamId);
            }

            $movements = $parser->parse($fullPath);

            if (empty($movements)) {
                throw new \Exception('El archivo fue procesado pero no se encontraron movimientos válidos. Verifique la configuración del formato.');
            }

            DB::transaction(function () use ($movements) {
                foreach ($movements as $movData) {
                    // Generate hash for duplicate detection.
                    // Use SHA256 with json_encode to avoid collisions from string concatenation
                    // (e.g. fecha="2024-01-1" monto="01000" vs fecha="2024-01-10" monto="1000").
                    $hash = hash('sha256', json_encode([
                        'fecha' => $movData['fecha'],
                        'monto' => $movData['monto'],
                        'referencia' => $movData['referencia'],
                        'descripcion' => $movData['descripcion'],
                    ]));

                    $exists = Movimiento::where('team_id', $this->teamId)
                        ->where('hash', $hash)
                        ->exists();

                    if (! $exists) {
                        Movimiento::create([
                            'user_id' => $this->userId,
                            'team_id' => $this->teamId,
                            'banco_id' => $this->archivo->banco_id,
                            'file_id' => $this->archivo->id,
                            'fecha' => $movData['fecha'],
                            'monto' => $movData['monto'],
                            'tipo' => $movData['tipo'],
                            'referencia' => $movData['referencia'],
                            'descripcion' => $movData['descripcion'],
                            'hash' => $hash,
                        ]);
                    }
                }

                $this->archivo->update(['estatus' => 'procesado']);
            });

        } catch (\Throwable $e) {
            Log::error("Error processing Statement {$this->archivo->id}: ".$e->getMessage());
            $this->archivo->update(['estatus' => 'fallido']);
            $this->fail($e);
        }
    }
}
