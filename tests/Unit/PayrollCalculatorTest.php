<?php

use App\Services\Finance\PayrollCalculator;
use App\Services\Finance\RecurrenceCalculator;

function calc(): PayrollCalculator
{
    return new PayrollCalculator(new RecurrenceCalculator());
}

it('returns the two quincena dates of a month with no weekend adjustment', function () {
    // Junio 2026: día 15 = lunes, fin de mes 30 = martes (sin ajuste).
    $q = calc()->quincenas(2026, 6);

    expect($q)->toHaveCount(2);
    expect($q[0]['nominal']->toDateString())->toBe('2026-06-15');
    expect($q[0]['pago']->toDateString())->toBe('2026-06-15');
    expect($q[1]['nominal']->toDateString())->toBe('2026-06-30');
    expect($q[1]['pago']->toDateString())->toBe('2026-06-30');
});

it('adjusts an end-of-month that falls on Sunday to the previous Friday', function () {
    // Mayo 2026: fin de mes 31 = domingo → pago viernes 29.
    $q = calc()->quincenas(2026, 5);

    expect($q[1]['nominal']->toDateString())->toBe('2026-05-31');
    expect($q[1]['pago']->toDateString())->toBe('2026-05-29');
});

it('adjusts a day-15 that falls on Saturday to the previous Friday', function () {
    // Agosto 2026: día 15 = sábado → pago viernes 14.
    $q = calc()->quincenas(2026, 8);

    expect($q[0]['nominal']->toDateString())->toBe('2026-08-15');
    expect($q[0]['pago']->toDateString())->toBe('2026-08-14');
});

it('uses the real last day for short months', function () {
    // Febrero 2026 (no bisiesto) → fin de mes 28.
    $q = calc()->quincenas(2026, 2);

    expect($q[1]['nominal']->toDateString())->toBe('2026-02-28');
});
