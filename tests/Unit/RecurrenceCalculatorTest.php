<?php

use App\Services\Finance\RecurrenceCalculator;
use Carbon\Carbon;

beforeEach(function () {
    $this->calc = new RecurrenceCalculator;
});

it('advances by frequency keeping the day of month', function (string $frecuencia, string $expected) {
    $next = $this->calc->nextDate(Carbon::parse('2026-01-15'), $frecuencia, 15);
    expect($next->toDateString())->toBe($expected);
})->with([
    ['mensual', '2026-02-15'],
    ['bimestral', '2026-03-15'],
    ['trimestral', '2026-04-15'],
    ['anual', '2027-01-15'],
]);

it('clamps the day to the last day of a short month', function () {
    // ene 31 + 1 mes (día nominal 31) → feb 28 (2026 no es bisiesto)
    expect($this->calc->nextDate(Carbon::parse('2026-01-31'), 'mensual', 31)->toDateString())->toBe('2026-02-28');
});

it('recovers the nominal day after a short month', function () {
    // desde feb 28 (clampado), avanzar con día nominal 31 → mar 31
    expect($this->calc->nextDate(Carbon::parse('2026-02-28'), 'mensual', 31)->toDateString())->toBe('2026-03-31');
});

it('computes the first occurrence on or after the start date', function () {
    // inicio 20 ene, día 15 → primera ocurrencia 15 feb (el 15 ene ya pasó)
    expect($this->calc->firstOccurrence(Carbon::parse('2026-01-20'), 15, 'mensual')->toDateString())->toBe('2026-02-15');
    // inicio 10 ene, día 15 → 15 ene
    expect($this->calc->firstOccurrence(Carbon::parse('2026-01-10'), 15, 'mensual')->toDateString())->toBe('2026-01-15');
});

it('adjusts weekends to a business day', function () {
    $sabado = Carbon::parse('2026-06-01')->next(Carbon::SATURDAY);
    expect($sabado->isWeekend())->toBeTrue();

    expect($this->calc->applyDiaHabil($sabado, 'habil_anterior')->toDateString())
        ->toBe($sabado->copy()->subDay()->toDateString()); // viernes
    expect($this->calc->applyDiaHabil($sabado, 'habil_siguiente')->toDateString())
        ->toBe($sabado->copy()->addDays(2)->toDateString()); // lunes
    expect($this->calc->applyDiaHabil($sabado, 'ninguno')->toDateString())
        ->toBe($sabado->toDateString()); // sin cambio
});
