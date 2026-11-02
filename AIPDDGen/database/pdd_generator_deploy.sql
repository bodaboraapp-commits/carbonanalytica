
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
INSERT INTO `ai_engine_settings` VALUES (1,'openai','https://api.openai.com/v1','gpt-4o-mini',0.20,1200,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-11-02 20:49:29.759','2026-11-02 20:49:29.759');
/*!40000 ALTER TABLE `ai_engine_settings` ENABLE KEYS */;
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
INSERT INTO `audit_logs` VALUES ('31e819d8-c2b1-4cd2-8083-a8235763dc4a','6f8cf40c-531a-4221-92f8-3349a68da5cf','2ca1bcb4-ca29-4c56-a40a-3228778f9506','review_task.created','review_task','4f95d2c0-6256-43df-be19-948a9d3940f0','{\"work_type\":\"template_review\",\"priority\":\"normal\"}','unknown','','2026-11-02 17:49:32.072'),('81ce52d7-fc07-428d-ba4e-4db557609c0e','6f8cf40c-531a-4221-92f8-3349a68da5cf','4d7bc58f-4469-43a5-863c-3568841d933f','project.created','project','855aaa4f-94e9-4e32-8b08-566c42d56877','{\"name\":\"Rift Valley Reforestation\"}','unknown','','2026-11-02 17:49:31.686'),('bc3b2d9e-c342-4c07-be06-87206bd1a649','6f8cf40c-531a-4221-92f8-3349a68da5cf','2ca1bcb4-ca29-4c56-a40a-3228778f9506','review_task.created','review_task','f7ae0d28-962f-42f4-aea0-5c57bcb98a25','{\"work_type\":\"organisation_kyb\",\"priority\":\"high\"}','unknown','','2026-11-02 17:49:32.062'),('c52e8555-7be6-4182-9e05-fdb356d98e57','6f8cf40c-531a-4221-92f8-3349a68da5cf','2ca1bcb4-ca29-4c56-a40a-3228778f9506','review_task.created','review_task','903ed43f-3b9b-4cff-a445-dab226c2831b','{\"work_type\":\"project_validation\",\"priority\":\"urgent\"}','unknown','','2026-11-02 17:49:32.067'),('cb87cd82-0fce-4151-9e68-a4a8c2ff8d8f','6f8cf40c-531a-4221-92f8-3349a68da5cf','4d7bc58f-4469-43a5-863c-3568841d933f','project.created','project','6723ab6d-5d29-4c99-b928-4f8c1db2bb15','{\"name\":\"Kisumu Clean Cookstoves\"}','unknown','','2026-11-02 17:49:31.679'),('d55b57f8-2ce3-4534-86bf-c0e90248e161','6f8cf40c-531a-4221-92f8-3349a68da5cf','4d7bc58f-4469-43a5-863c-3568841d933f','auth.signup','user','4d7bc58f-4469-43a5-863c-3568841d933f','{\"locale\":\"en\"}','unknown','','2026-11-02 17:49:31.666'),('dd705104-9d47-4219-b934-59293f76c13d','0ecd7951-a840-413c-ba93-b2eac970e895','2ca1bcb4-ca29-4c56-a40a-3228778f9506','auth.signup','user','2ca1bcb4-ca29-4c56-a40a-3228778f9506','{\"locale\":\"en\"}','unknown','','2026-11-02 17:49:32.048'),('e92cc75c-739c-4738-9e7d-eea8d3c0dc9a','6f8cf40c-531a-4221-92f8-3349a68da5cf','2ca1bcb4-ca29-4c56-a40a-3228778f9506','review_task.created','review_task','1385deb5-2ad5-4ce7-b5b6-043cf13a996e','{\"work_type\":\"fact_sheet_review\",\"priority\":\"normal\"}','unknown','','2026-11-02 17:49:32.078'),('fc40d298-ac0d-4595-936a-ff4edc6c792f','6f8cf40c-531a-4221-92f8-3349a68da5cf','2ca1bcb4-ca29-4c56-a40a-3228778f9506','review_task.created','review_task','909839d1-b9b6-4fe2-bf0e-c50ccb233cba','{\"work_type\":\"draft_review\",\"priority\":\"low\"}','unknown','','2026-11-02 17:49:32.082');
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
INSERT INTO `drafts` VALUES ('e5645356-0537-44ba-a28e-09416b810f89','6723ab6d-5d29-4c99-b928-4f8c1db2bb15','6f8cf40c-531a-4221-92f8-3349a68da5cf','54800606-5ca8-4edc-9b7f-04adf35ee80c','b56a2555-5a49-4505-953e-59f4f2ef8e2e','en','in_review',1,'draft','4d7bc58f-4469-43a5-863c-3568841d933f','2026-11-02 17:49:32.100','2026-11-02 17:49:32.100');
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
INSERT INTO `fact_sheet_versions` VALUES ('b56a2555-5a49-4505-953e-59f4f2ef8e2e','6723ab6d-5d29-4c99-b928-4f8c1db2bb15',1,'approved','{\"facts\":[],\"approved_for\":\"demo seed\"}','4d7bc58f-4469-43a5-863c-3568841d933f','4d7bc58f-4469-43a5-863c-3568841d933f','2026-11-02 17:49:32.000','2026-11-02 17:49:32.098');
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
INSERT INTO `methodologies` VALUES ('0b84ad7d-419a-4b28-95fe-23093af572de','verra_vcs','VM0009','Methodology for Avoided Ecosystem Conversion','v3.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 17:49:31.238','2026-11-02 17:49:31.238'),('3d17e38d-86c2-44ef-af8c-56898be43224','gold_standard','AMS-II.G','Energy efficiency measures in thermal applications of non-renewable biomass','v13.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 17:49:31.228','2026-11-02 17:49:31.228'),('4b6dcbef-102b-49d2-b613-c713a3fd8198','gold_standard','AMS-I.C','Thermal energy for the user with or without electricity','v21.0','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 17:49:31.225','2026-11-02 17:49:31.225'),('6d478d14-4fa6-45fa-a6ba-5893952853d1','gold_standard','AR-AMS0007','Afforestation and reforestation on lands other than wetlands','v03.1','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 17:49:31.231','2026-11-02 17:49:31.231'),('86b97925-e5c9-48a2-b3f5-00a14641fccb','verra_vcs','VM0015','Methodology for Avoided Unplanned Deforestation','v1.1','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 17:49:31.235','2026-11-02 17:49:31.235'),('d22df180-b3c9-4dac-b48a-f7a313b2d373','verra_vcs','VM0007','REDD+ Methodology Framework (REDD-MF)','v1.6','en',1,1,'Launch shortlist pending product-owner confirmation (docs/open-questions.md).','2026-11-02 17:49:31.233','2026-11-02 17:49:31.233');
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
INSERT INTO `platform_settings` VALUES ('maintenance_mode','0','2026-11-02 20:49:29.654',NULL),('signups_enabled','1','2026-11-02 20:49:29.654',NULL);
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
INSERT INTO `projects` VALUES ('6723ab6d-5d29-4c99-b928-4f8c1db2bb15','6f8cf40c-531a-4221-92f8-3349a68da5cf','Kisumu Clean Cookstoves','Improved cookstoves for households around Kisumu, Kenya.',NULL,NULL,NULL,NULL,NULL,'review','en','confidential',NULL,NULL,'4d7bc58f-4469-43a5-863c-3568841d933f',NULL,'2026-11-02 17:49:31.677','2026-11-02 17:49:32.084',NULL),('855aaa4f-94e9-4e32-8b08-566c42d56877','6f8cf40c-531a-4221-92f8-3349a68da5cf','Rift Valley Reforestation','Afforestation and reforestation on degraded land in the Rift Valley.',NULL,NULL,NULL,NULL,NULL,'facts_pending_approval','en','confidential',NULL,NULL,'4d7bc58f-4469-43a5-863c-3568841d933f',NULL,'2026-11-02 17:49:31.683','2026-11-02 17:49:32.083',NULL);
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
INSERT INTO `review_tasks` VALUES ('1385deb5-2ad5-4ce7-b5b6-043cf13a996e','6f8cf40c-531a-4221-92f8-3349a68da5cf','fact_sheet_review','Approve fact sheet — Rift Valley Reforestation','Confirm ex-ante estimates and the baseline quotations before drafting.','unassigned','normal',NULL,'2026-11-05 17:49:32.000',NULL,NULL,'2ca1bcb4-ca29-4c56-a40a-3228778f9506',NULL,NULL,'2026-11-02 17:49:32.076','2026-11-02 17:49:32.076'),('4f95d2c0-6256-43df-be19-948a9d3940f0','6f8cf40c-531a-4221-92f8-3349a68da5cf','template_review','QA the Gold Standard v1.4 template structure','Headings, numbering and table columns must match the published guide.','in_progress','normal','2ca1bcb4-ca29-4c56-a40a-3228778f9506','2026-11-07 17:49:32.000',NULL,NULL,'2ca1bcb4-ca29-4c56-a40a-3228778f9506',NULL,NULL,'2026-11-02 17:49:32.070','2026-11-02 17:49:32.070'),('903ed43f-3b9b-4cff-a445-dab226c2831b','6f8cf40c-531a-4221-92f8-3349a68da5cf','project_validation','Validate project boundary — Kisumu Clean Cookstoves','Boundary coordinates and included households need a second pair of eyes.','in_progress','urgent','2ca1bcb4-ca29-4c56-a40a-3228778f9506','2026-11-01 17:49:32.000',NULL,NULL,'2ca1bcb4-ca29-4c56-a40a-3228778f9506',NULL,NULL,'2026-11-02 17:49:32.064','2026-11-02 17:49:32.064'),('909839d1-b9b6-4fe2-bf0e-c50ccb233cba','6f8cf40c-531a-4221-92f8-3349a68da5cf','draft_review','Review draft sections — Kisumu Clean Cookstoves','Check citations and the two \"needs input\" markers in section B.','in_progress','low','2ca1bcb4-ca29-4c56-a40a-3228778f9506','2026-11-09 17:49:32.000',NULL,NULL,'2ca1bcb4-ca29-4c56-a40a-3228778f9506',NULL,NULL,'2026-11-02 17:49:32.080','2026-11-02 17:49:32.080'),('f7ae0d28-962f-42f4-aea0-5c57bcb98a25','6f8cf40c-531a-4221-92f8-3349a68da5cf','organisation_kyb','Review organisation documents — Amina Demo’s workspace','Check registration certificate and ownership structure before verifying the workspace.','unassigned','high',NULL,'2026-10-31 17:49:32.000',NULL,NULL,'2ca1bcb4-ca29-4c56-a40a-3228778f9506',NULL,NULL,'2026-11-02 17:49:32.059','2026-11-02 17:49:32.059');
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
INSERT INTO `schema_migrations` VALUES ('0001_init.sql','2026-11-02 20:49:29.444'),('0002_template_library.sql','2026-11-02 20:49:29.512'),('0003_organization_onboarding.sql','2026-11-02 20:49:29.565'),('0004_review_tasks.sql','2026-11-02 20:49:29.589'),('0005_otp_verification.sql','2026-11-02 20:49:29.620'),('0006_otp_preference.sql','2026-11-02 20:49:29.628'),('0007_platform_settings.sql','2026-11-02 20:49:29.656'),('0008_owner_scope_portability.sql','2026-11-02 20:49:29.719'),('0009_ai_engine.sql','2026-11-02 20:49:29.761'),('0010_ai_engine_examples.sql','2026-11-02 20:49:29.797');
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
INSERT INTO `team_members` VALUES ('5d576bb1-0b54-4f99-9ec8-dd4b4760c7b2','0ecd7951-a840-413c-ba93-b2eac970e895','2ca1bcb4-ca29-4c56-a40a-3228778f9506','owner','active',1,NULL,'2026-11-02 17:49:32.047','2026-11-02 17:49:32.047','2026-11-02 17:49:32.047'),('e7cf08e1-a360-43fa-be61-efa9ae9b59f3','6f8cf40c-531a-4221-92f8-3349a68da5cf','4d7bc58f-4469-43a5-863c-3568841d933f','owner','active',1,NULL,'2026-11-02 17:49:31.661','2026-11-02 17:49:31.661','2026-11-02 17:49:31.661');
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
INSERT INTO `teams` VALUES ('0ecd7951-a840-413c-ba93-b2eac970e895','Ian Admin’s workspace','ian-admin-six57','2ca1bcb4-ca29-4c56-a40a-3228778f9506',1,'free','auto',NULL,'2026-11-02 17:49:32.044','2026-11-02 17:49:32.044',NULL),('6f8cf40c-531a-4221-92f8-3349a68da5cf','Amina Demo’s workspace','amina-demo-kri9b','4d7bc58f-4469-43a5-863c-3568841d933f',1,'free','auto',NULL,'2026-11-02 17:49:31.659','2026-11-02 17:49:31.659',NULL);
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
INSERT INTO `template_sections` VALUES ('03dba074-bc60-4d85-abef-ce6352c7e3d4','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,8,'A.6','Scale and finance',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.261'),('18b3a840-a5c1-4ef7-b7fe-c72f790cf329','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,6,'A.4','Location',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.258'),('2c7299b1-feae-481d-b3b7-defe9d008aac','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,14,'B.5','Monitoring plan',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.269'),('31c1e3a9-06a9-4fac-82e6-b31198799436','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,10,'B.1','Baseline',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.264'),('420b7ee8-61cd-44bf-a842-18c18c27859c','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,11,'B.2','Boundary',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.265'),('43875022-f487-4701-bc9d-be94431d36a0','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,2,'A','Description of project',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.250'),('51370a62-0cc2-403f-a2bd-7d26565a65a9','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,13,'B.4','Ex ante estimates',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.268'),('5d8582c8-b462-4a0f-a31f-2bc37b3ee702','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,5,'A.3','Project ownership',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.257'),('60cd21b1-c708-461a-bd30-2c217c69a0b5','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,12,'B.3','Additionality',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.266'),('7c277ec7-6a20-41c8-a3d8-e77aadc849f8','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,4,'A.2','Eligibility',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.255'),('7e5d4053-6d47-49fc-b011-0da60274c04e','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,17,'E','Local stakeholder consultation',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.274'),('7fde485b-28c1-4dc6-8001-97abcb640908','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,16,'D','Safeguarding principles and gender summary',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.273'),('86143a1e-8884-469c-9cb3-4aafb80b028a','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,18,'APP1','Safeguarding assessment',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.276'),('9654a390-583f-46bc-ab86-52faa5e23f4a','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,20,'APP3','Land-use and forests information',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.278'),('b8c1e2db-f5dc-444e-8f85-fb9c9a218cd9','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,3,'A.1','Purpose and general description',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.254'),('bd2b60cd-8e37-484e-8bdf-d81460e83a19','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,15,'C','Duration and crediting period',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.271'),('c100ed16-409e-432b-ac4e-1770ec8655a1','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,1,'KPI','Key Project Information',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.248'),('d401a63d-12f3-4c9f-a103-4605914211c7','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,9,'B','Methodology and SDG contributions',1,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.262'),('efaa9d73-6b7a-4a59-96df-0e4fef87a002','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,19,'APP2','Contacts',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.277'),('f6e46a9d-2492-40a9-842c-cb3b3cd78cba','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,7,'A.5','Technologies and/or practices',2,1,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.260'),('fa20aca5-1421-40fb-9bf3-63e12d2820ef','54800606-5ca8-4edc-9b7f-04adf35ee80c',NULL,21,'APP4','Design changes',1,0,1,NULL,'{\"date_format\":\"DD\\/MM\\/YYYY\",\"number_format\":\"thousands_comma_decimal_point\",\"unique_ids_for_figures_and_tables\":true}','2026-11-02 17:49:31.279');
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
INSERT INTO `template_versions` VALUES ('54800606-5ca8-4edc-9b7f-04adf35ee80c','4433cf06-8d00-48dc-b399-53a9b02b5caa','v1.4','en','2023-06-21','draft','https://globalgoals.goldstandard.org/standards/TGuide-PreReview_V1.4-Project-Design-Document.pdf','Source link recorded. Permission to store and use must be confirmed by the owner before publishing (open question).',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 17:49:31.245','2026-11-02 17:49:31.245');
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
INSERT INTO `templates` VALUES ('4433cf06-8d00-48dc-b399-53a9b02b5caa','Gold Standard Template Guide v1.4','pdd_template','gold_standard',NULL,'en','Key Project Information and Project Design Document structure.',NULL,'2026-11-02 17:49:31.242','2026-11-02 17:49:31.242');
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
INSERT INTO `users` VALUES ('2ca1bcb4-ca29-4c56-a40a-3228778f9506','admin@pddgen.local','admin@pddgen.local','$argon2id$v=19$m=65536,t=4,p=1$Smk3Ty54bXoxY3pzbnBMNw$ZC21j3iZeedT66ROp6r48RZy9EI8VgSNyQwx2xY0s2Q','Ian Admin',NULL,'en','2026-11-02 17:49:32.049',0,1,'0ecd7951-a840-413c-ba93-b2eac970e895',NULL,NULL,NULL,1,'2026-11-02 17:49:32.042','2026-11-02 17:49:32.049'),('4d7bc58f-4469-43a5-863c-3568841d933f','demo@pddgen.local','demo@pddgen.local','$argon2id$v=19$m=65536,t=4,p=1$TEhDcnpwLnBjRllTOC91aA$0EyAQ5g3XiK1dW94DE5FoLmicOx6xEYY6Jz2vZ71B+U','Amina Demo',NULL,'en','2026-11-02 17:49:31.669',0,0,'6f8cf40c-531a-4221-92f8-3349a68da5cf',NULL,NULL,NULL,1,'2026-11-02 17:49:31.652','2026-11-02 17:49:31.669');
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

