<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Finanzas Fase 3: genera egresos de plantillas recurrentes vencidas (idempotente).
// Diario; cada plantilla decide qué le toca vía proxima_generacion. Requiere cron
// `* * * * * php artisan schedule:run` en prod (en Herd local: `php artisan schedule:work`).
// onOneServer: en despliegue multi-servidor solo UN host corre el job (withoutOverlapping
// es por-host). El índice único egresos_recurrente_periodo_unique es el respaldo final.
Schedule::command('egresos:generar-recurrentes')->dailyAt('01:00')->withoutOverlapping()->onOneServer();

// Finanzas Fase 3B: genera la nómina quincenal (fiscal + complemento) por empleado activo.
// Diario; ventana móvil de 40 días + idempotencia. onOneServer: solo un host en multi-servidor.
// Para backfill de meses fuera de la ventana: `php artisan nomina:generar --month=YYYY-MM`.
Schedule::command('nomina:generar')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
