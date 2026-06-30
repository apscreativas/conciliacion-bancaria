<?php

use App\Services\Finance\PeriodResolver;
use Carbon\Carbon;

function resolver(): PeriodResolver
{
    return new PeriodResolver;
}

// ─────────────────────────────────────────────────────────────────────────────
// resolve() — los 4 rangos desde un ancla conocida (year=2026, month=8).
// ─────────────────────────────────────────────────────────────────────────────
it('resolves a monthly range to that exact month', function () {
    $r = resolver()->resolve('mensual', 2026, 8);

    expect($r['desde']->toDateString())->toBe('2026-08-01')
        ->and($r['hasta']->toDateString())->toBe('2026-08-31');
});

it('resolves a quarterly range to the quarter containing the month', function () {
    // Mes 8 → Q3 (jul–sep).
    $r = resolver()->resolve('trimestral', 2026, 8);

    expect($r['desde']->toDateString())->toBe('2026-07-01')
        ->and($r['hasta']->toDateString())->toBe('2026-09-30');
});

it('resolves a half-year range to jul–dec for the second semester', function () {
    // Mes 8 (>=7) → jul–dic.
    $r = resolver()->resolve('semestral', 2026, 8);

    expect($r['desde']->toDateString())->toBe('2026-07-01')
        ->and($r['hasta']->toDateString())->toBe('2026-12-31');
});

it('resolves a half-year range to jan–jun for the first semester', function () {
    // Mes 3 (<=6) → ene–jun.
    $r = resolver()->resolve('semestral', 2026, 3);

    expect($r['desde']->toDateString())->toBe('2026-01-01')
        ->and($r['hasta']->toDateString())->toBe('2026-06-30');
});

it('resolves an annual range to the whole year', function () {
    $r = resolver()->resolve('anual', 2026, 8);

    expect($r['desde']->toDateString())->toBe('2026-01-01')
        ->and($r['hasta']->toDateString())->toBe('2026-12-31');
});

// ─────────────────────────────────────────────────────────────────────────────
// previous() — periodo inmediatamente anterior de la misma granularidad.
// ─────────────────────────────────────────────────────────────────────────────
it('returns the previous month, crossing the year boundary', function () {
    // Mensual desde 2026-01 → diciembre 2025.
    $r = resolver()->previous('mensual', Carbon::parse('2026-01-01'));

    expect($r['desde']->toDateString())->toBe('2025-12-01')
        ->and($r['hasta']->toDateString())->toBe('2025-12-31');
});

it('returns the previous month within the same year', function () {
    $r = resolver()->previous('mensual', Carbon::parse('2026-08-01'));

    expect($r['desde']->toDateString())->toBe('2026-07-01')
        ->and($r['hasta']->toDateString())->toBe('2026-07-31');
});

it('returns the previous quarter, crossing the year boundary', function () {
    // Trimestral desde Q1 2026 → Q4 2025.
    $r = resolver()->previous('trimestral', Carbon::parse('2026-01-01'));

    expect($r['desde']->toDateString())->toBe('2025-10-01')
        ->and($r['hasta']->toDateString())->toBe('2025-12-31');
});

it('returns the previous quarter within the same year', function () {
    // Trimestral desde Q3 2026 → Q2 2026.
    $r = resolver()->previous('trimestral', Carbon::parse('2026-07-01'));

    expect($r['desde']->toDateString())->toBe('2026-04-01')
        ->and($r['hasta']->toDateString())->toBe('2026-06-30');
});

it('returns the previous semester, crossing the year boundary', function () {
    // Semestral desde S1 2026 (ene–jun) → S2 2025 (jul–dic).
    $r = resolver()->previous('semestral', Carbon::parse('2026-01-01'));

    expect($r['desde']->toDateString())->toBe('2025-07-01')
        ->and($r['hasta']->toDateString())->toBe('2025-12-31');
});

it('returns the previous semester within the same year', function () {
    // Semestral desde S2 2026 (jul–dic) → S1 2026 (ene–jun).
    $r = resolver()->previous('semestral', Carbon::parse('2026-07-01'));

    expect($r['desde']->toDateString())->toBe('2026-01-01')
        ->and($r['hasta']->toDateString())->toBe('2026-06-30');
});

it('returns the previous year', function () {
    $r = resolver()->previous('anual', Carbon::parse('2026-01-01'));

    expect($r['desde']->toDateString())->toBe('2025-01-01')
        ->and($r['hasta']->toDateString())->toBe('2025-12-31');
});

// ─────────────────────────────────────────────────────────────────────────────
// yearOverYear() — mismo rango un año antes.
// ─────────────────────────────────────────────────────────────────────────────
it('shifts a range back exactly one year', function () {
    $rango = resolver()->resolve('trimestral', 2026, 8); // jul–sep 2026
    $yoy = resolver()->yearOverYear($rango);

    expect($yoy['desde']->toDateString())->toBe('2025-07-01')
        ->and($yoy['hasta']->toDateString())->toBe('2025-09-30');
});

it('shifts an annual range back one year', function () {
    $rango = resolver()->resolve('anual', 2026, 8);
    $yoy = resolver()->yearOverYear($rango);

    expect($yoy['desde']->toDateString())->toBe('2025-01-01')
        ->and($yoy['hasta']->toDateString())->toBe('2025-12-31');
});
