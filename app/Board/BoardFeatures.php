<?php

declare(strict_types=1);

namespace App\Board;

use PhpBaremetal\Spec\Sdk\Descriptor\Board;

/** The features of a board (blocks packages can drive) and the Composer changes to apply a choice. */
final class BoardFeatures
{
    private const EXCLUDED = ['microsd', 'network'];

    /**
     * @param array<string, list<string>> $index 'kind:type' => packages
     * @return list<Feature>
     */
    public static function of(Board $board, array $index): array
    {
        $blocks = $board->capabilities();
        ksort($blocks, SORT_STRING);

        $features = [];
        foreach ($blocks as $block => $type) {
            $block = (string) $block;
            if (in_array($block, self::EXCLUDED, true)) {
                continue;
            }
            $kind = explode('-', $block, 2)[0];
            $features[] = new Feature($block, $kind, $type, $index[$kind . ':' . $type] ?? []);
        }
        return $features;
    }

    /**
     * @param list<Feature> $features features of the (new) board
     * @param list<string> $chosenBlocks block names chosen
     * @param array<string, mixed> $required the direct `require` of composer.json (package => constraint)
     * @param list<Feature>|null $leaving features of the old board, only on a board change
     */
    public static function plan(array $features, array $chosenBlocks, array $required, ?array $leaving = null): ChangePlan
    {
        $chosen = array_values(array_filter($features, static fn (Feature $f): bool => in_array($f->block, $chosenBlocks, true)));
        $used = [];
        foreach ($chosen as $feature) {
            foreach ($feature->packages as $package) {
                $used[$package] = true;
            }
        }

        $remove = [];
        foreach ([...$features, ...($leaving ?? [])] as $feature) {
            foreach ($feature->packages as $package) {
                if (array_key_exists($package, $required) && !isset($used[$package]) && !in_array($package, $remove, true)) {
                    $remove[] = $package;
                }
            }
        }

        $require = [];
        foreach ($chosen as $feature) {
            $package = $feature->package();
            if ($package === null || in_array($package, $require, true)) {
                continue;
            }
            foreach ($feature->packages as $candidate) {
                if (array_key_exists($candidate, $required)) {
                    continue 2;
                }
            }
            $require[] = $package;
        }

        return new ChangePlan(null, [], $remove, $require);
    }
}
