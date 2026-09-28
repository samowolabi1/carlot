<?php

use App\Domain\Inventory\Enums\VehicleStatus as S;
use App\Domain\Inventory\Support\VehicleStateMachine;

it('allows the lifecycle in the TDD', function (S $from, S $to) {
    expect((new VehicleStateMachine)->canTransition($from, $to))->toBeTrue();
})->with([
    [S::Draft, S::Available],
    [S::Available, S::Reserved],
    [S::Available, S::Hidden],
    [S::Available, S::Sold],
    [S::Reserved, S::Available],
    [S::Reserved, S::Sold],
    [S::Hidden, S::Available],
]);

it('rejects invalid moves', function (S $from, S $to) {
    expect((new VehicleStateMachine)->canTransition($from, $to))->toBeFalse();
})->with([
    [S::Draft, S::Sold],
    [S::Draft, S::Reserved],
    [S::Draft, S::Hidden],
    [S::Sold, S::Available],
    [S::Hidden, S::Reserved],
    [S::Reserved, S::Hidden],
]);
