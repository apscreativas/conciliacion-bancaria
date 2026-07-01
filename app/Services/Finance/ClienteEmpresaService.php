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
     * last-wins: la última asignación gana empresa/nombre/user/fecha. `veces` solo
     * se incrementa cuando el mapeo es nuevo o cambia de empresa (mide confianza del
     * aprendizaje); re-asignar la MISMA empresa solo refresca nombre/fecha.
     *
     * Los clientes con `excluido = true` (respetan etiquetas individuales) se saltan
     * por completo: no se toca empresa, nombre, user, fecha ni veces.
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
            $cliente = ClienteEmpresa::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('rfc', $rfc)
                ->first();

            // Exclusión: el cliente "respeta etiquetas individuales" — no se aprende
            // nada (ni empresa, ni nombre, ni fecha, ni veces). El mapeo existente
            // queda inerte hasta que se des-excluya.
            if ($cliente !== null && $cliente->excluido) {
                continue;
            }

            // Solo cuenta como "asignación" (incrementa veces) cuando el mapeo es
            // nuevo o cambia de empresa. Re-asignar la MISMA empresa solo refresca
            // nombre/fecha, sin inflar el contador de confianza.
            $esCambio = $cliente === null || (int) $cliente->empresa_id !== $empresaId;

            $atributos = [
                'empresa_id' => $empresaId,
                'nombre' => $nombre,
                'user_id' => $userId,
                'ultima_asignacion_at' => now(),
            ];

            $cliente = ClienteEmpresa::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $teamId, 'rfc' => $rfc],
                $atributos
            );

            if ($esCambio) {
                $cliente->increment('veces');
            }
        }
    }

    /**
     * Sugiere una empresa para un conjunto de RFC (regla ESTRICTA).
     * Devuelve el empresa_id SOLO si TODOS los RFC dados están mapeados en el
     * catálogo Y coinciden en la misma empresa. Si algún RFC no tiene mapeo, si hay
     * empresas distintas (ambiguo), o si la lista viene vacía → null.
     *
     * Ser estricto evita mal-etiquetar grupos multi-RFC: si el grupo mezcla un RFC
     * conocido con uno desconocido, no se estampa la empresa del conocido a todo el
     * grupo.
     *
     * Los RFC con `excluido = true` quedan fuera del mapa, por lo que actúan como
     * bloqueantes (igual que un RFC sin mapeo): cualquier grupo que los contenga
     * devuelve null y se etiqueta a mano.
     */
    public function sugerirEmpresa(int $teamId, array $rfcs): ?int
    {
        $rfcs = array_values(array_unique(array_filter($rfcs)));

        if (empty($rfcs)) {
            return null;
        }

        $mapa = ClienteEmpresa::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('rfc', $rfcs)
            ->whereNotNull('empresa_id')
            ->where('excluido', false)
            ->pluck('empresa_id', 'rfc')
            ->all();

        return $this->resolverUnivoco($rfcs, $mapa);
    }

    /**
     * Regla estricta compartida: dado un conjunto de RFC y un mapa `rfc => empresa_id`,
     * devuelve la empresa SOLO si todos los RFC están mapeados y a la misma empresa.
     * Cualquier RFC sin entrada en el mapa, empresas distintas, o lista vacía → null.
     * El mapa ya viene filtrado sin clientes excluidos, así que un RFC excluido cae
     * en el caso "sin entrada" y bloquea al grupo.
     *
     * @param  array<int, string>  $rfcs
     * @param  array<string, int|string>  $mapa
     */
    private function resolverUnivoco(array $rfcs, array $mapa): ?int
    {
        $rfcs = array_values(array_unique(array_filter($rfcs)));

        if (empty($rfcs)) {
            return null;
        }

        $empresaId = null;
        foreach ($rfcs as $rfc) {
            // Algún RFC del grupo no está mapeado → no se puede etiquetar el grupo.
            if (! array_key_exists($rfc, $mapa) || $mapa[$rfc] === null) {
                return null;
            }

            $actual = (int) $mapa[$rfc];
            if ($empresaId === null) {
                $empresaId = $actual;
            } elseif ($empresaId !== $actual) {
                // Empresas distintas → ambiguo.
                return null;
            }
        }

        return $empresaId;
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
     * grupos ambiguos, sin mapeo o que contengan un RFC excluido (respeta
     * etiquetas individuales). Devuelve cuántos grupos se asignaron.
     */
    public function aplicarASinEmpresa(int $teamId): int
    {
        // 1 query: todas las conciliaciones sin empresa del team + su factura (rfc).
        $sinEmpresa = Conciliacion::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNull('empresa_id')
            ->with('factura:id,rfc')
            ->get();

        if ($sinEmpresa->isEmpty()) {
            return 0;
        }

        // Agrupa en memoria por group_id → RFCs únicos del grupo.
        $rfcsPorGrupo = $sinEmpresa
            ->groupBy('group_id')
            ->map(fn ($grupo) => $grupo
                ->map(fn ($c) => $c->factura?->rfc)
                ->filter()
                ->unique()
                ->values()
                ->all()
            );

        // 1 query: catálogo del team como mapa rfc => empresa_id (sin excluidos:
        // un RFC excluido bloquea a su grupo en la regla estricta).
        $mapa = ClienteEmpresa::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('empresa_id')
            ->where('excluido', false)
            ->pluck('empresa_id', 'rfc')
            ->all();

        // En memoria: resuelve cada grupo con la MISMA regla estricta y agrupa los
        // group_id por la empresa resultante (solo unívocos; ambiguos/desconocidos se saltan).
        $gruposPorEmpresa = [];
        foreach ($rfcsPorGrupo as $groupId => $rfcs) {
            $empresaId = $this->resolverUnivoco($rfcs, $mapa);

            if ($empresaId === null) {
                continue;
            }

            $gruposPorEmpresa[$empresaId][] = $groupId;
        }

        // Un update por empresa (no per-grupo), acotado por team.
        $asignados = 0;
        foreach ($gruposPorEmpresa as $empresaId => $groupIds) {
            Conciliacion::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereIn('group_id', $groupIds)
                ->update(['empresa_id' => $empresaId]);

            $asignados += count($groupIds);
        }

        return $asignados;
    }
}
