-- ---------------------------------------------------------------------------
-- AI Engine: few-shot skill examples ("train the skill" in-context).
--
-- Staff can save a sample input and the PDD-style output the configured engine
-- produced for it, tagged with the registry. Enabled examples are injected into
-- future drafting prompts as STYLE examples (few-shot / in-context tuning).
--
-- This is explicitly NOT model fine-tuning: no weights change, no model is
-- trained, and the provider only receives the examples inside one prompt. The
-- examples are staff-authored test snippets; customer project data must never be
-- used as an example (no path from project inputs/facts into this table).
-- ---------------------------------------------------------------------------

CREATE TABLE ai_engine_examples (
    id CHAR(36) NOT NULL,
    -- Registry skill the example belongs to (validated in the application).
    registry VARCHAR(30) NOT NULL,
    title VARCHAR(160) NOT NULL,
    input_text MEDIUMTEXT NOT NULL,
    output_text MEDIUMTEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    -- Prompt order; lower comes first.
    ordinal INT NOT NULL DEFAULT 0,
    created_by_user_id CHAR(36) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_ai_engine_examples_registry (registry, enabled, ordinal),
    CONSTRAINT fk_ai_engine_examples_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
