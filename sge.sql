-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: sge
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `courseId` int(11) NOT NULL,
  `studentId` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Presente','Falta') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`courseId`,`studentId`,`date`),
  KEY `studentId` (`studentId`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `certificates`
--

DROP TABLE IF EXISTS `certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `completion_date` date NOT NULL COMMENT 'Data de Conclusão informada no certificado',
  `custom_workload` varchar(50) DEFAULT NULL,
  `verification_hash` varchar(64) NOT NULL COMMENT 'Hash SHA-256 único para verificação',
  `generated_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'Data/Hora da geração do PDF',
  PRIMARY KEY (`id`),
  UNIQUE KEY `verification_hash` (`verification_hash`),
  KEY `student_id` (`student_id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `course_teachers`
--

DROP TABLE IF EXISTS `course_teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `courseId` int(11) NOT NULL,
  `teacherId` int(11) NOT NULL,
  `commissionRate` decimal(5,2) DEFAULT 0.00,
  `createdAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `courseId` (`courseId`),
  KEY `teacherId` (`teacherId`),
  CONSTRAINT `course_teachers_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_teachers_ibfk_2` FOREIGN KEY (`teacherId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `totalSlots` int(11) DEFAULT NULL COMMENT 'NULL para vagas ilimitadas',
  `status` enum('Aberto','Encerrado') NOT NULL DEFAULT 'Aberto',
  `carga_horaria` varchar(50) DEFAULT NULL COMMENT 'Carga horária do curso (ex: 40 horas)',
  `monthlyFee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paymentType` enum('recorrente','parcelado') NOT NULL DEFAULT 'recorrente',
  `installments` int(3) DEFAULT NULL,
  `closed_by_admin_id` int(11) DEFAULT NULL,
  `closed_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `schedule_json` text DEFAULT NULL COMMENT 'Armazena horários múltiplos em JSON',
  `thumbnail` longtext DEFAULT NULL,
  `id_card_template_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `closed_by_admin_id` (`closed_by_admin_id`),
  KEY `fk_course_id_card` (`id_card_template_id`),
  CONSTRAINT `courses_ibfk_2` FOREIGN KEY (`closed_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_course_id_card` FOREIGN KEY (`id_card_template_id`) REFERENCES `id_card_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `enrollments`
--

DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollments` (
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `status` enum('Pendente','Aprovada','Cancelada') NOT NULL DEFAULT 'Pendente',
  `billingStartDate` date DEFAULT NULL COMMENT 'Data de início para geração de cobranças',
  `enrollmentDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `scholarshipPercentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `customMonthlyFee` decimal(10,2) DEFAULT NULL,
  `termsAcceptedAt` datetime DEFAULT NULL,
  `contractAcceptedAt` datetime DEFAULT NULL,
  `customDueDay` int(2) DEFAULT NULL COMMENT 'Dia de vencimento personalizado (1-31). Se NULL, usa o padrão.',
  PRIMARY KEY (`studentId`,`courseId`),
  KEY `courseId` (`courseId`),
  CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `event_term_responses`
--

DROP TABLE IF EXISTS `event_term_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_term_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `term_id` int(11) NOT NULL,
  `studentId` int(11) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `term_id` (`term_id`),
  KEY `studentId` (`studentId`),
  CONSTRAINT `event_resp_ibfk_1` FOREIGN KEY (`term_id`) REFERENCES `event_terms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_resp_ibfk_2` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `event_terms`
--

DROP TABLE IF EXISTS `event_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `courseId` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `status` enum('active','concluded') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `courseId` (`courseId`),
  CONSTRAINT `event_terms_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `id_card_templates`
--

DROP TABLE IF EXISTS `id_card_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `id_card_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `background_image` mediumtext DEFAULT NULL,
  `template_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `orientation` char(1) NOT NULL DEFAULT 'L',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `referenceDate` date NOT NULL COMMENT 'Primeiro dia do mês de referência (ex: 2023-10-01)',
  `dueDate` date NOT NULL,
  `status` enum('Pago','Pendente','Atrasado','Cancelado') NOT NULL DEFAULT 'Pendente',
  `paymentDate` date DEFAULT NULL,
  `method` varchar(50) DEFAULT NULL,
  `mp_payment_id` varchar(50) DEFAULT NULL,
  `transaction_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reminderSent` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 se o lembrete de vencimento já foi enviado',
  PRIMARY KEY (`id`),
  KEY `studentId` (`studentId`),
  KEY `courseId` (`courseId`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1263 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `school_profile`
--

DROP TABLE IF EXISTS `school_profile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_profile` (
  `id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(255) NOT NULL,
  `cnpj` varchar(20) NOT NULL,
  `state` varchar(2) DEFAULT NULL,
  `schoolCity` varchar(100) DEFAULT NULL,
  `address` text NOT NULL,
  `phone` varchar(20) NOT NULL,
  `pixKeyType` enum('CPF','CNPJ','E-mail','Telefone','Aleatória') NOT NULL,
  `pixKey` varchar(255) NOT NULL,
  `profilePicture` longtext DEFAULT NULL,
  `signatureImage` longtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `school_recess`
--

DROP TABLE IF EXISTS `school_recess`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_recess` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `smtpServer` varchar(255) DEFAULT NULL,
  `smtpPort` varchar(10) DEFAULT NULL,
  `smtpUser` varchar(255) DEFAULT NULL,
  `smtpPass` varchar(255) DEFAULT NULL,
  `site_url` varchar(255) DEFAULT 'https://seusite.com/caminho_para_sge/',
  `email_approval_subject` varchar(255) DEFAULT 'Sua Matrícula foi Aprovada!',
  `email_approval_body` text DEFAULT NULL,
  `email_reset_subject` varchar(255) DEFAULT 'Redefinição de Senha Solicitada',
  `email_reset_body` text DEFAULT NULL,
  `certificate_template_text` text DEFAULT NULL COMMENT 'Modelo de texto do certificado com placeholders',
  `certificate_background_image` mediumtext DEFAULT NULL COMMENT 'Imagem de fundo do certificado (base64)',
  `language` varchar(10) NOT NULL DEFAULT 'pt-BR',
  `timeZone` varchar(100) NOT NULL DEFAULT 'America/Sao_Paulo',
  `currencySymbol` varchar(5) NOT NULL DEFAULT 'R$',
  `enableTerminationFine` tinyint(1) NOT NULL DEFAULT 0,
  `terminationFineMonths` int(11) NOT NULL DEFAULT 1,
  `defaultDueDay` int(2) NOT NULL DEFAULT 10,
  `geminiApiKey` varchar(255) DEFAULT NULL,
  `geminiApiEndpoint` varchar(255) DEFAULT NULL,
  `imageTermsText` text DEFAULT NULL,
  `enrollmentContractText` text DEFAULT NULL,
  `term_text_adult` text DEFAULT NULL,
  `term_text_minor` text DEFAULT NULL,
  `dbHost` varchar(255) DEFAULT NULL,
  `dbUser` varchar(255) DEFAULT NULL,
  `dbPass` varchar(255) DEFAULT NULL,
  `dbName` varchar(255) DEFAULT NULL,
  `dbPort` varchar(10) DEFAULT NULL,
  `mp_active` varchar(10) DEFAULT 'false',
  `mp_public_key` varchar(255) DEFAULT '',
  `mp_access_token` varchar(255) DEFAULT '',
  `mp_client_id` varchar(255) DEFAULT NULL,
  `mp_client_secret` varchar(255) DEFAULT NULL,
  `email_reminder_subject` varchar(255) DEFAULT 'Lembrete: Sua mensalidade vence em breve',
  `email_reminder_body` text DEFAULT NULL,
  `reminderDaysBefore` int(11) NOT NULL DEFAULT 3 COMMENT 'Dias antes do vencimento para enviar o lembrete',
  `inter_active` tinyint(1) DEFAULT 0,
  `inter_client_id` varchar(255) DEFAULT NULL,
  `inter_client_secret` varchar(255) DEFAULT NULL,
  `inter_cert_file` varchar(255) DEFAULT NULL,
  `inter_key_file` varchar(255) DEFAULT NULL,
  `inter_sandbox` tinyint(1) DEFAULT 0,
  `inter_webhook_crt` varchar(255) DEFAULT NULL,
  `teacher_id_card_template_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firstName` varchar(100) NOT NULL,
  `lastName` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL COMMENT 'Telefone pessoal do aluno/usuario',
  `password_hash` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'unassigned',
  `age` int(3) DEFAULT NULL,
  `profilePicture` longtext DEFAULT NULL COMMENT 'Armazena a imagem em base64',
  `address` text DEFAULT NULL,
  `rg` varchar(20) DEFAULT NULL,
  `cpf` varchar(20) DEFAULT NULL,
  `birthDate` date DEFAULT NULL COMMENT 'Data de Nascimento do usuário',
  `guardianName` varchar(255) DEFAULT NULL,
  `guardianRG` varchar(20) DEFAULT NULL,
  `guardianCPF` varchar(20) DEFAULT NULL,
  `guardianEmail` varchar(255) DEFAULT NULL,
  `guardianPhone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role_title` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-08 23:02:20
INSERT INTO school_profile (id, name, cnpj, address, phone, pixKeyType, pixKey) VALUES (1, 'SGE Padrao', '0', 'N/A', '0', 'CPF', '0');
INSERT INTO system_settings (id) VALUES (1);
INSERT INTO users (firstName, email, password_hash, role) VALUES ('Admin', 'admin@admin', '$GAO2VV5Htfwmmts4djrwkeztk1yKrcIf6OXa7KXZKN3z0RXHW2olu', 'admin');
