
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `actor_id` int DEFAULT NULL,
  `actor_name` varchar(255) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `entity_type` varchar(64) DEFAULT NULL,
  `entity_id` varchar(64) DEFAULT NULL,
  `details` json DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_actor` (`actor_id`),
  KEY `idx_audit_time` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consultation_reason`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultation_reason` (
  `id` int NOT NULL AUTO_INCREMENT,
  `consultation_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consultations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `BED` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `MRN` int DEFAULT NULL,
  `PNAME` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `age` int DEFAULT NULL,
  `consultation_date` date DEFAULT NULL,
  `consultation_from` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `current_location` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `entered_by_id` int DEFAULT NULL,
  `indication` json DEFAULT NULL,
  `consultant_id` int DEFAULT NULL,
  `signoff_date` date DEFAULT NULL,
  `other_ind` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consultation_to_service` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  KEY `idx_consultations_consultant` (`consultant_id`),
  KEY `idx_consultations_mrn` (`MRN`),
  KEY `idx_consultations_signoff` (`signoff_date`),
  KEY `idx_consultations_condate` (`consultation_date`),
  KEY `fk_consultations_entered_by` (`entered_by_id`),
  CONSTRAINT `fk_consultations_consultant` FOREIGN KEY (`consultant_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_consultations_entered_by` FOREIGN KEY (`entered_by_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=972 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=255 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `icd10`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `icd10` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autoid` int NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`autoid`)
) ENGINE=InnoDB AUTO_INCREMENT=72752 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `member_id` int NOT NULL AUTO_INCREMENT,
  `member_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mfa_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mfa_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `mfa_enrolled_at` datetime DEFAULT NULL,
  `member_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` int DEFAULT NULL,
  `active` int DEFAULT NULL,
  `pass_exp_date` date DEFAULT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `on_service` int DEFAULT NULL,
  `specialty_id` int DEFAULT NULL,
  `assign_access` int DEFAULT NULL,
  `add_new_patient` int DEFAULT NULL,
  `manage_patient` int DEFAULT NULL,
  `modify_patient` int DEFAULT NULL,
  PRIMARY KEY (`member_id`)
) ENGINE=InnoDB AUTO_INCREMENT=331 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `other_specialities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `other_specialities` (
  `specilaity` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `id` int NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_token` (`token_hash`),
  KEY `idx_pr_member` (`member_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `patient_diagnosis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_diagnosis` (
  `id` int NOT NULL AUTO_INCREMENT,
  `picupatient_id` int NOT NULL,
  `seq` smallint NOT NULL,
  `icd10_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icd10_autoid` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pd_patient` (`picupatient_id`),
  KEY `idx_pd_code` (`icd10_code`),
  KEY `idx_pd_autoid` (`icd10_autoid`)
) ENGINE=InnoDB AUTO_INCREMENT=16384 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mrn` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pname` mediumtext COLLATE utf8mb4_unicode_ci,
  `gender` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `age` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admission_count` int NOT NULL DEFAULT '0',
  `first_admission` date DEFAULT NULL,
  `last_admission` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_patients_mrn` (`mrn`)
) ENGINE=InnoDB AUTO_INCREMENT=8192 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `picupatients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `picupatients` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `MRN` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `PNAME` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ADMDATE` date DEFAULT NULL,
  `DISDATE` date DEFAULT NULL,
  `ADMFROM` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `DISTO` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `MORTALITY` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `admissiondiagnosis` json DEFAULT NULL,
  `BED` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `nationality` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `gender` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `consultant_id` int DEFAULT NULL,
  `age` int DEFAULT NULL,
  `newassign` int DEFAULT NULL,
  `assigned_on` date DEFAULT NULL,
  `admitted_by` int DEFAULT NULL,
  `trans_discharge` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `trans_discharge_by` int DEFAULT NULL,
  `current_location` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `med_DISDATE` date DEFAULT NULL,
  `delay` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `longterm` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`ID`),
  KEY `idx_picupatients_admdate` (`ADMDATE`),
  KEY `idx_picupatients_disdate` (`DISDATE`),
  KEY `idx_picupatients_mrn` (`MRN`(20)),
  KEY `idx_picupatients_consultant` (`consultant_id`),
  KEY `idx_picupatients_location` (`current_location`(10)),
  KEY `fk_picupatients_admitted_by` (`admitted_by`),
  KEY `fk_picupatients_trans_discharge_by` (`trans_discharge_by`),
  CONSTRAINT `fk_picupatients_admitted_by` FOREIGN KEY (`admitted_by`) REFERENCES `members` (`member_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_picupatients_consultant` FOREIGN KEY (`consultant_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_picupatients_trans_discharge_by` FOREIGN KEY (`trans_discharge_by`) REFERENCES `members` (`member_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `picupatients_temp`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `picupatients_temp` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `MRN` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `PNAME` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ADMDATE` date DEFAULT NULL,
  `DISDATE` date DEFAULT NULL,
  `ADMFROM` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `DISTO` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `MORTALITY` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `admissiondiagnosis` json DEFAULT NULL,
  `BED` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `nationality` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `gender` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `consultant_id` int DEFAULT NULL,
  `age` int DEFAULT NULL,
  `newassign` int DEFAULT NULL,
  `assigned_on` date DEFAULT NULL,
  `admitted_by` int DEFAULT NULL,
  `trans_discharge` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `trans_discharge_by` int DEFAULT NULL,
  `current_location` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `med_DISDATE` date DEFAULT NULL,
  `delay` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `longterm` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=422 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `position`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `position` (
  `id` int NOT NULL AUTO_INCREMENT,
  `position` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `min_hospitalist` int DEFAULT NULL,
  `max_hospitalist` int DEFAULT NULL,
  `id` int NOT NULL,
  `min_subs` int DEFAULT NULL,
  `max_subs` int DEFAULT NULL,
  `short_los` int DEFAULT NULL,
  `long_los` int DEFAULT NULL,
  `mfa_enforcement` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speciality`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speciality` (
  `id` int NOT NULL AUTO_INCREMENT,
  `specilaity` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tb_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dx_id` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tbl_token_auth`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tbl_token_auth` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `selector_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_expired` int NOT NULL DEFAULT '0',
  `expiry_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=799 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

