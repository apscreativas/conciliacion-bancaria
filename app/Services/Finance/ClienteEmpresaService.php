<?php

namespace App\Services\Finance;

use App\Models\ClienteEmpresa;
use App\Models\Conciliacion;

/**
 * Catálogo auto-aprendido RFC → empresa para INGRESOS.
 *
 * POPO team-explícito: cada método recibe el `teamId` para ser seguro fuera de
 * un request con Auth (jobs/cola/tests). Las escrituras/lecturas se aíslan por
 * team de forma explícita, no dependen del global scope de TeamOwned.
 */
class ClienteEmpresaService
{
    /**
     * Aprende (upsert) el mapeo rfc → empresa por cada RFC único de $facturas.
     * last-wins: la última asignación gana empresa/nombre/user/fecha. `veces` se
     * incrementa +1 por cada asignación (para medir confianza del aprendizaje).
     *
     * @param  array  $facturas  lista de arrays `['rfc'=>..,'nombre'=>..]` o modelos Factura
     */
    public function recordar(int $teamId, ?int $userId, array $facturas, int $empresaId): void
    {
        $unicos = [];
        foreach ($facturas as $factura) {
            $rfc = is_array($factura) ? ($factura['rfc'] ?? null) : ($factura->rfc ?? null);
            $nombre = is_array($factura) ? ($factura['nombre'] ?? null) : ($factura->nombre ?? null);

            if (empty($rfc)) {
                continue;
            }

            // Dedup dentro del lote: el último nombre visto para ese rfc gana.
            $unicos[$rfc] = $nombre;
        }

        foreach ($unicos as $rfc => $nombre) {
            $cliente = ClienteEmpresa::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $teamId, 'rfc' => $rfc],
                [
                    'empresa_id' => $empresaId,
                    'nombre' => $nombre,
                    'user_id' => $userId,
                    'ultima_asignacion_at' => now(),
                ]
            );

            $cliente->increment('veces');
        }
    }

    /**
     * Sugiere una empresa para un conjunto de RFC.
     * Devuelve el empresa_id si TODOS los RFC que existen en el catálogo mapean a
     * la MISMA empresa y hay al menos uno mapeado. Si los mapeos difieren
     * (ambiguo) o ninguno mapea → null. Los RFC sin mapeo se ignoran.
     */
    public function sugerirEmpresa(int $teamId, array $rfcs): ?int
    {
        $rfcs = array_values(array_unique(array_filter($rfcs)));

        if (empty($rfcs)) {
            return null;
        }

        $empresaIds = ClienteEmpresa::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('rfc', $rfcs)
            ->whereNotNull('empresa_id')
            ->pluck('empresa_id')
            ->unique()
            ->values();

        if ($empresaIds->count() === 1) {
            return (int) $empresaIds->first();
        }

        // 0 mapeos (ninguno conocido) o >1 (ambiguo) → sin sugerencia.
        return null;
    }

    /**
     * Devuelve los RFC/nombre únicos (por rfc) de las facturas de un grupo de
     * conciliación. Ignora conciliaciones cuya factura sea null.
     *
     * @return array<int, array{rfc: string, nombre: ?string}>
     */
    public function rfcsDeGrupo(string $groupId, int $teamId): array
    {
        return Conciliacion::withoutGlobalScopes()
            ->where('group_id', $groupId)
            ->where('team_id', $teamId)
            ->with('factura:id,rfc,nombre')
            ->get()
            ->map(fn ($c) => $c->factura)
            ->filter()
            ->unique('rfc')
            ->map(fn ($f) => ['rfc' => $f->rfc, 'nombre' => $f->nombre])
            ->values()
            ->all();
    }

    /**
     * Aplica el catálogo a los grupos de conciliación del team que aún no tienen
     * empresa: por cada group_id con empresa_id null, si sus RFC dan una
     * sugerencia unívoca, asigna esa empresa a todo el grupo. Deja intactos los
     * grupos ambiguos o sin mapeo. Devuelve cuántos grupos se asignaron.
     */
    public function aplicarASinEmpresa(int $teamId): int
    {
        $groupIds = Conciliacion::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNull('empresa_id')
            ->distinct()
            ->pluck('group_id');

        $asignados = 0;

        foreach ($groupIds as $groupId) {
            $rfcs = collect($this->rfcsDeGrupo($groupId, $teamId))->pluck('rfc')->all();
            $sugerida = $this->sugerirEmpresa($teamId, $rfcs);

            if ($sugerida === null) {
                continue;
            }

            Conciliacion::withoutGlobalScopes()
                ->where('group_id', $groupId)
                ->where('team_id', $teamId)
                ->update(['empresa_id' => $sugerida]);

            $asignados++;
        }

        return $asignados;
    }
}
