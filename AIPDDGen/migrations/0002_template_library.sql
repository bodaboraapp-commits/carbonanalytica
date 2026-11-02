-- ---------------------------------------------------------------------------
-- Step 2: admin-curated template library (PRD F13)
--
--  * stores the uploaded source file of a template version (for parsing and
--    for the admin quality gate)
--  * stores parsed fields inside a section (label, type, guidance, rules)
--  * records the quality-gate validation that must exist before publishing
-- ---------------------------------------------------------------------------

ALTER TABLE template_versions
    ADD COLUMN source_filename VARCHAR(255) NULL AFTER storage_key,
    ADD COLUMN source_mime_type VARCHAR(120) NULL AFTER source_filename,
    ADD COLUMN source_size_bytes BIGINT UNSIGNED NULL AFTER source_mime_type,
    ADD COLUMN validated_at DATETIME(3) NULL AFTER retired_at,
    ADD COLUMN validated_by_user_id CHAR(36) NULL AFTER validated_at,
    ADD COLUMN validation_report JSON NULL AFTER validated_by_user_id,
    ADD CONSTRAINT fk_template_versions_validator FOREIGN KEY (validated_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE template_fields (
    id CHAR(36) NOT NULL,
    template_section_id CHAR(36) NOT NULL,
    ordinal INT NOT NULL,
    field_key VARCHAR(80) NOT NULL,
    label VARCHAR(255) NOT NULL,
    field_type ENUM('text', 'long_text', 'number', 'date', 'table', 'list', 'boolean', 'select', 'file') NOT NULL DEFAULT 'text',
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    guidance TEXT NULL,
    rules JSON NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_template_fields_key (template_section_id, field_key),
    KEY idx_template_fields_ordinal (template_section_id, ordinal),
    CONSTRAINT fk_template_fields_section FOREIGN KEY (template_section_id) REFERENCES template_sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
