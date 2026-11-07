<?php

declare(strict_types=1);

namespace App\Board;

use App\PhpbmException;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;

/** Which features of a board a `--features` value selects. */
final class FeatureChoice
{
    /** @param list<Feature> $features */
    public static function selectable(array $features): array
    {
        return array_values(array_filter($features, static fn (Feature $f): bool => $f->package() !== null));
    }

    /** @param list<Feature> $features @return list<string> note lines for the features without a package */
    public static function notes(array $features): array
    {
        $notes = [];
        foreach ($features as $feature) {
            if ($feature->package() === null) {
                $notes[] = "{$feature->block} {$feature->type}: no package";
            }
        }
        return $notes;
    }

    /**
     * @param list<Feature> $features all the features of the board
     * @return list<Feature> the selectable features of the given kinds; a kind with no package selects nothing
     * @throws PhpbmException when the board has no feature of a kind
     */
    public static function byKinds(Board $board, array $features, string $option): array
    {
        $kinds = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($option))), static fn (string $k): bool => $k !== '')));
        $known = array_values(array_unique(array_map(static fn (Feature $f): string => $f->kind, $features)));
        sort($known);
        foreach ($kinds as $kind) {
            if (!in_array($kind, $known, true)) {
                throw new PhpbmException("board '{$board->id}' has no feature '{$kind}' (features: " . implode(', ', $known) . ')');
            }
        }
        return array_values(array_filter(self::selectable($features), static fn (Feature $f): bool => in_array($f->kind, $kinds, true)));
    }
}
