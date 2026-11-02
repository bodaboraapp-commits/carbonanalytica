<?php

declare(strict_types=1);

/**
 * @var array<string, mixed>|null $user
 * @var string $token
 * @var array<string, mixed> $preview
 * @var bool $emailMatches
 */

$invitation = $preview['invitation'];
$team = $preview['team'];
$state = (string) $preview['state'];
$invitedEmail = (string) $invitation['email'];
$roleLabel = t(role_key((string) $invitation['role']));
?>
<section class="auth-shell">
    <div class="auth-card">
        <?php if ($state !== 'valid'): ?>
            <h1><?= e(t('team.invite_title')) ?></h1>
            <div class="alert alert--error" role="alert"><?= e(t('invite.invalid')) ?></div>
            <p class="auth-alt">
                <a href="<?= e(url('/dashboard')) ?>"><?= e(t('nav.projects')) ?></a>
            </p>
        <?php else: ?>
            <h1><?= e(t('invite.title', ['team' => (string) ($team['name'] ?? '')])) ?></h1>
            <p class="auth-card__sub">
                <?= e(t('invite.sub', [
                    'inviter' => (string) ($preview['inviter_name'] ?? t('team.role_owner')),
                    'role' => $roleLabel,
                ])) ?>
            </p>

            <p class="small muted">
                <?= e(t('auth.email_label')) ?>: <strong><?= e($invitedEmail) ?></strong>
            </p>

            <?php if ($user === null): ?>
                <div class="alert alert--info" role="status">
                    <?= e(t('invite.sign_in_first', ['email' => $invitedEmail])) ?>
                </div>
                <div class="form__actions">
                    <a class="btn btn--block" href="<?= e(url('/login') . '?next=' . rawurlencode('/invite/' . $token) . '&email=' . rawurlencode($invitedEmail)) ?>">
                        <?= e(t('nav.sign_in')) ?>
                    </a>
                    <a class="btn btn--secondary btn--block" href="<?= e(url('/signup') . '?next=' . rawurlencode('/invite/' . $token) . '&email=' . rawurlencode($invitedEmail)) ?>">
                        <?= e(t('nav.sign_up')) ?>
                    </a>
                </div>
            <?php elseif ($emailMatches): ?>
                <form class="form" method="post" action="<?= e(url('/invite/' . $token . '/accept')) ?>">
                    <?= csrf_field() ?>
                    <div class="form__actions">
                        <button class="btn btn--block" type="submit"><?= e(t('invite.accept_button')) ?></button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert--warning" role="alert">
                    <?= e(t('invite.wrong_account', [
                        'current' => (string) $user['email'],
                        'email' => $invitedEmail,
                    ])) ?>
                </div>
                <form class="inline-form" method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--secondary btn--block" type="submit"><?= e(t('nav.sign_out')) ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
