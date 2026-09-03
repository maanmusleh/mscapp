<?php

declare(strict_types=1);

/**
 * Official CanSkate ribbon passing thresholds.
 *
 * The numerator is the number of successfully demonstrated elements required
 * for the named fundamental area; it is not always the number of elements in
 * the area's curriculum. These values follow the CanSkate Progress Report.
 */
final class CanSkateRequirements
{
    private const RIBBON_THRESHOLDS = [
        0 => ['pre-canskate' => 1],
        1 => ['balance' => 4, 'control' => 3, 'agility' => 3],
        2 => ['balance' => 4, 'control' => 3, 'agility' => 4],
        3 => ['balance' => 5, 'control' => 5, 'agility' => 5],
        4 => ['balance' => 5, 'control' => 5, 'agility' => 5],
        5 => ['balance' => 5, 'control' => 6, 'agility' => 6],
        6 => ['balance' => 6, 'control' => 6, 'agility' => 6],
    ];

    public static function ribbonRequiredSkillCount(
        int $stageNumber,
        string $ribbonName,
        int $availableSkillCount
    ): int {
        $configured = self::RIBBON_THRESHOLDS[$stageNumber][strtolower(trim($ribbonName))] ?? null;
        if ($configured === null) {
            return $availableSkillCount;
        }

        // Do not make a custom/incomplete curriculum impossible to complete.
        return min($availableSkillCount, $configured);
    }
}
