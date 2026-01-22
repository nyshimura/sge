-- SGE SYSTEM - DATABASE LIMPA PARA GITHUB
-- Credenciais Padrão: admin@admin / admin

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Desabilita verificação de chaves estrangeiras para evitar erros na criação
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------

--
-- Estrutura da tabela `users`
--
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firstName` varchar(100) NOT NULL,
  `lastName` varchar(100) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','teacher','admin','superadmin') NOT NULL DEFAULT 'student',
  `phone` varchar(20) DEFAULT NULL,
  `cpf` varchar(20) DEFAULT NULL,
  `profilePicture` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `active` tinyint(1) DEFAULT 1,
  `guardianName` varchar(150) DEFAULT NULL,
  `guardianEmail` varchar(150) DEFAULT NULL,
  `guardianPhone` varchar(20) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Inserindo Usuário ADMIN Padrão
-- Senha 'admin' (Hash Bcrypt gerado)
--
INSERT INTO `users` (`id`, `firstName`, `lastName`, `email`, `password_hash`, `role`, `created_at`, `active`) VALUES
(1, 'Super', 'Admin', 'admin@admin', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', 'superadmin', NOW(), 1);

-- --------------------------------------------------------

--
-- Estrutura da tabela `system_settings`
--
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `site_url` varchar(255) DEFAULT 'http://localhost/sge',
  `language` varchar(10) DEFAULT 'pt-BR',
  `timeZone` varchar(50) DEFAULT 'America/Sao_Paulo',
  `currencySymbol` varchar(10) DEFAULT 'R$',
  `smtpServer` varchar(100) DEFAULT NULL,
  `smtpPort` int(11) DEFAULT 587,
  `smtpUser` varchar(100) DEFAULT NULL,
  `smtpPass` varchar(255) DEFAULT NULL,
  `email_approval_subject` varchar(255) DEFAULT 'Matrícula Aprovada',
  `email_approval_body` text DEFAULT NULL,
  `email_reset_subject` varchar(255) DEFAULT 'Recuperação de Senha',
  `email_reset_body` text DEFAULT NULL,
  `email_reminder_subject` varchar(255) DEFAULT 'Lembrete de Mensalidade',
  `email_reminder_body` text DEFAULT NULL,
  `reminderDaysBefore` int(11) DEFAULT 3,
  `certificate_template_text` text DEFAULT NULL,
  `certificate_background_image` longtext DEFAULT NULL,
  `imageTermsText` text DEFAULT NULL,
  `enrollmentContractText` text DEFAULT NULL,
  `geminiApiKey` varchar(255) DEFAULT NULL,
  `geminiApiEndpoint` varchar(255) DEFAULT NULL,
  `dbHost` varchar(100) DEFAULT 'localhost',
  `dbUser` varchar(100) DEFAULT 'root',
  `dbPass` varchar(100) DEFAULT NULL,
  `dbName` varchar(100) DEFAULT 'sge_db',
  `dbPort` varchar(10) DEFAULT '3306',
  `mp_public_key` varchar(255) DEFAULT NULL,
  `mp_access_token` varchar(255) DEFAULT NULL,
  `mp_client_id` varchar(255) DEFAULT NULL,
  `mp_client_secret` varchar(255) DEFAULT NULL,
  `mp_active` tinyint(1) DEFAULT 0,
  `enableTerminationFine` tinyint(1) DEFAULT 0,
  `terminationFineMonths` int(11) DEFAULT 0,
  `defaultDueDay` int(11) DEFAULT 10,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dados iniciais de configuração
--
INSERT INTO `system_settings` (`id`, `site_url`, `language`) VALUES
(1, 'http://localhost', 'pt-BR');

-- --------------------------------------------------------

--
-- Estrutura da tabela `school_profile`
--
DROP TABLE IF EXISTS `school_profile`;
CREATE TABLE `school_profile` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT 'Nome da Escola',
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `logo` longtext DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `school_profile` (`id`, `name`) VALUES (1, 'Minha Escola Modelo');

-- --------------------------------------------------------

--
-- Estrutura da tabela `courses`
--
DROP TABLE IF EXISTS `courses`;
CREATE TABLE `courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `monthlyFee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `schedule` varchar(255) DEFAULT NULL,
  `schedule_json` text DEFAULT NULL,
  `teacherId` int(11) DEFAULT NULL,
  `course_image` longtext DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `closed_date` datetime DEFAULT NULL,
  `closed_by_admin_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `teacherId` (`teacherId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `enrollments`
--
DROP TABLE IF EXISTS `enrollments`;
CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `status` enum('Pendente','Aprovada','Rejeitada','Cancelada','Concluido') DEFAULT 'Pendente',
  `enrollmentDate` datetime DEFAULT current_timestamp(),
  `scholarshipPercentage` decimal(5,2) DEFAULT 0.00,
  `contractAcceptedAt` datetime DEFAULT NULL,
  `termsAcceptedAt` datetime DEFAULT NULL,
  `customMonthlyFee` decimal(10,2) DEFAULT NULL,
  `customDueDay` int(2) DEFAULT NULL,
  `billingStartDate` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `studentId` (`studentId`),
  KEY `courseId` (`courseId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `payments`
--
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `dueDate` date NOT NULL,
  `status` enum('Pendente','Pago','Atrasado','Cancelada') DEFAULT 'Pendente',
  `paymentDate` datetime DEFAULT NULL,
  `method` varchar(50) DEFAULT NULL,
  `referenceDate` date DEFAULT NULL,
  `reminderSent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `studentId` (`studentId`),
  KEY `courseId` (`courseId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `attendance`
--
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `courseId` int(11) NOT NULL,
  `studentId` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Presente','Falta') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `courseId` (`courseId`),
  KEY `studentId` (`studentId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `certificates`
--
DROP TABLE IF EXISTS `certificates`;
CREATE TABLE `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `verification_hash` varchar(64) NOT NULL,
  `completion_date` date NOT NULL,
  `generated_at` datetime DEFAULT current_timestamp(),
  `custom_workload` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `course_teachers`
--
DROP TABLE IF EXISTS `course_teachers`;
CREATE TABLE `course_teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `courseId` int(11) NOT NULL,
  `teacherId` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `courseId` (`courseId`),
  KEY `teacherId` (`teacherId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `school_recess`
--
DROP TABLE IF EXISTS `school_recess`;
CREATE TABLE `school_recess` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Constraints (Foreign Keys)
--

ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`teacherId`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `courses_ibfk_2` FOREIGN KEY (`closed_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `certificates`
  ADD CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

ALTER TABLE `course_teachers`
  ADD CONSTRAINT `course_teachers_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_teachers_ibfk_2` FOREIGN KEY (`teacherId`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Reabilita verificação de chaves estrangeiras
SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
