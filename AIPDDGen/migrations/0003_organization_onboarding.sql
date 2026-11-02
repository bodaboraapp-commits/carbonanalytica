-- ---------------------------------------------------------------------------
-- Organization onboarding (product-owner request, mirrors the reference wizard)
--
--  * one organization profile per workspace (team), created lazily on first save
--  * the four wizard steps are derived from this data, not stored separately
--  * required verification documents, one current file per requirement
--  * status flow: draft -> submitted -> verified | rejected (admin review is a
--    later step; the columns and audit trail exist now)
-- ---------------------------------------------------------------------------

CREATE TABLE organization_profiles (
    id CHAR(36) NOT NULL,
    team_id CHAR(36) NOT NULL,
    legal_name VARCHAR(200) NULL,
    trading_name VARCHAR(200) NULL,
    registration_number VARCHAR(80) NULL,
    registration_country VARCHAR(80) NULL,
    organization_type ENUM('developer', 'consultant', 'ngo', 'government', 'academic', 'cooperative', 'other') NULL,
    year_founded SMALLINT NULL,
    website VARCHAR(255) NULL,
    registered_address VARCHAR(400) NULL,
    city VARCHAR(120) NULL,
    contact_name VARCHAR(160) NULL,
    contact_role VARCHAR(120) NULL,
    contact_email VARCHAR(254) NULL,
    contact_phone VARCHAR(40) NULL,
    status ENUM('draft', 'submitted', 'verified', 'rejected') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME(3) NULL,
    reviewed_at DATETIME(3) NULL,
    review_note VARCHAR(500) NULL,
    attested_at DATETIME(3) NULL,
    attested_by_user_id CHAR(36) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_organization_profiles_team (team_id),
    KEY idx_organization_profiles_status (status),
    CONSTRAINT fk_organization_profiles_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_organization_profiles_attested_by FOREIGN KEY (attested_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organization_documents (
    id CHAR(36) NOT NULL,
    team_id CHAR(36) NOT NULL,
    kind ENUM('registration_certificate', 'ownership_structure', 'declaration', 'tax_compliance') NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    uploaded_by_user_id CHAR(36) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_organization_documents_team_kind (team_id, kind),
    KEY idx_organization_documents_team (team_id),
    CONSTRAINT fk_organization_documents_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_organization_documents_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
