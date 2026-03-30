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
  KEY `idx_applicant_exam_idnum` (`exam_year`,`id_num`),
  KEY `idx_applicant_exam_idcode` (`exam_year`,`idcode`),
  KEY `idx_applicant_exam_firstname` (`exam_year`,`firstname`),
  KEY `idx_applicant_exam_lastname` (`exam_year`,`lastname`),
  KEY `idx_applicant_exam_allname` (`exam_year`,`allname`),
  KEY `idx_applicant_exam_score` (`exam_year`,`score`)
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
