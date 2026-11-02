<?php

declare(strict_types=1);

/**
 * Flash messages. Success is announced politely, errors assertively, so screen
 * readers hear what happened without stealing focus.
 *
 * @var list<array{type: string, message: string}> $flashes
 */

$roleFor = static fn (string $type): string => $type === 'error' ? 'alert' : 'status';
?>
<?php if ($flashes !== []): ?>
    <div class="flash-region">
        <?php foreach ($flashes as $flash): ?>
            <?php $type = in_array($flash['type'], ['success', 'error', 'info', 'warning'], true) ? $flash['type'] : 'info'; ?>
            <div class="alert alert--<?= e($type) ?>" role="<?= e($roleFor($type)) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
