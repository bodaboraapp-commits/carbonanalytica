
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `provider` enum('google') NOT NULL,
  `provider_account_id` varchar(191) NOT NULL,
  `scope` varchar(255) DEFAULT NULL,
  `token_type` varchar(40) DEFAULT NULL,
  `access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_provider` (`provider`,`provider_account_id`),
  KEY `idx_accounts_user` (`user_id`),
  CONSTRAINT `fk_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` char(36) NOT NULL,
  `team_id` char(36) DEFAULT NULL,
  `actor_user_id` char(36) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `target_type` varchar(40) DEFAULT NULL,
  `target_id` varchar(64) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_team_created` (`team_id`,`created_at`),
  KEY `idx_audit_logs_actor_created` (`actor_user_id`,`created_at`),
  KEY `idx_audit_logs_action` (`action`),
  CONSTRAINT `fk_audit_logs_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_logs_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES ('23197f62-bd3f-44ff-98b7-580750886bd0','7666f403-5038-4422-8353-fc71a525194f','26a67b39-1ffc-424f-a88e-699e1a412d18','project.created','project','49006a57-bb84-4754-8540-acec174290bd','{\"name\":\"Rift Valley Reforestation\"}','unknown','','2026-11-02 12:38:42.113'),('42e27697-14a0-4c6b-9e0e-fed4bc0f9f4e','7666f403-5038-4422-8353-fc71a525194f','086f948f-1b55-49de-a66f-6ea670e095b5','review_task.created','review_task','fcfd2a66-1f5c-4190-837a-8ab7b2dffb95','{\"work_type\":\"draft_review\",\"priority\":\"low\"}','unknown','','2026-11-02 12:38:42.892'),('6345bfb3-81b7-44e7-81db-eaf321b740eb','7666f403-5038-4422-8353-fc71a525194f','26a67b39-1ffc-424f-a88e-699e1a412d18','project.created','project','12a76bc0-5121-4921-8688-fc2c2c0b0bd5','{\"name\":\"Kisumu Clean Cookstoves\"}','unknown','','2026-11-02 12:38:42.105'),('65428cea-b092-4e1f-81af-bf945090c251','7666f403-5038-4422-8353-fc71a525194f','086f948f-1b55-49de-a66f-6ea670e095b5','review_task.created','review_task','e495b758-591c-4fe9-9b55-a5b8ff7c1684','{\"work_type\":\"project_validation\",\"priority\":\"urgent\"}','unknown','','2026-11-02 12:38:42.862'),('65599e2d-d918-4596-8c21-08a1a37d53ac','d05a648f-f569-4614-8e6e-979b91956982','086f948f-1b55-49de-a66f-6ea670e095b5','auth.signup','user','086f948f-1b55-49de-a66f-6ea670e095b5','{\"locale\":\"en\"}','unknown','','2026-11-02 12:38:42.819'),('cdd8fed2-8cb7-4e06-ad39-314c8871951a','7666f403-5038-4422-8353-fc71a525194f','086f948f-1b55-49de-a66f-6ea670e095b5','review_task.created','review_task','aaba2965-fb3f-4bd5-adcc-eb98a8645c97','{\"work_type\":\"fact_sheet_review\",\"priority\":\"normal\"}','unknown','','2026-11-02 12:38:42.882'),('d69560a3-17d6-482c-897c-7a2730b3496d','7666f403-5038-4422-8353-fc71a525194f','086f948f-1b55-49de-a66f-6ea670e095b5','review_task.created','review_task','730b2ffd-5377-41db-abb4-ca532c573b0a','{\"work_type\":\"organisation_kyb\",\"priority\":\"high\"}','unknown','','2026-11-02 12:38:42.848'),('dfa16c7b-a320-4f40-b657-b1fe977bc985','7666f403-5038-4422-8353-fc71a525194f','26a67b39-1ffc-424f-a88e-699e1a412d18','auth.signup','user','26a67b39-1ffc-424f-a88e-699e1a412d18','{\"locale\":\"en\"}','unknown','','2026-11-02 12:38:42.082'),('f632d1fb-5676-47d5-b31e-7e851492f5fc','7666f403-5038-4422-8353-fc71a525194f','086f948f-1b55-49de-a66f-6ea670e095b5','review_task.created','review_task','3990de7a-dbf5-4383-8d8a-f0115c74b337','{\"work_type\":\"template_review\",\"priority\":\"normal\"}','unknown','','2026-11-02 12:38:42.872');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comments` (
  `id` char(36) NOT NULL,
  `draft_id` char(36) NOT NULL,
  `draft_section_id` char(36) DEFAULT NULL,
  `author_user_id` char(36) DEFAULT NULL,
  `body` text NOT NULL,
  `anchor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`anchor`)),
  `resolved_at` datetime(3) DEFAULT NULL,
  `resolved_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_comments_draft` (`draft_id`),
  KEY `idx_comments_section` (`draft_section_id`),
  KEY `fk_comments_author` (`author_user_id`),
  KEY `fk_comments_resolver` (`resolved_by_user_id`),
  CONSTRAINT `fk_comments_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comments_draft` FOREIGN KEY (`draft_id`) REFERENCES `drafts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_resolver` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comments_section` FOREIGN KEY (`draft_section_id`) REFERENCES `draft_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `comments` WRITE;
/*!40000 ALTER TABLE `comments` DISABLE KEYS */;
/*!40000 ALTER TABLE `comments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `draft_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `draft_sections` (
  `id` char(36) NOT NULL,
  `draft_id` char(36) NOT NULL,
  `template_section_id` char(36) NOT NULL,
  `heading` varchar(255) NOT NULL,
  `content_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`content_json`)),
  `content_text` mediumtext DEFAULT NULL,
  `status` enum('needs_input','generated','edited','approved','intentionally_blank') NOT NULL DEFAULT 'needs_input',
  `citations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`citations`)),
  `generated_at` datetime(3) DEFAULT NULL,
  `edited_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_draft_sections_draft_section` (`draft_id`,`template_section_id`),
  KEY `idx_draft_sections_draft` (`draft_id`),
  KEY `fk_draft_sections_template_section` (`template_section_id`),
  KEY `fk_draft_sections_editor` (`edited_by_user_id`),
  CONSTRAINT `fk_draft_sections_draft` FOREIGN KEY (`draft_id`) REFERENCES `drafts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_draft_sections_editor` FOREIGN KEY (`edited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_draft_sections_template_section` FOREIGN KEY (`template_section_id`) REFERENCES `template_sections` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `draft_sections` WRITE;
/*!40000 ALTER TABLE `draft_sections` DISABLE KEYS */;
/*!40000 ALTER TABLE `draft_sections` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `draft_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `draft_versions` (
  `id` char(36) NOT NULL,
  `draft_id` char(36) NOT NULL,
  `version` int(11) NOT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`snapshot`)),
  `created_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_draft_versions_draft_version` (`draft_id`,`version`),
  KEY `idx_draft_versions_draft` (`draft_id`),
  KEY `fk_draft_versions_creator` (`created_by_user_id`),
  CONSTRAINT `fk_draft_versions_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_draft_versions_draft` FOREIGN KEY (`draft_id`) REFERENCES `drafts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `draft_versions` WRITE;
/*!40000 ALTER TABLE `draft_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `draft_versions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `drafts` (
  `id` char(36) NOT NULL,
  `project_id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `template_version_id` char(36) NOT NULL,
  `fact_sheet_version_id` char(36) NOT NULL,
  `language` enum('en','fr') NOT NULL DEFAULT 'en',
  `status` enum('draft','in_review','approved','exported','stale') NOT NULL DEFAULT 'draft',
  `version` int(11) NOT NULL DEFAULT 1,
  `is_labeled_draft` varchar(20) NOT NULL DEFAULT 'draft',
  `created_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_drafts_project` (`project_id`),
  KEY `idx_drafts_team` (`team_id`),
  KEY `idx_drafts_status` (`status`),
  KEY `fk_drafts_template_version` (`template_version_id`),
  KEY `fk_drafts_fact_sheet_version` (`fact_sheet_version_id`),
  KEY `fk_drafts_creator` (`created_by_user_id`),
  CONSTRAINT `fk_drafts_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_drafts_fact_sheet_version` FOREIGN KEY (`fact_sheet_version_id`) REFERENCES `fact_sheet_versions` (`id`),
  CONSTRAINT `fk_drafts_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drafts_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drafts_template_version` FOREIGN KEY (`template_version_id`) REFERENCES `template_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `drafts` WRITE;
/*!40000 ALTER TABLE `drafts` DISABLE KEYS */;
INSERT INTO `drafts` VALUES ('7eae3e82-6028-4cbe-87bb-344eb8977f30','12a76bc0-5121-4921-8688-fc2c2c0b0bd5','7666f403-5038-4422-8353-fc71a525194f','6c07c968-095f-42dc-ac5c-2720764a6f01','dc9c9a2b-489c-4e02-bca6-5f5f7c2348a9','en','in_review',1,'draft','26a67b39-1ffc-424f-a88e-699e1a412d18','2026-11-02 12:38:42.920','2026-11-02 12:38:42.920');
/*!40000 ALTER TABLE `drafts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `email_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_outbox` (
  `id` char(36) NOT NULL,
  `to_email` varchar(254) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_text` mediumtext NOT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `kind` varchar(40) NOT NULL,
  `sent_at` datetime(3) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `provider_message_id` varchar(120) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_email_outbox_to` (`to_email`),
  KEY `idx_email_outbox_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `email_outbox` WRITE;
/*!40000 ALTER TABLE `email_outbox` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_outbox` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `fact_sheet_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fact_sheet_versions` (
  `id` char(36) NOT NULL,
  `project_id` char(36) NOT NULL,
  `version` int(11) NOT NULL,
  `status` enum('draft','approved','superseded') NOT NULL DEFAULT 'draft',
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`snapshot`)),
  `created_by_user_id` char(36) DEFAULT NULL,
  `approved_by_user_id` char(36) DEFAULT NULL,
  `approved_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fact_sheet_versions_project` (`project_id`,`version`),
  KEY `idx_fact_sheet_versions_status` (`project_id`,`status`),
  KEY `fk_fact_sheet_versions_creator` (`created_by_user_id`),
  KEY `fk_fact_sheet_versions_approver` (`approved_by_user_id`),
  CONSTRAINT `fk_fact_sheet_versions_approver` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fact_sheet_versions_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fact_sheet_versions_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `fact_sheet_versions` WRITE;
/*!40000 ALTER TABLE `fact_sheet_versions` DISABLE KEYS */;
INSERT INTO `fact_sheet_versions` VALUES ('dc9c9a2b-489c-4e02-bca6-5f5f7c2348a9','12a76bc0-5121-4921-8688-fc2c2c0b0bd5',1,'approved','{\"facts\":[],\"approved_for\":\"demo seed\"}','26a67b39-1ffc-424f-a88e-699e1a412d18','26a67b39-1ffc-424f-a88e-699e1a412d18','2026-11-02 12:38:42.000','2026-11-02 12:38:42.915');
/*!40000 ALTER TABLE `fact_sheet_versions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `facts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facts` (
  `id` char(36) NOT NULL,
  `project_id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `category` enum('project','boundary','baseline','additionality','monitoring','stakeholders','safeguards','sdg','financial') NOT NULL,
  `fact_key` varchar(80) NOT NULL,
  `label` varchar(200) NOT NULL,
  `value_type` enum('string','number','date','coordinate','boolean','list') NOT NULL,
  `value_text` text DEFAULT NULL,
  `value_numeric` decimal(18,4) DEFAULT NULL,
  `value_date` date DEFAULT NULL,
  `value_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value_json`)),
  `unit` varchar(40) DEFAULT NULL,
  `confidence` enum('high','medium','low') DEFAULT NULL,
  `status` enum('ai_suggested','confirmed','corrected','rejected','needs_input') NOT NULL DEFAULT 'ai_suggested',
  `source_input_id` char(36) DEFAULT NULL,
  `source_quote` text DEFAULT NULL,
  `source_locator` varchar(120) DEFAULT NULL,
  `confirmed_by_user_id` char(36) DEFAULT NULL,
  `confirmed_at` datetime(3) DEFAULT NULL,
  `created_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facts_project_key` (`project_id`,`fact_key`),
  KEY `idx_facts_project_status` (`project_id`,`status`),
  KEY `idx_facts_team` (`team_id`),
  KEY `fk_facts_source_input` (`source_input_id`),
  KEY `fk_facts_confirmer` (`confirmed_by_user_id`),
  KEY `fk_facts_creator` (`created_by_user_id`),
  CONSTRAINT `fk_facts_confirmer` FOREIGN KEY (`confirmed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_facts_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_facts_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_facts_source_input` FOREIGN KEY (`source_input_id`) REFERENCES `inputs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_facts_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `facts` WRITE;
/*!40000 ALTER TABLE `facts` DISABLE KEYS */;
/*!40000 ALTER TABLE `facts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `gap_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gap_items` (
  `id` char(36) NOT NULL,
  `gap_report_id` char(36) NOT NULL,
  `severity` enum('info','warning','error') NOT NULL,
  `type` enum('missing_fact','inconsistent_number','inconsistent_date','inconsistent_location','weak_evidence','format_rule','missing_citation','unsupported_number') NOT NULL,
  `message` text NOT NULL,
  `section_key` varchar(60) DEFAULT NULL,
  `fact_key` varchar(80) DEFAULT NULL,
  `resolved_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_gap_items_report` (`gap_report_id`),
  KEY `idx_gap_items_severity` (`severity`),
  CONSTRAINT `fk_gap_items_report` FOREIGN KEY (`gap_report_id`) REFERENCES `gap_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `gap_items` WRITE;
/*!40000 ALTER TABLE `gap_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `gap_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `gap_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gap_reports` (
  `id` char(36) NOT NULL,
  `draft_id` char(36) NOT NULL,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`summary`)),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_gap_reports_draft` (`draft_id`),
  CONSTRAINT `fk_gap_reports_draft` FOREIGN KEY (`draft_id`) REFERENCES `drafts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `gap_reports` WRITE;
/*!40000 ALTER TABLE `gap_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `gap_reports` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inputs` (
  `id` char(36) NOT NULL,
  `project_id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `kind` enum('text','document','transcript','image','audio','video') NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `size_bytes` bigint(20) unsigned DEFAULT NULL,
  `storage_key` varchar(500) DEFAULT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `status` enum('pending','uploading','uploaded','parsing','parsed','failed') NOT NULL DEFAULT 'pending',
  `language` enum('en','fr') DEFAULT NULL,
  `source_text` mediumtext DEFAULT NULL,
  `uploaded_by_user_id` char(36) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `parsed_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `deleted_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inputs_project` (`project_id`),
  KEY `idx_inputs_team` (`team_id`),
  KEY `idx_inputs_status` (`status`),
  KEY `fk_inputs_uploader` (`uploaded_by_user_id`),
  CONSTRAINT `fk_inputs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inputs_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inputs_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inputs` WRITE;
/*!40000 ALTER TABLE `inputs` DISABLE KEYS */;
/*!40000 ALTER TABLE `inputs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `methodologies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `methodologies` (
  `id` char(36) NOT NULL,
  `registry` enum('gold_standard','verra_vcs') NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(200) NOT NULL,
  `version` varchar(40) NOT NULL,
  `language` enum('en','fr') NOT NULL DEFAULT 'en',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_provisional` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_methodologies_code` (`registry`,`code`,`version`,`language`),
  KEY `idx_methodologies_registry_active` (`registry`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `methodologies` WRITE;
/*!40000 ALTER TABLE `methodologies` DISABLE KEYS */;
INSERT INTO `methodologies` VALUES ('03e6e703-efa2-4a50-bc64-726eaa76028b','verra_vcs','VM0007','REDD+ Methodology Framework (REDD-MF)','v1.6','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 12:38:41.101','2026-11-02 12:38:41.101'),('71d60f1a-5640-45e4-934e-083321ef6b09','gold_standard','AMS-I.C','Thermal energy for the user with or without electricity','v21.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 12:38:41.065','2026-11-02 12:38:41.065'),('81426f82-6321-4e38-9bd4-e0e5a95e6ff4','verra_vcs','VM0015','Methodology for Avoided Unplanned Deforestation','v1.1','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 12:38:41.111','2026-11-02 12:38:41.111'),('c27c1739-3b13-4f4a-a81e-643d7893480c','gold_standard','AR-AMS0007','Afforestation and reforestation on lands other than wetlands','v03.1','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 12:38:41.079','2026-11-02 12:38:41.079'),('cf955b18-c456-45e3-8ce6-e9501f0bf666','verra_vcs','VM0009','Methodology for Avoided Ecosystem Conversion','v3.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 12:38:41.117','2026-11-02 12:38:41.117'),('d1b5670f-143f-4786-9fc0-3a65178e0559','gold_standard','AMS-II.G','Energy efficiency measures in thermal applications of non-renewable biomass','v13.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 12:38:41.072','2026-11-02 12:38:41.072');
/*!40000 ALTER TABLE `methodologies` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `organization_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `organization_documents` (
  `id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `kind` enum('registration_certificate','ownership_structure','declaration','tax_compliance') NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `storage_key` varchar(255) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `checksum_sha256` char(64) NOT NULL,
  `uploaded_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organization_documents_team_kind` (`team_id`,`kind`),
  KEY `idx_organization_documents_team` (`team_id`),
  KEY `fk_organization_documents_uploaded_by` (`uploaded_by_user_id`),
  CONSTRAINT `fk_organization_documents_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_organization_documents_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `organization_documents` WRITE;
/*!40000 ALTER TABLE `organization_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `organization_documents` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `organization_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `organization_profiles` (
  `id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `legal_name` varchar(200) DEFAULT NULL,
  `trading_name` varchar(200) DEFAULT NULL,
  `registration_number` varchar(80) DEFAULT NULL,
  `registration_country` varchar(80) DEFAULT NULL,
  `organization_type` enum('developer','consultant','ngo','government','academic','cooperative','other') DEFAULT NULL,
  `year_founded` smallint(6) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `registered_address` varchar(400) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `contact_name` varchar(160) DEFAULT NULL,
  `contact_role` varchar(120) DEFAULT NULL,
  `contact_email` varchar(254) DEFAULT NULL,
  `contact_phone` varchar(40) DEFAULT NULL,
  `status` enum('draft','submitted','verified','rejected') NOT NULL DEFAULT 'draft',
  `submitted_at` datetime(3) DEFAULT NULL,
  `reviewed_at` datetime(3) DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `attested_at` datetime(3) DEFAULT NULL,
  `attested_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organization_profiles_team` (`team_id`),
  KEY `idx_organization_profiles_status` (`status`),
  KEY `fk_organization_profiles_attested_by` (`attested_by_user_id`),
  CONSTRAINT `fk_organization_profiles_attested_by` FOREIGN KEY (`attested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_organization_profiles_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `organization_profiles` WRITE;
/*!40000 ALTER TABLE `organization_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `organization_profiles` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `otp_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `otp_codes` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `email` varchar(254) NOT NULL,
  `purpose` enum('signup','signin') NOT NULL,
  `code_hash` char(64) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime(3) NOT NULL,
  `consumed_at` datetime(3) DEFAULT NULL,
  `requested_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_otp_codes_user_purpose` (`user_id`,`purpose`,`consumed_at`),
  KEY `idx_otp_codes_expires` (`expires_at`),
  CONSTRAINT `fk_otp_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `otp_codes` WRITE;
/*!40000 ALTER TABLE `otp_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_codes` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `country_code` char(2) DEFAULT NULL,
  `registry` enum('gold_standard','verra_vcs') DEFAULT NULL,
  `project_type` enum('cookstoves','afforestation_reforestation','renewable_energy','clean_water_access','improved_agriculture','other') DEFAULT NULL,
  `methodology_id` char(36) DEFAULT NULL,
  `template_version_id` char(36) DEFAULT NULL,
  `status` enum('setup','collecting_inputs','extracting_facts','facts_pending_approval','drafting','review','complete','archived') NOT NULL DEFAULT 'setup',
  `language` enum('en','fr') NOT NULL DEFAULT 'en',
  `confidentiality` enum('public','confidential') NOT NULL DEFAULT 'confidential',
  `crediting_period_start` date DEFAULT NULL,
  `crediting_period_end` date DEFAULT NULL,
  `created_by_user_id` char(36) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `deleted_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_projects_team` (`team_id`),
  KEY `idx_projects_team_status` (`team_id`,`status`),
  KEY `idx_projects_deleted` (`deleted_at`),
  KEY `idx_projects_creator` (`created_by_user_id`),
  KEY `fk_projects_methodology` (`methodology_id`),
  KEY `fk_projects_template_version` (`template_version_id`),
  CONSTRAINT `fk_projects_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_methodology` FOREIGN KEY (`methodology_id`) REFERENCES `methodologies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_projects_template_version` FOREIGN KEY (`template_version_id`) REFERENCES `template_versions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
INSERT INTO `projects` VALUES ('12a76bc0-5121-4921-8688-fc2c2c0b0bd5','7666f403-5038-4422-8353-fc71a525194f','Kisumu Clean Cookstoves','Improved cookstoves for households around Kisumu, Kenya.',NULL,NULL,NULL,NULL,NULL,'review','en','confidential',NULL,NULL,'26a67b39-1ffc-424f-a88e-699e1a412d18',NULL,'2026-11-02 12:38:42.101','2026-11-02 12:38:42.898',NULL),('49006a57-bb84-4754-8540-acec174290bd','7666f403-5038-4422-8353-fc71a525194f','Rift Valley Reforestation','Afforestation and reforestation on degraded land in the Rift Valley.',NULL,NULL,NULL,NULL,NULL,'facts_pending_approval','en','confidential',NULL,NULL,'26a67b39-1ffc-424f-a88e-699e1a412d18',NULL,'2026-11-02 12:38:42.111','2026-11-02 12:38:42.895',NULL);
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `rate_limit_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limit_counters` (
  `counter_key` varchar(191) NOT NULL,
  `window_start` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`counter_key`),
  KEY `idx_rate_limit_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `rate_limit_counters` WRITE;
/*!40000 ALTER TABLE `rate_limit_counters` DISABLE KEYS */;
/*!40000 ALTER TABLE `rate_limit_counters` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `review_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `review_tasks` (
  `id` char(36) NOT NULL,
  `team_id` char(36) DEFAULT NULL,
  `work_type` enum('organisation_kyb','project_validation','template_review','fact_sheet_review','draft_review','other') NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('unassigned','in_progress','blocked','completed','cancelled') NOT NULL DEFAULT 'unassigned',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `assigned_user_id` char(36) DEFAULT NULL,
  `due_at` datetime(3) DEFAULT NULL,
  `target_type` varchar(40) DEFAULT NULL,
  `target_id` char(36) DEFAULT NULL,
  `created_by_user_id` char(36) DEFAULT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  `completed_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_review_tasks_assigned` (`assigned_user_id`,`status`,`due_at`),
  KEY `idx_review_tasks_work_type` (`work_type`,`status`),
  KEY `idx_review_tasks_team` (`team_id`),
  KEY `idx_review_tasks_due` (`due_at`),
  KEY `idx_review_tasks_target` (`target_type`,`target_id`),
  KEY `fk_review_tasks_creator` (`created_by_user_id`),
  KEY `fk_review_tasks_completer` (`completed_by_user_id`),
  CONSTRAINT `fk_review_tasks_assignee` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_review_tasks_completer` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_review_tasks_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_review_tasks_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `review_tasks` WRITE;
/*!40000 ALTER TABLE `review_tasks` DISABLE KEYS */;
INSERT INTO `review_tasks` VALUES ('3990de7a-dbf5-4383-8d8a-f0115c74b337','7666f403-5038-4422-8353-fc71a525194f','template_review','QA the Gold Standard v1.4 template structure','Headings, numbering and table columns must match the published guide.','in_progress','normal','086f948f-1b55-49de-a66f-6ea670e095b5','2026-11-07 12:38:42.000',NULL,NULL,'086f948f-1b55-49de-a66f-6ea670e095b5',NULL,NULL,'2026-11-02 12:38:42.868','2026-11-02 12:38:42.868'),('730b2ffd-5377-41db-abb4-ca532c573b0a','7666f403-5038-4422-8353-fc71a525194f','organisation_kyb','Review organisation documents — Amina Demo’s workspace','Check registration certificate and ownership structure before verifying the workspace.','unassigned','high',NULL,'2026-10-31 12:38:42.000',NULL,NULL,'086f948f-1b55-49de-a66f-6ea670e095b5',NULL,NULL,'2026-11-02 12:38:42.841','2026-11-02 12:38:42.841'),('aaba2965-fb3f-4bd5-adcc-eb98a8645c97','7666f403-5038-4422-8353-fc71a525194f','fact_sheet_review','Approve fact sheet — Rift Valley Reforestation','Confirm ex-ante estimates and the baseline quotations before drafting.','unassigned','normal',NULL,'2026-11-05 12:38:42.000',NULL,NULL,'086f948f-1b55-49de-a66f-6ea670e095b5',NULL,NULL,'2026-11-02 12:38:42.878','2026-11-02 12:38:42.878'),('e495b758-591c-4fe9-9b55-a5b8ff7c1684','7666f403-5038-4422-8353-fc71a525194f','project_validation','Validate project boundary — Kisumu Clean Cookstoves','Boundary coordinates and included households need a second pair of eyes.','in_progress','urgent','086f948f-1b55-49de-a66f-6ea670e095b5','2026-11-01 12:38:42.000',NULL,NULL,'086f948f-1b55-49de-a66f-6ea670e095b5',NULL,NULL,'2026-11-02 12:38:42.858','2026-11-02 12:38:42.858'),('fcfd2a66-1f5c-4190-837a-8ab7b2dffb95','7666f403-5038-4422-8353-fc71a525194f','draft_review','Review draft sections — Kisumu Clean Cookstoves','Check citations and the two \"needs input\" markers in section B.','in_progress','low','086f948f-1b55-49de-a66f-6ea670e095b5','2026-11-09 12:38:42.000',NULL,NULL,'086f948f-1b55-49de-a66f-6ea670e095b5',NULL,NULL,'2026-11-02 12:38:42.888','2026-11-02 12:38:42.888');
/*!40000 ALTER TABLE `review_tasks` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `version` varchar(120) NOT NULL,
  `applied_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES ('0001_init.sql','2026-11-02 15:38:38.377'),('0002_template_library.sql','2026-11-02 15:38:38.508'),('0003_organization_onboarding.sql','2026-11-02 15:38:38.620'),('0004_review_tasks.sql','2026-11-02 15:38:38.687'),('0005_otp_verification.sql','2026-11-02 15:38:38.762'),('0006_otp_preference.sql','2026-11-02 15:38:38.788');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `team_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_invitations` (
  `id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `email` varchar(254) NOT NULL,
  `role` enum('owner','admin','editor','viewer') NOT NULL,
  `token_hash` char(64) NOT NULL,
  `invited_by_user_id` char(36) DEFAULT NULL,
  `expires_at` datetime(3) NOT NULL,
  `accepted_at` datetime(3) DEFAULT NULL,
  `accepted_by_user_id` char(36) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `live_email` varchar(254) GENERATED ALWAYS AS (if(`accepted_at` is null and `revoked_at` is null,lcase(`email`),NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_invitations_token` (`token_hash`),
  UNIQUE KEY `uq_team_invitations_live_email` (`team_id`,`live_email`),
  KEY `idx_team_invitations_email` (`email`),
  KEY `idx_team_invitations_team` (`team_id`),
  KEY `fk_team_invitations_inviter` (`invited_by_user_id`),
  KEY `fk_team_invitations_accepted_by` (`accepted_by_user_id`),
  CONSTRAINT `fk_team_invitations_accepted_by` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_team_invitations_inviter` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_team_invitations_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `team_invitations` WRITE;
/*!40000 ALTER TABLE `team_invitations` DISABLE KEYS */;
/*!40000 ALTER TABLE `team_invitations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `team_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_members` (
  `id` char(36) NOT NULL,
  `team_id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `role` enum('owner','admin','editor','viewer') NOT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `invited_by_user_id` char(36) DEFAULT NULL,
  `joined_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `owner_scope` char(36) GENERATED ALWAYS AS (if(`role` = 'owner',`team_id`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_members_team_user` (`team_id`,`user_id`),
  UNIQUE KEY `uq_team_members_single_owner` (`owner_scope`),
  KEY `idx_team_members_user` (`user_id`),
  KEY `idx_team_members_team` (`team_id`),
  KEY `fk_team_members_inviter` (`invited_by_user_id`),
  CONSTRAINT `fk_team_members_inviter` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_team_members_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `team_members` WRITE;
/*!40000 ALTER TABLE `team_members` DISABLE KEYS */;
INSERT INTO `team_members` VALUES ('0372682a-e409-425c-827c-0d299e197951','d05a648f-f569-4614-8e6e-979b91956982','086f948f-1b55-49de-a66f-6ea670e095b5','owner','active',NULL,'2026-11-02 12:38:42.812','2026-11-02 12:38:42.812','2026-11-02 12:38:42.812','d05a648f-f569-4614-8e6e-979b91956982'),('7b5248eb-a77e-45bd-8912-084a1013532c','7666f403-5038-4422-8353-fc71a525194f','26a67b39-1ffc-424f-a88e-699e1a412d18','owner','active',NULL,'2026-11-02 12:38:42.072','2026-11-02 12:38:42.072','2026-11-02 12:38:42.072','7666f403-5038-4422-8353-fc71a525194f');
/*!40000 ALTER TABLE `team_members` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teams` (
  `id` char(36) NOT NULL,
  `name` varchar(120) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `owner_user_id` char(36) NOT NULL,
  `is_personal` tinyint(1) NOT NULL DEFAULT 0,
  `plan` enum('free','payg','team') NOT NULL DEFAULT 'free',
  `data_region` varchar(20) NOT NULL DEFAULT 'auto',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `deleted_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teams_slug` (`slug`),
  KEY `idx_teams_owner` (`owner_user_id`),
  KEY `idx_teams_deleted` (`deleted_at`),
  CONSTRAINT `fk_teams_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `teams` WRITE;
/*!40000 ALTER TABLE `teams` DISABLE KEYS */;
INSERT INTO `teams` VALUES ('7666f403-5038-4422-8353-fc71a525194f','Amina Demo’s workspace','amina-demo-zdmfg','26a67b39-1ffc-424f-a88e-699e1a412d18',1,'free','auto',NULL,'2026-11-02 12:38:42.066','2026-11-02 12:38:42.066',NULL),('d05a648f-f569-4614-8e6e-979b91956982','Ian Admin’s workspace','ian-admin-b3xwa','086f948f-1b55-49de-a66f-6ea670e095b5',1,'free','auto',NULL,'2026-11-02 12:38:42.803','2026-11-02 12:38:42.803',NULL);
/*!40000 ALTER TABLE `teams` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `template_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `template_fields` (
  `id` char(36) NOT NULL,
  `template_section_id` char(36) NOT NULL,
  `ordinal` int(11) NOT NULL,
  `field_key` varchar(80) NOT NULL,
  `label` varchar(255) NOT NULL,
  `field_type` enum('text','long_text','number','date','table','list','boolean','select','file') NOT NULL DEFAULT 'text',
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `guidance` text DEFAULT NULL,
  `rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rules`)),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_fields_key` (`template_section_id`,`field_key`),
  KEY `idx_template_fields_ordinal` (`template_section_id`,`ordinal`),
  CONSTRAINT `fk_template_fields_section` FOREIGN KEY (`template_section_id`) REFERENCES `template_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `template_fields` WRITE;
/*!40000 ALTER TABLE `template_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `template_fields` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `template_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `template_sections` (
  `id` char(36) NOT NULL,
  `template_version_id` char(36) NOT NULL,
  `parent_section_id` char(36) DEFAULT NULL,
  `ordinal` int(11) NOT NULL,
  `stable_key` varchar(60) NOT NULL,
  `heading` varchar(255) NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `allow_intentionally_blank` tinyint(1) NOT NULL DEFAULT 1,
  `guidance` text DEFAULT NULL,
  `format_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`format_rules`)),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_sections_key` (`template_version_id`,`stable_key`),
  KEY `idx_template_sections_ordinal` (`template_version_id`,`ordinal`),
  KEY `idx_template_sections_parent` (`parent_section_id`),
  CONSTRAINT `fk_template_sections_parent` FOREIGN KEY (`parent_section_id`) REFERENCES `template_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_template_sections_version` FOREIGN KEY (`template_version_id`) REFERENCES `template_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `template_sections` WRITE;
/*!40000 ALTER TABLE `template_sections` DISABLE KEYS */;
INSERT INTO `template_sections` VALUES ('052198e8-6644-46ee-b601-be6f832b2fc9','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,4,'A.2','Eligibility',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.221'),('0860087d-cd83-4e5c-8fa1-af8a3be80b1b','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,18,'APP1','Safeguarding assessment',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.318'),('134d6ca0-0680-47f4-9806-9c401816595e','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,1,'KPI','Key Project Information',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.198'),('13c4f5a4-1da3-463a-8283-b98b07992dd8','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,14,'B.5','Monitoring plan',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.305'),('199cb8e7-ef1f-4aff-80d6-2eb8a516777b','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,19,'APP2','Contacts',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.322'),('45d3301c-0cf2-4ef2-885f-747295506455','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,13,'B.4','Ex ante estimates',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.302'),('5aeb2880-dedf-4dd3-a611-ec62baf06b45','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,21,'APP4','Design changes',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.329'),('90acf4ab-50fd-44d5-aaa5-9268b152b817','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,12,'B.3','Additionality',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.298'),('998e8085-1c89-4093-be18-84a416a6e479','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,11,'B.2','Boundary',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.293'),('a3cf6752-2a6c-44e4-ad6e-17df3b62d916','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,10,'B.1','Baseline',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.289'),('a3f407e7-e4c2-43b9-8f8a-ac8102047d8b','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,3,'A.1','Purpose and general description',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.217'),('a743bec1-78c8-490e-92c0-8c8ce0d3fa81','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,15,'C','Duration and crediting period',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.309'),('aa32ed8c-ee85-48f7-83f1-b71ac7a7fb31','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,17,'E','Local stakeholder consultation',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.315'),('ba6c7045-3110-4295-8018-1bfaa5567f04','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,5,'A.3','Project ownership',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.225'),('c90729b6-1f08-4563-b0b4-7c07a630b8d5','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,6,'A.4','Location',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.233'),('d38dc904-788f-4f72-82ac-ef963d920fc8','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,9,'B','Methodology and SDG contributions',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.285'),('d5b72694-566c-4d0e-b443-20e0eb30fa44','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,2,'A','Description of project',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.213'),('d754410b-767e-41a2-9399-d14c8d852601','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,7,'A.5','Technologies and/or practices',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.270'),('ecd81852-76ec-4dbf-85c8-ae236946c1dd','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,8,'A.6','Scale and finance',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.278'),('ef30830c-8d0b-411f-a54e-2d1f2c49a754','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,16,'D','Safeguarding principles and gender summary',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.312'),('f96d9bd5-2102-4fa9-9434-2493b5025f45','6c07c968-095f-42dc-ac5c-2720764a6f01',NULL,20,'APP3','Land-use and forests information',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 12:38:41.325');
/*!40000 ALTER TABLE `template_sections` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `template_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `template_versions` (
  `id` char(36) NOT NULL,
  `template_id` char(36) NOT NULL,
  `version_label` varchar(40) NOT NULL,
  `language` enum('en','fr') NOT NULL DEFAULT 'en',
  `effective_date` date DEFAULT NULL,
  `status` enum('draft','published','retired') NOT NULL DEFAULT 'draft',
  `source_url` varchar(1000) DEFAULT NULL,
  `license_note` text DEFAULT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `storage_key` varchar(500) DEFAULT NULL,
  `source_filename` varchar(255) DEFAULT NULL,
  `source_mime_type` varchar(120) DEFAULT NULL,
  `source_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `published_at` datetime(3) DEFAULT NULL,
  `retired_at` datetime(3) DEFAULT NULL,
  `validated_at` datetime(3) DEFAULT NULL,
  `validated_by_user_id` char(36) DEFAULT NULL,
  `validation_report` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_report`)),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_versions_label` (`template_id`,`version_label`),
  KEY `idx_template_versions_status` (`status`),
  KEY `fk_template_versions_validator` (`validated_by_user_id`),
  CONSTRAINT `fk_template_versions_template` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_template_versions_validator` FOREIGN KEY (`validated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `template_versions` WRITE;
/*!40000 ALTER TABLE `template_versions` DISABLE KEYS */;
INSERT INTO `template_versions` VALUES ('6c07c968-095f-42dc-ac5c-2720764a6f01','e572e6d1-4822-4f23-ae26-913da4d66228','v1.4','en','2023-06-21','draft','https://globalgoals.goldstandard.org/standards/TGuide-PreReview_V1.4-Project-Design-Document.pdf','Source link recorded. Permission to store and use must be confirmed by the owner before publishing (open question).',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 12:38:41.182','2026-11-02 12:38:41.182');
/*!40000 ALTER TABLE `template_versions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `templates` (
  `id` char(36) NOT NULL,
  `name` varchar(200) NOT NULL,
  `kind` enum('pdd_template','methodology_document','approved_pdd','guidance') NOT NULL,
  `registry` enum('gold_standard','verra_vcs') DEFAULT NULL,
  `methodology_code` varchar(40) DEFAULT NULL,
  `language` enum('en','fr') NOT NULL DEFAULT 'en',
  `description` text DEFAULT NULL,
  `created_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_templates_registry_kind` (`registry`,`kind`),
  KEY `idx_templates_methodology` (`methodology_code`),
  KEY `fk_templates_creator` (`created_by_user_id`),
  CONSTRAINT `fk_templates_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `templates` WRITE;
/*!40000 ALTER TABLE `templates` DISABLE KEYS */;
INSERT INTO `templates` VALUES ('e572e6d1-4822-4f23-ae26-913da4d66228','Gold Standard Template Guide v1.4','pdd_template','gold_standard',NULL,'en','Key Project Information and Project Design Document structure.',NULL,'2026-11-02 12:38:41.143','2026-11-02 12:38:41.143');
/*!40000 ALTER TABLE `templates` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` char(36) NOT NULL,
  `email` varchar(254) NOT NULL,
  `email_lower` varchar(254) GENERATED ALWAYS AS (lcase(`email`)) STORED,
  `password_hash` varchar(255) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `locale` enum('en','fr') NOT NULL DEFAULT 'en',
  `email_verified_at` datetime(3) DEFAULT NULL,
  `otp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_platform_admin` tinyint(1) NOT NULL DEFAULT 0,
  `default_team_id` char(36) DEFAULT NULL,
  `last_seen_at` datetime(3) DEFAULT NULL,
  `deletion_requested_at` datetime(3) DEFAULT NULL,
  `deleted_at` datetime(3) DEFAULT NULL,
  `session_version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email_lower`),
  KEY `idx_users_default_team` (`default_team_id`),
  KEY `idx_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES ('086f948f-1b55-49de-a66f-6ea670e095b5','admin@pddgen.local','admin@pddgen.local','$argon2id$v=19$m=65536,t=4,p=1$aHdrVkI2TDFoRkJvT2pLag$1V1mNQxovKwumhtTvTdHndCf5mzxwWHhkIPzBn8M1Xs','Ian Admin',NULL,'en','2026-11-02 12:38:42.822',0,1,'d05a648f-f569-4614-8e6e-979b91956982',NULL,NULL,NULL,1,'2026-11-02 12:38:42.761','2026-11-02 12:38:42.822'),('26a67b39-1ffc-424f-a88e-699e1a412d18','demo@pddgen.local','demo@pddgen.local','$argon2id$v=19$m=65536,t=4,p=1$VkhaU0JUUDJOZmo3Z0Z4NA$EUXpZMJNoyS1mnbcmElY4IxebxvxFzy6oKWOX/NgX4M','Amina Demo',NULL,'en','2026-11-02 12:38:42.087',0,0,'7666f403-5038-4422-8353-fc71a525194f',NULL,NULL,NULL,1,'2026-11-02 12:38:42.018','2026-11-02 12:38:42.087');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `verification_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `verification_tokens` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `type` enum('email_verification','password_reset','email_change') NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  `used_at` datetime(3) DEFAULT NULL,
  `requested_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_verification_tokens_hash` (`token_hash`),
  KEY `idx_verification_tokens_user_type` (`user_id`,`type`),
  CONSTRAINT `fk_verification_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `verification_tokens` WRITE;
/*!40000 ALTER TABLE `verification_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `verification_tokens` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

