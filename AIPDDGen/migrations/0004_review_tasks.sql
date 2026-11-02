-- ---------------------------------------------------------------------------
-- Staff Action Center / review task queue (product-owner request)
--
-- Staff review work used to live only in the onboarding status column. This
-- table makes it a first-class work queue: assignee, priority, SLA due date,
-- work type and a link to the underlying entity.
--
-- SLA semantics: a task is breached when due_at is in the past and the task is
-- still open (unassigned / in_progress / blocked). Completing or cancelling a
-- task clears the breach without editing history.
-- ---------------------------------------------------------------------------

CREATE TABLE review_tasks (
    id CHAR(36) NOT NULL,
    team_id CHAR(36) NULL,
    work_type ENUM('organisation_kyb', 'project_validation', 'template_review', 'fact_sheet_review', 'draft_review', 'other') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('unassigned', 'in_progress', 'blocked', 'completed', 'cancelled') NOT NULL DEFAULT 'unassigned',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    assigned_user_id CHAR(36) NULL,
    due_at DATETIME(3) NULL,
    target_type VARCHAR(40) NULL,
    target_id CHAR(36) NULL,
    created_by_user_id CHAR(36) NULL,
    completed_at DATETIME(3) NULL,
    completed_by_user_id CHAR(36) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_review_tasks_assigned (assigned_user_id, status, due_at),
    KEY idx_review_tasks_work_type (work_type, status),
    KEY idx_review_tasks_team (team_id),
    KEY idx_review_tasks_due (due_at),
    KEY idx_review_tasks_target (target_type, target_id),
    CONSTRAINT fk_review_tasks_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
    CONSTRAINT fk_review_tasks_assignee FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_review_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_review_tasks_completer FOREIGN KEY (completed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
