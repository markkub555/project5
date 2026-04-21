USE `register`;

CREATE TABLE IF NOT EXISTS `applicantname` (
  `id` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
  `exam_year` int(11) NOT NULL,
  `idcode` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
  `prefix` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
  `firstname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
  `lastname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
  `score` int(10) DEFAULT NULL,
  `submit_doc` char(1) DEFAULT 'W',
  `lab_check` char(1) DEFAULT 'W',
  `swim_test` char(1) DEFAULT 'W',
  `run_test` char(1) DEFAULT 'W',
  `station3_test` char(1) DEFAULT 'W',
  `hospital_check` char(1) DEFAULT 'W',
  `fingerprint_check` char(1) DEFAULT 'W',
  `background_check` char(1) DEFAULT 'W',
  `interview` char(1) DEFAULT 'W',
  `allname` varchar(255) DEFAULT 'W',
  `militarydoc` char(1) DEFAULT 'W',
  `id_num` bigint(20) unsigned GENERATED ALWAYS AS (cast(`id` as unsigned)) STORED,
  `score_num` decimal(10,2) GENERATED ALWAYS AS (cast(nullif(`score`, '') as decimal(10,2))) STORED,
  `allname_calc` varchar(1) GENERATED ALWAYS AS (
    case
      when `submit_doc` = 'F'
        or `lab_check` = 'F'
        or `swim_test` = 'F'
        or `run_test` = 'F'
        or `station3_test` = 'F'
        or `hospital_check` = 'F'
        or `fingerprint_check` = 'F'
        or `background_check` = 'F'
        or `interview` = 'F'
        or `militarydoc` = 'F' then 'F'
      when `submit_doc` = 'P'
        and `lab_check` = 'P'
        and `swim_test` = 'P'
        and `run_test` = 'P'
        and `station3_test` = 'P'
        and `hospital_check` = 'P'
        and `fingerprint_check` = 'P'
        and `background_check` = 'P'
        and `interview` = 'P'
        and `militarydoc` = 'P' then 'P'
      else 'W'
    end
  ) STORED,
  KEY `idx_applicant_exam_idnum` (`exam_year`,`id_num`),
  KEY `idx_applicant_exam_idcode` (`exam_year`,`idcode`),
  KEY `idx_applicant_exam_firstname` (`exam_year`,`firstname`),
  KEY `idx_applicant_exam_lastname` (`exam_year`,`lastname`),
  KEY `idx_applicant_exam_allname` (`exam_year`,`allname`),
  KEY `idx_applicant_exam_allname_calc` (`exam_year`,`allname_calc`),
  KEY `idx_applicant_exam_score` (`exam_year`,`score`),
  KEY `idx_applicant_exam_score_num` (`exam_year`,`score_num`,`id_num`),
  KEY `idx_applicant_exam_idcode_allname` (`exam_year`,`idcode`,`allname_calc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `applicant_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `exam_year` varchar(50) NOT NULL,
  `applicant_id` varchar(50) NOT NULL,
  `stage_key` varchar(50) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_applicant_notes` (`exam_year`,`applicant_id`,`stage_key`),
  KEY `idx_applicant_notes_lookup` (`exam_year`,`stage_key`,`applicant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `selected_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `exam_year` varchar(50) NOT NULL,
  `row_no` int(11) NOT NULL,
  `idcode` varchar(50) NOT NULL,
  `prefix` varchar(100) DEFAULT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `score` decimal(10,2) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selected_imports_exam_idcode` (`exam_year`,`idcode`),
  KEY `idx_selected_imports_exam_row` (`exam_year`,`row_no`),
  KEY `idx_selected_imports_exam_score` (`exam_year`,`score`,`row_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `idnumber` varchar(13) NOT NULL,
  `position` varchar(255) NOT NULL,
  `firstname` varchar(255) NOT NULL,
  `lastname` varchar(255) NOT NULL,
  `number` int(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) DEFAULT NULL,
  `expire` datetime DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `userstatus` char(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_token` (`token`),
  KEY `idx_users_userstatus` (`userstatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(100) DEFAULT NULL,
  `target_id` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_created_at` (`created_at`),
  KEY `idx_audit_logs_action` (`action`),
  KEY `idx_audit_logs_user_id` (`user_id`),
  KEY `idx_audit_logs_action_id` (`action`,`id`),
  KEY `idx_audit_logs_username_id` (`username`,`id`),
  KEY `idx_audit_logs_ip_created_at` (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users`
  (`idnumber`, `position`, `firstname`, `lastname`, `number`, `password`, `username`, `email`, `token`, `expire`, `code`, `userstatus`)
SELECT
  '0000000000000',
  'admin',
  'ผู้ดูแล',
  'ระบบ',
  0,
  '$2y$10$j4t5bWEiFUsLPx6pfmW2H.jCvSJhY8cNUlE8uEJg9sUAgMRuf2rxe',
  'useradmin',
  'useradmin@local.invalid',
  NULL,
  NULL,
  NULL,
  'P'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `users` WHERE `username` = 'useradmin'
);
