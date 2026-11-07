<?php

declare(strict_types=1);

use App\Board\BoardFeatures;
use App\Board\ChangePlan;
use App\Board\Feature;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;

function featureBoard(array $peripherals): Board
{
    return new Board('b', 'B', 'esp32s3', null, '16MB', null, $peripherals);
}

$index = [
    'display:st77916' => ['php-baremetal/esp32-display-st77916'],
    'i2c:i2c' => ['php-baremetal/ext-i2c'],
    'led:ws2812' => ['php-baremetal/led-ws2812', 'acme/led'],
];

it('builds one feature per block except microsd and network', function () use ($index) {
    $board = featureBoard([
        'network' => ['type' => 'wifi'], 'microsd' => ['type' => 'sdmmc'],
        'i2c' => ['type' => 'i2c'], 'display' => ['type' => 'st77916'],
    ]);
    $features = BoardFeatures::of($board, $index);

    expect(array_map(fn (Feature $f) => $f->block, $features))->toBe(['display', 'i2c'])
        ->and($features[0]->package())->toBe('php-baremetal/esp32-display-st77916')
        ->and($features[1]->kind)->toBe('i2c');
});

it('derives the kind from the block name', function () use ($index) {
    $features = BoardFeatures::of(featureBoard(['led-2' => ['type' => 'ws2812']]), $index);
    expect($features[0]->kind)->toBe('led')->and($features[0]->packages)->toBe(['php-baremetal/led-ws2812', 'acme/led']);
});

it('leaves packages empty for a block without package', function () use ($index) {
    $features = BoardFeatures::of(featureBoard(['audio' => ['type' => 'es8311']]), $index);
    expect($features[0]->packages)->toBe([])->and($features[0]->package())->toBeNull();
});

function feats(): array
{
    return [
        new Feature('display', 'display', 'st77916', ['a/display']),
        new Feature('i2c', 'i2c', 'i2c', ['a/i2c']),
        new Feature('led', 'led', 'ws2812', ['a/led', 'b/led']),
    ];
}

it('adds and removes packages', function () {
    $plan = BoardFeatures::plan(feats(), ['display', 'led'], ['a/i2c' => '^1']);
    expect($plan->remove)->toBe(['a/i2c'])->and($plan->require)->toBe(['a/display', 'a/led'])
        ->and($plan->board)->toBeNull()->and($plan->isEmpty())->toBeFalse();
});

it('keeps user packages and does not re-add an installed candidate', function () {
    $plan = BoardFeatures::plan(feats(), ['led'], ['user/pkg' => '*', 'b/led' => '*']);
    expect($plan->remove)->toBe([])->and($plan->require)->toBe([])->and($plan->isEmpty())->toBeTrue();
});

it('does not remove a package shared by a remaining feature', function () {
    $features = [new Feature('a', 'a', 't', ['s/shared']), new Feature('b', 'b', 't', ['s/shared'])];
    $plan = BoardFeatures::plan($features, ['b'], ['s/shared' => '*']);
    expect($plan->remove)->toBe([])->and($plan->isEmpty())->toBeTrue();
});

it('removes the packages of the leaving board features', function () {
    $leaving = [new Feature('touch', 'touch', 'x', ['a/touch']), new Feature('i2c', 'i2c', 'i2c', ['a/i2c']), new Feature('p', 'p', 'x', ['a/pp'])];
    $plan = BoardFeatures::plan(feats(), ['i2c'], ['a/touch' => '*', 'a/i2c' => '*', 'user/x' => '*'], $leaving);
    expect($plan->remove)->toBe(['a/touch'])->and($plan->require)->toBe([]);
});

it('gives an empty plan when nothing changes', function () {
    expect(BoardFeatures::plan([], [], [])->isEmpty())->toBeTrue()
        ->and((new ChangePlan('x', [], [], []))->isEmpty())->toBeFalse();
});
