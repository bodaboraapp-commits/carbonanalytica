-- ---------------------------------------------------------------------------
-- AI Engine settings (staff console /admin/ai).
--
-- One singleton row (id = 1) holds the OpenAI-compatible provider configuration
-- and the admin-managed drafting "skill" (free-text registry rules) used to
-- build PDD drafts.
--
-- Secrets: the API key is NEVER stored in plaintext. The application encrypts it
-- with AES-256-GCM using AI_ENGINE_SECRET from the environment and stores the
-- ciphertext plus the last four characters (for a masked display only). The
-- ciphertext is unreadable without the environment secret.
--
-- Provider, model, temperature and the skills are ordinary settings: they are
-- data, not code, so staff can change them without a deploy.
-- ---------------------------------------------------------------------------

CREATE TABLE ai_engine_settings (
    -- Singleton: the CHECK constraint keeps a second row out.
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- Provider preset label; every preset speaks the OpenAI chat-completions API.
    provider VARCHAR(40) NOT NULL DEFAULT 'openai',
    -- API root, e.g. https://api.openai.com/v1. The client appends /chat/completions.
    base_url VARCHAR(500) NOT NULL DEFAULT 'https://api.openai.com/v1',
    model VARCHAR(120) NOT NULL DEFAULT 'gpt-4o-mini',
    temperature DECIMAL(3, 2) NOT NULL DEFAULT 0.20,
    max_tokens INT NOT NULL DEFAULT 1200,
    -- base64(nonce | auth tag | ciphertext), AES-256-GCM. NULL = no stored key.
    api_key_ciphertext TEXT NULL,
    -- Last four characters of the key, for the masked write-only field.
    api_key_last4 VARCHAR(4) NULL,
    -- Admin-managed drafting skill/rules, injected as the system prompt.
    skill_gold_standard MEDIUMTEXT NULL,
    skill_verra_vcs MEDIUMTEXT NULL,
    global_instructions MEDIUMTEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_user_id CHAR(36) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    CONSTRAINT ck_ai_engine_singleton CHECK (id = 1),
    CONSTRAINT fk_ai_engine_settings_user FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The defaults are deliberately "not configured": the engine stays off and no
-- key exists until platform staff configure it on /admin/ai.
INSERT INTO ai_engine_settings (id) VALUES (1);
