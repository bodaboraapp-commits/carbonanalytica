<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array<string, mixed> $team
 * @var string $role
 * @var list<array<string, mixed>> $members
 * @var list<array<string, mixed>> $invitations
 * @var bool $canInvite
 * @var bool $isOwner
 * @var string|null $newInviteLink
 * @var list<array<string, mixed>> $teams
 * @var array<string, mixed> $organization
 */

$orgStatus = (string) ($organization['status'] ?? 'draft');
$orgStatusKey = match ($orgStatus) {
    'submitted' => 'org.status_submitted',
    'verified' => 'org.status_verified',
    'rejected' => 'org.status_rejected',
    default => 'org.status_draft',
};
$orgStatusClass = match ($orgStatus) {
    'submitted' => 'badge--gold',
    'verified' => 'badge',
    'rejected' => 'badge--draft',
    default => 'badge--neutral',
};
?>
<div class="app-head">
    <div>
        <h1><?= e(t('team.title')) ?></h1>
        <p class="app-head__sub"><?= e(t('team.sub')) ?></p>
    </div>
    <span class="workspace-pill">
        <strong><?= e(t('dashboard.workspace_label')) ?>:</strong>
        <?= e((string) $team['name']) ?>
    </span>
</div>

<?php if ($newInviteLink !== null): ?>
    <div class="alert alert--info" role="status">
        <div>
            <strong><?= e(t('team.invite_link_hint')) ?></strong>
            <code class="copy-hint"><?= e($newInviteLink) ?></code>
        </div>
    </div>
<?php endif; ?>

<div class="page-grid">
    <div class="stack">
        <section class="card card--flush" aria-labelledby="members-title">
            <div style="padding: 1.25rem 1.4rem 0;">
                <h2 class="panel-title" id="members-title">
                    <?= e(t('team.members_title')) ?>
                    <span class="muted">· <?= e((string) count($members)) ?> <?= e(t('team.members_label')) ?></span>
                </h2>
            </div>
            <div class="table-wrap">
                <table class="data">
                    <caption class="visually-hidden"><?= e(t('team.members_title')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('auth.name_label')) ?></th>
                            <th scope="col"><?= e(t('auth.email_label')) ?></th>
                            <th scope="col"><?= e(t('team.invite_role')) ?></th>
                            <th scope="col"><?= e(t('team.member_since')) ?></th>
                            <th scope="col"><span class="visually-hidden"><?= e(t('team.revoke')) ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <?php
                            $isSelf = (string) $member['user_id'] === (string) $user['id'];
                            $isOwnerRow = $member['role'] === 'owner';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) $member['name']) ?></strong>
                                    <?php if ($isSelf): ?>
                                        <span class="badge badge--neutral"><?= e(t('common.you')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted"><?= e((string) $member['email']) ?></td>
                                <td>
                                    <span class="badge <?= $isOwnerRow ? '' : 'badge--neutral' ?>">
                                        <?= e(t(role_key((string) $member['role']))) ?>
                                    </span>
                                </td>
                                <td class="muted"><?= e(format_date((string) $member['joined_at'])) ?></td>
                                <td>
                                    <?php if ($isOwner && !$isOwnerRow && !$isSelf): ?>
                                        <div class="table-actions">
                                            <form class="inline-form" method="post" action="<?= e(url('/team/members/' . $member['id'] . '/role')) ?>">
                                                <?= csrf_field() ?>
                                                <label class="visually-hidden" for="role-<?= e((string) $member['id']) ?>">
                                                    <?= e(t('team.invite_role')) ?>
                                                </label>
                                                <select id="role-<?= e((string) $member['id']) ?>" name="role">
                                                    <?php foreach (['admin', 'editor', 'viewer'] as $assignable): ?>
                                                        <option value="<?= e($assignable) ?>" <?= $member['role'] === $assignable ? 'selected' : '' ?>>
                                                            <?= e(t(role_key($assignable))) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn--secondary btn--sm" type="submit"><?= e(t('common.save')) ?></button>
                                            </form>
                                            <form class="inline-form" method="post" action="<?= e(url('/team/members/' . $member['id'] . '/remove')) ?>">
                                                <?= csrf_field() ?>
                                                <button class="btn btn--danger btn--sm" type="submit"><?= e(t('team.revoke')) ?></button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$isOwner): ?>
                <p class="small muted" style="padding: 0.9rem 1.4rem 1.25rem; margin: 0;">
                    <?= e(t('team.owner_note')) ?>
                </p>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="pending-title">
            <h2 class="panel-title" id="pending-title"><?= e(t('team.pending_title')) ?></h2>
            <?php if ($invitations === []): ?>
                <p class="small muted mb-0"><?= e(t('team.pending_empty')) ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <caption class="visually-hidden"><?= e(t('team.pending_title')) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= e(t('auth.email_label')) ?></th>
                                <th scope="col"><?= e(t('team.invite_role')) ?></th>
                                <th scope="col"><?= e(t('team.member_since')) ?></th>
                                <th scope="col"><span class="visually-hidden"><?= e(t('team.revoke')) ?></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invitations as $invitation): ?>
                                <tr>
                                    <td><?= e((string) $invitation['email']) ?></td>
                                    <td><span class="badge badge--neutral"><?= e(t(role_key((string) $invitation['role']))) ?></span></td>
                                    <td class="muted"><?= e(format_date((string) $invitation['expires_at'])) ?></td>
                                    <td>
                                        <?php if ($canInvite): ?>
                                            <form class="inline-form" method="post" action="<?= e(url('/team/invitations/' . $invitation['id'] . '/revoke')) ?>">
                                                <?= csrf_field() ?>
                                                <button class="btn btn--danger btn--sm" type="submit"><?= e(t('team.revoke')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="stack">
        <?php if ($canInvite): ?>
            <section class="card" aria-labelledby="invite-title">
                <h2 class="panel-title" id="invite-title"><?= e(t('team.invite_title')) ?></h2>
                <form class="form" method="post" action="<?= e(url('/team/invite')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="field">
                        <label for="invite-email"><?= e(t('team.invite_email')) ?></label>
                        <input id="invite-email" name="email" type="email" inputmode="email" autocomplete="off" required value="<?= e(old('email')) ?>">
                    </div>

                    <div class="field">
                        <label for="invite-role"><?= e(t('team.invite_role')) ?></label>
                        <select id="invite-role" name="role">
                            <?php $roles = $isOwner ? ['admin', 'editor', 'viewer'] : ['editor', 'viewer']; ?>
                            <?php foreach ($roles as $assignable): ?>
                                <option value="<?= e($assignable) ?>"><?= e(t(role_key($assignable))) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$isOwner): ?>
                            <span class="hint"><?= e(t('team.owner_note')) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form__actions">
                        <button class="btn btn--block" type="submit"><?= e(t('team.invite_button')) ?></button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="card">
                <p class="small muted mb-0"><?= e(t('team.invite_forbidden')) ?></p>
            </section>
        <?php endif; ?>

        <section class="card">
            <h2 class="panel-title"><?= e(t('team.title')) ?></h2>
            <dl class="meta-grid">
                <div class="meta-item">
                    <dt><?= e(t('dashboard.plan_free')) ?></dt>
                    <dd><?= e((string) $team['plan']) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('team.members_title')) ?></dt>
                    <dd><?= e((string) count($members)) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('nav.organization')) ?></dt>
                    <dd><span class="badge <?= e($orgStatusClass) ?>"><?= e(t($orgStatusKey)) ?></span></dd>
                </div>
            </dl>
            <p class="mt-2 mb-0">
                <a class="btn btn--secondary btn--sm" href="<?= e(url('/dashboard')) ?>"><?= e(t('nav.projects')) ?></a>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('/onboarding')) ?>"><?= e(t('nav.organization')) ?></a>
            </p>
        </section>
    </aside>
</div>
