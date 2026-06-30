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
