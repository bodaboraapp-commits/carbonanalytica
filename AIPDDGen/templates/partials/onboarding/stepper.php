<?php

declare(strict_types=1);

use App\Services\OnboardingService;

/**
 * Wizard progress indicator: one item per step, marked complete / current /
 * upcoming. Steps beyond the furthest reachable one are not links.
 *
 * @var int $step
 * @var int $maxStep
 * @var array<int, bool> $completion
 * @var bool $isLocked
 */
?>
<nav class="stepper-wrap" aria-label="<?= e(t('org.progress_label')) ?>">
    <ol class="stepper">
        <?php foreach (OnboardingService::STEPS as $number => $name): ?>
            <?php
            $isComplete = !empty($completion[$number]);
            $isCurrent = $number === $step;
            $isLocked = $number > $maxStep;
            $stateClass = $isComplete ? 'stepper__item--complete' : ($isCurrent ? 'stepper__item--current' : '');
            if ($isLocked) {
                $stateClass .= ' stepper__item--locked';
            }
            ?>
            <li class="stepper__item <?= e(trim($stateClass)) ?>" <?= $isCurrent ? 'aria-current="step"' : '' ?>>
                <?php if (!$isLocked): ?>
                    <a class="stepper__link" href="<?= e(url('/onboarding?step=' . $number)) ?>">
                <?php else: ?>
                    <span class="stepper__link stepper__link--static">
                <?php endif; ?>
                    <span class="stepper__mark" aria-hidden="true">
                        <?php if ($isComplete): ?>
                            <svg viewBox="0 0 20 20" width="14" height="14" focusable="false"><path d="M4 10.5 8 14.5 16 5.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php else: ?>
                            <span class="stepper__num"><?= e((string) $number) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="stepper__text">
                        <span class="stepper__title"><?= e(t('org.step' . $number . '_title')) ?></span>
                        <span class="stepper__desc"><?= e(t('org.step' . $number . '_desc')) ?></span>
                        <span class="visually-hidden">
                            <?= e($isComplete ? t('org.step_complete') : ($isLocked ? t('org.step_locked') : t('org.step_open'))) ?>
                        </span>
                    </span>
                <?php if (!$isLocked): ?>
                    </a>
                <?php else: ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
