<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBankStatement;
use App\Jobs\ProcessXmlUpload;
use App\Models\Archivo;
use App\Models\Banco;
use App\Models\Factura;
use App\Services\Parsers\StatementParserFactory;
use App\Services\Xml\CfdiParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    public function store(Request $request, CfdiParserService $cfdiParser)
    {
        // Global Try-Catch to prevent 500 errors
        try {
            $teamId = Auth::user()->current_team_id;
            $userId = Auth::id();
            $team = \App\Models\Team::find($teamId);
            $teamRfc = $team ? $team->rfc : null;

            $results = [
                'xml_processed' => 0,
                'xml_xml_duplicates' => 0,
                'xml_other_errors' => 0,
                'file_errors' => [],
            ];

            $toasts = [];

            // 1. Process XML Files (Facturas) - Hybrid (Sync Check + Async Process)
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    try {
                        // Validation
                        if ($file->getClientOriginalExtension() !== 'xml') {
                            $results['xml_other_errors']++;
                            $results['file_errors'][] = "Error ({$file->getClientOriginalName()}): No es un archivo XML.";

                            continue;
                        }

                        // Parse Synchronously for Feedback
                        try {
                            $content = file_get_contents($file->getRealPath());
                            $data = $cfdiParser->parse($content);
                        } catch (\Throwable $e) {
                            $results['xml_other_errors']++;
                            $results['file_errors'][] = "Error ({$file->getClientOriginalName()}): XML Inválido - ".$e->getMessage();

                            continue;
                        }

                        // Reject PPD invoices — user should upload Complemento de Pago instead
                        if ($data['tipo_comprobante'] === 'I' && $data['metodo_pago'] === 'PPD') {
                            $results['xml_other_errors']++;
                            $results['file_errors'][] = "Rechazado ({$file->getClientOriginalName()}): Esta factura es PPD (Pago en Parcialidades o Diferido). Suba el Complemento de Pago correspondiente.";

                            continue;
                        }

                        // Flexible RFC Validation: Team must be either Emisor or Receptor
                        if ($teamRfc) {
                            $emisorRfc = strtoupper($data['rfc_emisor'] ?? '');
                            $receptorRfc = strtoupper($data['rfc_receptor'] ?? '');
                            $teamRfcUpper = strtoupper($teamRfc);

                            if ($emisorRfc !== $teamRfcUpper && $receptorRfc !== $teamRfcUpper) {
                                $results['xml_other_errors']++;
                                $results['file_errors'][] = "Error ({$file->getClientOriginalName()}): El RFC del equipo ({$teamRfc}) no coincide con el Emisor ({$emisorRfc}) ni con el Receptor ({$receptorRfc}) del XML.";

                                continue; // Skip processing this file
                            }
                        }

                        // Check Duplicate (Sync)
                        // Note: We check against current team.
                        $exists = Factura::where('team_id', $teamId)->where('uuid', $data['uuid'])->exists();

                        if ($exists) {
                            $results['xml_xml_duplicates']++;
                            $results['file_errors'][] = "Duplicado ({$file->getClientOriginalName()}): Esta factura ya fue registrada anteriormente (UUID: {$data['uuid']}).";

                            continue;
                        }

                        // Store File
                        $path = $file->storeAs(
                            'uploads/teams/'.$teamId.'/xml',
                            Str::uuid().'_'.$file->getClientOriginalName()
                        );

                        // Create Archivo Record
                        $archivo = Archivo::create([
                            'user_id' => $userId,
                            'team_id' => $teamId,
                            'path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime' => $file->getMimeType(),
                            'size' => $file->getSize(),
                            'checksum' => md5_file($file->getRealPath()),
                            'estatus' => 'pendiente',
                        ]);

                        // Dispatch Job
                        ProcessXmlUpload::dispatch($archivo, $teamId, $userId);

                        $results['xml_processed']++; // Count as success/queued

                    } catch (\Throwable $e) {
                        Log::error('Error queuing XML: '.$e->getMessage());
                        $results['xml_other_errors']++;
                        $results['file_errors'][] = "Error interno ({$file->getClientOriginalName()}): ".$e->getMessage();
                    }
                }
            }

            // 2. Process Bank Statement (Movimientos) - ASYNC
            if ($request->hasFile('statement')) {
                try {
                    $file = $request->file('statement');
                    $bankCode = $request->input('bank_code'); // This is actually BankFormat ID from frontend
                    $bancoId = null;
                    $bankFormatId = null;

                    if (! $bankCode) {
                        throw new \Exception('Debe seleccionar un formato bancario.');
                    }

                    // 1. Resolve BankFormat
                    $format = \App\Models\BankFormat::find($bankCode);

                    if ($format) {
                        $bankFormatId = $format->id;

                        // 2. Heuristic: Find Banco by Name (e.g. "BBVA Excel" -> matches "BBVA")
                        // This is imperfect but bridges the gap since BankFormat is not linked to Banco in DB.
                        $banco = Banco::where('nombre', 'LIKE', '%'.$format->name.'%')
                            ->orWhere('nombre', 'LIKE', '%'.explode(' ', $format->name)[0].'%')
                            ->first();

                        if ($banco) {
                            $bancoId = $banco->id;
                        }
                    }

                    // 3. If we couldn't resolve a Bank via Format, fail explicitly.
                    // Silently assigning a wrong bank corrupts data, so we reject the upload instead.
                    if (! $bancoId) {
                        throw new \Exception("No se pudo determinar el banco para el formato seleccionado (ID: {$bankCode}). Configure un banco en el formato bancario.");
                    }

                    // --- SYNCHRONOUS VALIDATION ---
                    // Parse the file to ensure it matches the selected format BEFORE queuing/storing.
                    // IMPORTANT: We must store a temp file with the correct extension so the Parser detects it is Excel/CSV correctly.
                    $tempPath = null;
                    try {
                        $parserIdentifier = $bankFormatId ? (string) $bankFormatId : ($banco ? $banco->codigo : '');
                        $validatorParser = StatementParserFactory::make($parserIdentifier, $teamId);

                        // Store validation copy
                        $ext = $file->getClientOriginalExtension();
                        $tempName = 'validate_'.Str::random(10).'.'.$ext;
                        $tempPath = $file->storeAs('temp', $tempName);
                        $absPath = Storage::path($tempPath);

                        $previewMovements = $validatorParser->parse($absPath);

                        if (empty($previewMovements)) {
                            throw new \Exception('El archivo no contiene movimientos válidos para el formato seleccionado.');
                        }
                    } catch (\Throwable $e) {
                        // If validation fails, we stop here and return error.
                        // We verify "Invalid Format" synchronously.
                        Log::warning('FileUpload Validation Error: '.$e->getMessage());
                        $toasts[] = ['message' => 'Error de Validación: '.$e->getMessage(), 'type' => 'error'];
                        if ($request->wantsJson()) {
                            return response()->json([
                                'success' => false,
                                'results' => $results,
                                'toasts' => $toasts,
                            ], 422); // Return 422 for validation error
                        }

                        return back()->with('toasts', $toasts);
                    } finally {
                        if ($tempPath && Storage::exists($tempPath)) {
                            Storage::delete($tempPath);
                        }
                    }
                    // ------------------------------

                    // Store File
                    $path = $file->store('statements/'.$teamId);
                    $hash = md5_file($file->getRealPath());

                    // Check Duplicate File
                    $existingFile = Archivo::where('team_id', $teamId)
                        ->where('checksum', $hash)
                        ->where('estatus', '!=', 'fallido')
                        ->first();

                    if ($existingFile) {
                        $toasts[] = ['message' => 'Este estado de cuenta ya ha sido subido anteriormente.', 'type' => 'warning'];
                    } else {
                        // Create Archivo Record
                        $archivo = Archivo::create([
                            'user_id' => $userId,
                            'team_id' => $teamId,
                            'banco_id' => $bancoId,
                            'bank_format_id' => $bankFormatId,
                            'path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime' => $file->getMimeType(),
                            'size' => $file->getSize(),
                            'checksum' => $hash,
                            'estatus' => 'pendiente',
                        ]);

                        // Dispatch Job
                        ProcessBankStatement::dispatch($archivo, $teamId, $userId);

                        $toasts[] = ['message' => 'Estado de cuenta encolado para procesamiento.', 'type' => 'success'];
                    }

                } catch (\Throwable $e) {
                    Log::error('Error queuing statement: '.$e->getMessage());
                    $toasts[] = ['message' => 'Error al procesar estado de cuenta: '.$e->getMessage(), 'type' => 'error'];
                }
            }

            if ($request->wantsJson()) {
                // success = true only if at least one file was processed (or a statement was queued).
                // If every uploaded file failed, we return success=false so the frontend can react.
                $statementQueued = collect($toasts)->contains(fn ($t) => $t['type'] === 'success');
                $anySuccess = $results['xml_processed'] > 0 || $statementQueued || $results['xml_xml_duplicates'] > 0;

                return response()->json([
                    'success' => $anySuccess,
                    'results' => $results,
                    'toasts' => $toasts,
                    'processed_xml_count' => $results['xml_processed'],
                ]);
            }

            return back()->with('toasts', $toasts);

        } catch (\Throwable $e) {
            Log::error('Critical FileUpload Error: '.$e->getMessage());
            $errorMsg = 'Ocurrió un error inesperado al procesar la solicitud: '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json(['message' => $errorMsg], 500);
            }

            return back()->with('error', $errorMsg);
        }
    }
}
