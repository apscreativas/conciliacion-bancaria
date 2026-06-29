<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Team;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Fase 0 del PRD finanzas-egresos-multiempresa:
 * siembra la dimensión de empresas (3 unidades de negocio) y el catálogo
 * gerencial de categorías (§4.2) para cada Team. Idempotente: re-ejecutar
 * no duplica (updateOrCreate por clave team_id + slug/nombre).
 */
class FinanzasCatalogoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Las 3 unidades de negocio del grupo.
     */
    private array $empresas = [
        ['nombre' => 'Aplicaciones Creativas', 'color' => '#6366f1', 'orden' => 1],
        ['nombre' => 'Tu Checador', 'color' => '#10b981', 'orden' => 2],
        ['nombre' => 'Domoticap', 'color' => '#f59e0b', 'orden' => 3],
    ];

    /**
     * Catálogo de categorías del Estado de Resultados (PRD §4.2).
     * [nombre, tipo, grupo, naturaleza|null]
     */
    private array $categorias = [
        // INGRESOS
        ['Servicios de desarrollo', 'ingreso', 'ingreso', null],
        ['Ingresos recurrentes / suscripción', 'ingreso', 'ingreso', null],
        ['Instalaciones y hardware', 'ingreso', 'ingreso', null],
        ['Otros ingresos (efectivo)', 'ingreso', 'ingreso', null],

        // COSTO DE VENTA (COGS)
        ['Infraestructura / servidores / cloud', 'egreso', 'costo_venta', 'variable'],
        ['Licencias y software de terceros de proyecto', 'egreso', 'costo_venta', 'variable'],
        ['Subcontratación / freelancers de proyecto', 'egreso', 'costo_venta', 'variable'],
        ['Hardware y materiales', 'egreso', 'costo_venta', 'variable'],
        ['Nómina técnica facturable', 'egreso', 'costo_venta', 'fijo'],

        // GASTOS DE OPERACIÓN (OPEX) — Costo laboral
        ['Nómina fiscal', 'egreso', 'gasto_operativo', 'fijo'],
        ['Nómina complemento / real', 'egreso', 'gasto_operativo', 'fijo'],
        ['Cuotas IMSS / seguro social', 'egreso', 'gasto_operativo', 'fijo'],
        ['Impuesto sobre nómina (ISN)', 'egreso', 'gasto_operativo', 'fijo'],
        ['Bonos y comisiones', 'egreso', 'gasto_operativo', 'variable'],
        // GASTOS DE OPERACIÓN (OPEX) — Otros
        ['Renta y servicios', 'egreso', 'gasto_operativo', 'fijo'],
        ['Contabilidad, legal y administrativos', 'egreso', 'gasto_operativo', 'fijo'],
        ['Marketing y ventas', 'egreso', 'gasto_operativo', 'variable'],
        ['Herramientas internas', 'egreso', 'gasto_operativo', 'fijo'],

        // ABAJO DE EBITDA
        ['Depreciación y amortización', 'egreso', 'abajo_ebitda', 'fijo'],
        ['Gastos financieros / intereses', 'egreso', 'abajo_ebitda', 'variable'],
        ['Impuestos (estimado)', 'egreso', 'abajo_ebitda', 'variable'],
    ];

    public function run(): void
    {
        Team::query()->each(function (Team $team) {
            $this->seedEmpresas($team->id);
            $this->seedCategorias($team->id);
        });
    }

    private function seedEmpresas(int $teamId): void
    {
        foreach ($this->empresas as $empresa) {
            Empresa::updateOrCreate(
                ['team_id' => $teamId, 'slug' => Str::slug($empresa['nombre'])],
                [
                    'nombre' => $empresa['nombre'],
                    'color' => $empresa['color'],
                    'activo' => true,
                    'orden' => $empresa['orden'],
                ]
            );
        }
    }

    private function seedCategorias(int $teamId): void
    {
        foreach ($this->categorias as $orden => [$nombre, $tipo, $grupo, $naturaleza]) {
            Categoria::updateOrCreate(
                ['team_id' => $teamId, 'nombre' => $nombre],
                [
                    'tipo' => $tipo,
                    'grupo' => $grupo,
                    'naturaleza' => $naturaleza,
                    'activo' => true,
                    'orden' => $orden + 1,
                ]
            );
        }
    }
}
