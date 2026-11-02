
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
DROP TABLE IF EXISTS `ai_engine_examples`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_engine_examples` (
  `id` char(36) NOT NULL,
  `registry` varchar(30) NOT NULL,
  `title` varchar(160) NOT NULL,
  `input_text` mediumtext NOT NULL,
  `output_text` mediumtext DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `ordinal` int(11) NOT NULL DEFAULT 0,
  `created_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_ai_engine_examples_registry` (`registry`,`enabled`,`ordinal`),
  KEY `fk_ai_engine_examples_creator` (`created_by_user_id`),
  CONSTRAINT `fk_ai_engine_examples_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ai_engine_examples` WRITE;
/*!40000 ALTER TABLE `ai_engine_examples` DISABLE KEYS */;
/*!40000 ALTER TABLE `ai_engine_examples` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `ai_engine_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_engine_settings` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `provider` varchar(40) NOT NULL DEFAULT 'openai',
  `base_url` varchar(500) NOT NULL DEFAULT 'https://api.openai.com/v1',
  `model` varchar(120) NOT NULL DEFAULT 'gpt-4o-mini',
  `temperature` decimal(3,2) NOT NULL DEFAULT 0.20,
  `max_tokens` int(11) NOT NULL DEFAULT 1200,
  `api_key_ciphertext` text DEFAULT NULL,
  `api_key_last4` varchar(4) DEFAULT NULL,
  `skill_gold_standard` mediumtext DEFAULT NULL,
  `skill_verra_vcs` mediumtext DEFAULT NULL,
  `global_instructions` mediumtext DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `fk_ai_engine_settings_user` (`updated_by_user_id`),
  CONSTRAINT `fk_ai_engine_settings_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_ai_engine_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ai_engine_settings` WRITE;
/*!40000 ALTER TABLE `ai_engine_settings` DISABLE KEYS */;
INSERT INTO `ai_engine_settings` VALUES (1,'openai','https://api.openai.com/v1','gpt-4o-mini',0.20,1200,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-11-02 22:03:40.146','2026-11-02 22:03:40.146');
/*!40000 ALTER TABLE `ai_engine_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `ai_engine_skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_engine_skills` (
  `id` char(36) NOT NULL,
  `template_id` char(36) DEFAULT NULL,
  `template_version_id` char(36) DEFAULT NULL,
  `registry` varchar(40) DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `skill_text` mediumtext DEFAULT NULL,
  `source_filename` varchar(255) DEFAULT NULL,
  `source_mime_type` varchar(120) DEFAULT NULL,
  `source_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `status` enum('pending','ready') NOT NULL DEFAULT 'pending',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` char(36) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_engine_skills_version` (`template_version_id`),
  KEY `idx_ai_engine_skills_template` (`template_id`),
  KEY `idx_ai_engine_skills_registry` (`registry`,`status`),
  KEY `fk_ai_engine_skills_creator` (`created_by_user_id`),
  CONSTRAINT `fk_ai_engine_skills_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_engine_skills_template` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_engine_skills_version` FOREIGN KEY (`template_version_id`) REFERENCES `template_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ai_engine_skills` WRITE;
/*!40000 ALTER TABLE `ai_engine_skills` DISABLE KEYS */;
/*!40000 ALTER TABLE `ai_engine_skills` ENABLE KEYS */;
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
INSERT INTO `audit_logs` VALUES ('1dfc4618-5fd4-4f3d-ab27-441b72403ca0','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','cf024974-40b7-4b5b-9758-4c3e0dea5e02','review_task.created','review_task','1f0f077f-0a9b-43ae-b591-15692e4aa66a','{\"work_type\":\"draft_review\",\"priority\":\"low\"}','unknown','','2026-11-02 19:03:43.107'),('22e050b8-f1e7-4054-9542-1c17eac2c93a','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','cf024974-40b7-4b5b-9758-4c3e0dea5e02','review_task.created','review_task','8e333e3e-42b5-4224-8c97-08fadf68ed32','{\"work_type\":\"organisation_kyb\",\"priority\":\"high\"}','unknown','','2026-11-02 19:03:43.088'),('315a90ca-cd22-4a49-83fb-efaaf32b01ef','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','87b5c5a0-7919-475c-99e0-1252d06ac1ca','auth.signup','user','87b5c5a0-7919-475c-99e0-1252d06ac1ca','{\"locale\":\"en\"}','unknown','','2026-11-02 19:03:42.587'),('4d429b0e-cb41-42a3-9c9e-d5dde5ec9bdd','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','87b5c5a0-7919-475c-99e0-1252d06ac1ca','project.created','project','5dd24762-6f3d-4a79-be3a-f4587aba4117','{\"name\":\"Kisumu Clean Cookstoves\"}','unknown','','2026-11-02 19:03:42.603'),('4e2c26b1-a07c-4f34-ae13-65cd8d975b98','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','87b5c5a0-7919-475c-99e0-1252d06ac1ca','project.created','project','84ba9fd6-2416-450e-9684-a01cb45b8a39','{\"name\":\"Rift Valley Reforestation\"}','unknown','','2026-11-02 19:03:42.608'),('52dff1c3-da2d-4144-a426-d4d34a602954','cc2856ea-dff6-42c8-a232-d8e876b6858f','cf024974-40b7-4b5b-9758-4c3e0dea5e02','auth.signup','user','cf024974-40b7-4b5b-9758-4c3e0dea5e02','{\"locale\":\"en\"}','unknown','','2026-11-02 19:03:43.071'),('90fa035d-ff95-4b4c-9003-1f7ebb26f7b8','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','cf024974-40b7-4b5b-9758-4c3e0dea5e02','review_task.created','review_task','53d6cc7e-3597-449d-af33-01feaf72f1e3','{\"work_type\":\"fact_sheet_review\",\"priority\":\"normal\"}','unknown','','2026-11-02 19:03:43.103'),('b981c2d5-1ac0-4113-87f6-fb0cac7e315d','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','cf024974-40b7-4b5b-9758-4c3e0dea5e02','review_task.created','review_task','e694971b-49fb-4f66-a32e-d9683c5c50c6','{\"work_type\":\"project_validation\",\"priority\":\"urgent\"}','unknown','','2026-11-02 19:03:43.092'),('eca952a7-7f62-42fb-9089-a08fc0cdcf16','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','cf024974-40b7-4b5b-9758-4c3e0dea5e02','review_task.created','review_task','9a3c0ad7-6fdb-42f8-9ad8-cbcf7aa8e20a','{\"work_type\":\"template_review\",\"priority\":\"normal\"}','unknown','','2026-11-02 19:03:43.098');
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
INSERT INTO `drafts` VALUES ('1ffb179c-d7d6-43ea-9bf6-63310f011c1c','5dd24762-6f3d-4a79-be3a-f4587aba4117','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','11541e47-df4c-48d2-956d-1dda36bdf4cb','b9d0af54-9db4-494a-b888-6b4b4f8eec84','en','in_review',1,'draft','87b5c5a0-7919-475c-99e0-1252d06ac1ca','2026-11-02 19:03:43.121','2026-11-02 19:03:43.121');
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
INSERT INTO `fact_sheet_versions` VALUES ('b9d0af54-9db4-494a-b888-6b4b4f8eec84','5dd24762-6f3d-4a79-be3a-f4587aba4117',1,'approved','{\"facts\":[],\"approved_for\":\"demo seed\"}','87b5c5a0-7919-475c-99e0-1252d06ac1ca','87b5c5a0-7919-475c-99e0-1252d06ac1ca','2026-11-02 19:03:43.000','2026-11-02 19:03:43.119');
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
INSERT INTO `methodologies` VALUES ('399c1241-dc21-43ee-84a8-fd6b5a9f9021','verra_vcs','VM0007','REDD+ Methodology Framework (REDD-MF)','v1.6','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 19:03:41.993','2026-11-02 19:03:41.993'),('44b2b8a2-cb7a-4741-b51a-59ad0415b284','verra_vcs','VM0015','Methodology for Avoided Unplanned Deforestation','v1.1','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 19:03:41.996','2026-11-02 19:03:41.996'),('5b2cb828-9ba1-4ba1-b159-633d7c0904f3','verra_vcs','VM0009','Methodology for Avoided Ecosystem Conversion','v3.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 19:03:41.999','2026-11-02 19:03:41.999'),('6b4f56b6-866d-465c-a8be-c09169c6fb7f','gold_standard','AMS-II.G','Energy efficiency measures in thermal applications of non-renewable biomass','v13.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 19:03:41.986','2026-11-02 19:03:41.986'),('d1424e6d-3b63-466f-bad4-48646958e2b1','gold_standard','AR-AMS0007','Afforestation and reforestation on lands other than wetlands','v03.1','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 19:03:41.990','2026-11-02 19:03:41.990'),('dfeaeb03-5d53-4f11-8543-04db24db7333','gold_standard','AMS-I.C','Thermal energy for the user with or without electricity','v21.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 19:03:41.984','2026-11-02 19:03:41.984');
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
DROP TABLE IF EXISTS `platform_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  `updated_by_user_id` char(36) DEFAULT NULL,
  PRIMARY KEY (`setting_key`),
  KEY `fk_platform_settings_user` (`updated_by_user_id`),
  CONSTRAINT `fk_platform_settings_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `platform_settings` WRITE;
/*!40000 ALTER TABLE `platform_settings` DISABLE KEYS */;
INSERT INTO `platform_settings` VALUES ('maintenance_mode','0','2026-11-02 22:03:40.013',NULL),('signups_enabled','1','2026-11-02 22:03:40.013',NULL);
/*!40000 ALTER TABLE `platform_settings` ENABLE KEYS */;
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
INSERT INTO `projects` VALUES ('5dd24762-6f3d-4a79-be3a-f4587aba4117','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','Kisumu Clean Cookstoves','Improved cookstoves for households around Kisumu, Kenya.',NULL,NULL,NULL,NULL,NULL,'review','en','confidential',NULL,NULL,'87b5c5a0-7919-475c-99e0-1252d06ac1ca',NULL,'2026-11-02 19:03:42.601','2026-11-02 19:03:43.112',NULL),('84ba9fd6-2416-450e-9684-a01cb45b8a39','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','Rift Valley Reforestation','Afforestation and reforestation on degraded land in the Rift Valley.',NULL,NULL,NULL,NULL,NULL,'facts_pending_approval','en','confidential',NULL,NULL,'87b5c5a0-7919-475c-99e0-1252d06ac1ca',NULL,'2026-11-02 19:03:42.605','2026-11-02 19:03:43.108',NULL);
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
INSERT INTO `review_tasks` VALUES ('1f0f077f-0a9b-43ae-b591-15692e4aa66a','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','draft_review','Review draft sections — Kisumu Clean Cookstoves','Check citations and the two \"needs input\" markers in section B.','in_progress','low','cf024974-40b7-4b5b-9758-4c3e0dea5e02','2026-11-09 19:03:43.000',NULL,NULL,'cf024974-40b7-4b5b-9758-4c3e0dea5e02',NULL,NULL,'2026-11-02 19:03:43.105','2026-11-02 19:03:43.105'),('53d6cc7e-3597-449d-af33-01feaf72f1e3','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','fact_sheet_review','Approve fact sheet — Rift Valley Reforestation','Confirm ex-ante estimates and the baseline quotations before drafting.','unassigned','normal',NULL,'2026-11-05 19:03:43.000',NULL,NULL,'cf024974-40b7-4b5b-9758-4c3e0dea5e02',NULL,NULL,'2026-11-02 19:03:43.101','2026-11-02 19:03:43.101'),('8e333e3e-42b5-4224-8c97-08fadf68ed32','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','organisation_kyb','Review organisation documents — Amina Demo’s workspace','Check registration certificate and ownership structure before verifying the workspace.','unassigned','high',NULL,'2026-10-31 19:03:43.000',NULL,NULL,'cf024974-40b7-4b5b-9758-4c3e0dea5e02',NULL,NULL,'2026-11-02 19:03:43.085','2026-11-02 19:03:43.085'),('9a3c0ad7-6fdb-42f8-9ad8-cbcf7aa8e20a','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','template_review','QA the Gold Standard v1.4 template structure','Headings, numbering and table columns must match the published guide.','in_progress','normal','cf024974-40b7-4b5b-9758-4c3e0dea5e02','2026-11-07 19:03:43.000',NULL,NULL,'cf024974-40b7-4b5b-9758-4c3e0dea5e02',NULL,NULL,'2026-11-02 19:03:43.096','2026-11-02 19:03:43.096'),('e694971b-49fb-4f66-a32e-d9683c5c50c6','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','project_validation','Validate project boundary — Kisumu Clean Cookstoves','Boundary coordinates and included households need a second pair of eyes.','in_progress','urgent','cf024974-40b7-4b5b-9758-4c3e0dea5e02','2026-11-01 19:03:43.000',NULL,NULL,'cf024974-40b7-4b5b-9758-4c3e0dea5e02',NULL,NULL,'2026-11-02 19:03:43.090','2026-11-02 19:03:43.090');
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
INSERT INTO `schema_migrations` VALUES ('0001_init.sql','2026-11-02 22:03:39.760'),('0002_template_library.sql','2026-11-02 22:03:39.846'),('0003_organization_onboarding.sql','2026-11-02 22:03:39.901'),('0004_review_tasks.sql','2026-11-02 22:03:39.937'),('0005_otp_verification.sql','2026-11-02 22:03:39.969'),('0006_otp_preference.sql','2026-11-02 22:03:39.981'),('0007_platform_settings.sql','2026-11-02 22:03:40.017'),('0008_owner_scope_portability.sql','2026-11-02 22:03:40.116'),('0009_ai_engine.sql','2026-11-02 22:03:40.149'),('0010_ai_engine_examples.sql','2026-11-02 22:03:40.179'),('0011_ai_engine_skills.sql','2026-11-02 22:03:40.205');
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
  `owner_scope` tinyint(4) GENERATED ALWAYS AS (if(`role` = 'owner',1,NULL)) STORED,
  `invited_by_user_id` char(36) DEFAULT NULL,
  `joined_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_members_team_user` (`team_id`,`user_id`),
  UNIQUE KEY `uq_team_members_single_owner` (`team_id`,`owner_scope`),
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
INSERT INTO `team_members` VALUES ('6ec3de01-e85e-42ec-966c-b8ae9ad2b909','c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','87b5c5a0-7919-475c-99e0-1252d06ac1ca','owner','active',1,NULL,'2026-11-02 19:03:42.580','2026-11-02 19:03:42.580','2026-11-02 19:03:42.580'),('77e30306-640b-48ff-9e6c-93871600defd','cc2856ea-dff6-42c8-a232-d8e876b6858f','cf024974-40b7-4b5b-9758-4c3e0dea5e02','owner','active',1,NULL,'2026-11-02 19:03:43.066','2026-11-02 19:03:43.066','2026-11-02 19:03:43.066');
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
INSERT INTO `teams` VALUES ('c0ec08ea-4952-4ff4-bee5-0b25fd22a9db','Amina Demo’s workspace','amina-demo-bdbms','87b5c5a0-7919-475c-99e0-1252d06ac1ca',1,'free','auto',NULL,'2026-11-02 19:03:42.578','2026-11-02 19:03:42.578',NULL),('cc2856ea-dff6-42c8-a232-d8e876b6858f','Ian Admin’s workspace','ian-admin-dokdq','cf024974-40b7-4b5b-9758-4c3e0dea5e02',1,'free','auto',NULL,'2026-11-02 19:03:43.065','2026-11-02 19:03:43.065',NULL);
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
INSERT INTO `template_sections` VALUES ('0c6dba8f-e5c7-44c2-8244-3fca01f456a2','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,15,'C','Duration and crediting period',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.039'),('12f92b03-074e-494f-90b1-6f89d4c31a38','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,8,'A.6','Scale and finance',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.025'),('1c948f0c-1002-4d6f-9bb0-c3504810f45d','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,19,'APP2','Contacts',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.049'),('20613b75-768a-43a6-b2f4-939c5c07003f','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,13,'B.4','Ex ante estimates',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.036'),('21708d37-e57a-4950-a21e-8cb939daa7ea','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,12,'B.3','Additionality',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.034'),('4120ff47-42c3-4975-9f00-fc5f22967439','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,17,'E','Local stakeholder consultation',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.044'),('445cee3f-81db-4f3e-9cc1-b86eb4c4fde9','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,9,'B','Methodology and SDG contributions',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.028'),('56b09d4b-c745-4d01-a522-6cd41cc00ae6','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,20,'APP3','Land-use and forests information',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.050'),('6bee06d1-7fed-4ce6-bce4-718803923272','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,10,'B.1','Baseline',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.031'),('71fc4fb1-006e-4f6c-9926-431003ca6036','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,6,'A.4','Location',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.021'),('7706ce80-028f-40c6-82e5-099dc746aad2','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,7,'A.5','Technologies and/or practices',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.023'),('7d69d40b-fdfd-4fe5-8ffa-5dc316550429','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,3,'A.1','Purpose and general description',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.016'),('8c9baab4-2e58-44ed-94b0-1e5583c0b204','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,16,'D','Safeguarding principles and gender summary',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.040'),('928d6ba7-db0b-43cd-823e-9ddcafc5d9ea','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,5,'A.3','Project ownership',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.019'),('a3eab4b4-2a30-4de3-82ed-8bae0254a69b','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,21,'APP4','Design changes',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.052'),('d0e518c6-9ae7-45c9-b1de-487c7edb91ea','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,11,'B.2','Boundary',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.032'),('dc7a12dd-8fbe-4a6a-98a2-dd9257d9ec3b','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,2,'A','Description of project',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.014'),('e227c07c-abfa-4fd6-b56a-f8d43b658646','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,14,'B.5','Monitoring plan',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.038'),('eccc6739-e8fc-4b0b-9f74-e16ddeafddde','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,1,'KPI','Key Project Information',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.010'),('f1213733-f7d5-40b8-8f75-a999a878baf3','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,4,'A.2','Eligibility',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.018'),('f6bb2a61-aa22-46e8-b138-753ed921ec3e','11541e47-df4c-48d2-956d-1dda36bdf4cb',NULL,18,'APP1','Safeguarding assessment',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 19:03:42.047');
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
INSERT INTO `template_versions` VALUES ('11541e47-df4c-48d2-956d-1dda36bdf4cb','6a898300-f41d-42d2-aaf3-dfb8035cc009','v1.4','en','2023-06-21','draft','https://globalgoals.goldstandard.org/standards/TGuide-PreReview_V1.4-Project-Design-Document.pdf','Source link recorded. Permission to store and use must be confirmed by the owner before publishing (open question).',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 19:03:42.006','2026-11-02 19:03:42.006');
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
INSERT INTO `templates` VALUES ('6a898300-f41d-42d2-aaf3-dfb8035cc009','Gold Standard Template Guide v1.4','pdd_template','gold_standard',NULL,'en','Key Project Information and Project Design Document structure.',NULL,'2026-11-02 19:03:42.003','2026-11-02 19:03:42.003');
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
INSERT INTO `users` VALUES ('87b5c5a0-7919-475c-99e0-1252d06ac1ca','demo@pddgen.local','demo@pddgen.local','$argon2id$v=19$m=65536,t=4,p=1$MWw5bnpGWlhLQ2toa05BSA$ZZz9IJmTR9HSll7irfXMNeIauJhPWSycXm0+awlYgr8','Amina Demo',NULL,'en','2026-11-02 19:03:42.590',0,0,'c0ec08ea-4952-4ff4-bee5-0b25fd22a9db',NULL,NULL,NULL,1,'2026-11-02 19:03:42.565','2026-11-02 19:03:42.590'),('cf024974-40b7-4b5b-9758-4c3e0dea5e02','admin@pddgen.local','admin@pddgen.local','$argon2id$v=19$m=65536,t=4,p=1$Nlpic3pkN2xKVWpMTzNsYg$KXGDTiX1NvvwsOoQ+6sj0LwbzafaRZGfCrPeZS6gCNU','Ian Admin',NULL,'en','2026-11-02 19:03:43.073',0,1,'cc2856ea-dff6-42c8-a232-d8e876b6858f',NULL,NULL,NULL,1,'2026-11-02 19:03:43.053','2026-11-02 19:03:43.073');
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

