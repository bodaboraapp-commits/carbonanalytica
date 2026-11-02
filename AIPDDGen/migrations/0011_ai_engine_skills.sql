-- ---------------------------------------------------------------------------
-- AI Engine: per-template SKILL.md requirement (phase 2).
--
-- Every template version added to the library gets its own admin-managed
-- SKILL.md. The drafting engine resolves the most specific available skill in
-- the order: per-version -> per-template -> registry-level -> global
-- instructions (the global instructions stay in ai_engine_settings).
--
-- A newly created template version starts with its skill row in status
-- 'pending'; drafting is refused for that version until an admin uploads the
-- SKILL.md (status 'ready'). The skill text is admin-curated guidance only and
-- must never contain customer project data.
--
-- Rows with template_id/template_version_id NULL are registry-level fallback
-- skills, backfilled below from the existing AI Engine settings so today's
-- Gold Standard / Verra drafting behaviour is unchanged.
-- ---------------------------------------------------------------------------

CREATE TABLE ai_engine_skills (
    id CHAR(36) NOT NULL,
    -- NULL for registry-level fallback rows; set for per-template/version rows.
    template_id CHAR(36) NULL,
    template_version_id CHAR(36) NULL,
    registry VARCHAR(40) NULL,
    name VARCHAR(160) NOT NULL,
    -- The uploaded SKILL.md text, read into the database (never a public file).
    skill_text MEDIUMTEXT NULL,
    source_filename VARCHAR(255) NULL,
    source_mime_type VARCHAR(120) NULL,
    source_size_bytes BIGINT UNSIGNED NULL,
    status ENUM('pending', 'ready') NOT NULL DEFAULT 'pending',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id CHAR(36) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    -- One skill per template version. Registry fallback rows have a NULL
    -- version; MariaDB allows multiple NULLs in a UNIQUE index.
    UNIQUE KEY uq_ai_engine_skills_version (template_version_id),
    KEY idx_ai_engine_skills_template (template_id),
    KEY idx_ai_engine_skills_registry (registry, status),
    CONSTRAINT fk_ai_engine_skills_template FOREIGN KEY (template_id)
        REFERENCES templates (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_engine_skills_version FOREIGN KEY (template_version_id)
        REFERENCES template_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_engine_skills_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registry-level fallbacks from the current AI Engine settings. Empty skills
-- are skipped so no empty rows are created on a fresh deployment.
INSERT INTO ai_engine_skills (id, template_id, template_version_id, registry, name, skill_text, status, enabled)
SELECT UUID(), NULL, NULL, 'gold_standard', 'Gold Standard registry skill', skill_gold_standard, 'ready', 1
FROM ai_engine_settings
WHERE id = 1 AND COALESCE(TRIM(skill_gold_standard), '') <> '';

INSERT INTO ai_engine_skills (id, template_id, template_version_id, registry, name, skill_text, status, enabled)
SELECT UUID(), NULL, NULL, 'verra_vcs', 'Verra VCS registry skill', skill_verra_vcs, 'ready', 1
FROM ai_engine_settings
WHERE id = 1 AND COALESCE(TRIM(skill_verra_vcs), '') <> '';
