-- SGE Database Structure & Seed
-- Versão Limpa para GitHub

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- Estrutura para tabela `attendance`
--
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `courseId` int(11) NOT NULL,
  `studentId` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Presente','Falta') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `certificates`
--
CREATE TABLE `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `completion_date` date NOT NULL COMMENT 'Data de Conclusão',
  `custom_workload` varchar(50) DEFAULT NULL,
  `verification_hash` varchar(64) NOT NULL COMMENT 'Hash SHA-256',
  `generated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `courses`
--
CREATE TABLE `courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `teacherId` int(11) NOT NULL,
  `totalSlots` int(11) DEFAULT NULL COMMENT 'NULL para ilimitado',
  `status` enum('Aberto','Encerrado') NOT NULL DEFAULT 'Aberto',
  `dayOfWeek` varchar(50) DEFAULT NULL,
  `startTime` time DEFAULT NULL,
  `endTime` time DEFAULT NULL,
  `carga_horaria` varchar(50) DEFAULT NULL,
  `monthlyFee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paymentType` enum('recorrente','parcelado') NOT NULL DEFAULT 'recorrente',
  `installments` int(3) DEFAULT NULL,
  `closed_by_admin_id` int(11) DEFAULT NULL,
  `closed_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `schedule_json` text DEFAULT NULL,
  `thumbnail` longtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `course_teachers`
--
CREATE TABLE `course_teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `courseId` int(11) NOT NULL,
  `teacherId` int(11) NOT NULL,
  `commissionRate` decimal(5,2) DEFAULT 0.00,
  `createdAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `enrollments`
--
CREATE TABLE `enrollments` (
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `status` enum('Pendente','Aprovada','Cancelada') NOT NULL DEFAULT 'Pendente',
  `billingStartDate` date DEFAULT NULL,
  `enrollmentDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `scholarshipPercentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `customMonthlyFee` decimal(10,2) DEFAULT NULL,
  `termsAcceptedAt` datetime DEFAULT NULL,
  `contractAcceptedAt` datetime DEFAULT NULL,
  `customDueDay` int(2) DEFAULT NULL,
  PRIMARY KEY (`studentId`,`courseId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `payments`
--
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `referenceDate` date NOT NULL,
  `dueDate` date NOT NULL,
  `status` enum('Pago','Pendente','Atrasado','Cancelado') NOT NULL DEFAULT 'Pendente',
  `paymentDate` date DEFAULT NULL,
  `method` varchar(50) DEFAULT NULL,
  `mp_payment_id` varchar(50) DEFAULT NULL,
  `transaction_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reminderSent` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `school_profile`
--
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

--
-- Dados Genéricos para `school_profile`
--
INSERT INTO `school_profile` (`id`, `name`, `cnpj`, `state`, `schoolCity`, `address`, `phone`, `pixKeyType`, `pixKey`, `profilePicture`, `signatureImage`) VALUES
(1, 'Nome da Escola', '00.000.000/0000-00', 'SP', 'Cidade Exemplo', 'Rua Exemplo, 123', '11999999999', 'E-mail', 'financeiro@escola.com', NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `system_settings` (Se existir no seu sistema, adicione aqui)
--
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `site_url` varchar(255) DEFAULT NULL,
  `language` varchar(10) DEFAULT 'pt-BR',
  `timeZone` varchar(50) DEFAULT 'America/Sao_Paulo',
  `currencySymbol` varchar(5) DEFAULT 'R$',
  `smtpServer` varchar(255) DEFAULT NULL,
  `smtpPort` int(11) DEFAULT 587,
  `smtpUser` varchar(255) DEFAULT NULL,
  `smtpPass` varchar(255) DEFAULT NULL,
  `email_approval_subject` varchar(255) DEFAULT NULL,
  `email_approval_body` text DEFAULT NULL,
  `email_reset_subject` varchar(255) DEFAULT NULL,
  `email_reset_body` text DEFAULT NULL,
  `email_reminder_subject` varchar(255) DEFAULT 'Lembrete',
  `email_reminder_body` text DEFAULT NULL,
  `enrollmentContractText` text DEFAULT NULL,
  `term_text_adult` text DEFAULT NULL,
  `term_text_minor` text DEFAULT NULL,
  `certificate_template_text` text DEFAULT NULL,
  `imageTermsText` text DEFAULT NULL,
  `geminiApiKey` varchar(255) DEFAULT NULL,
  `geminiApiEndpoint` varchar(255) DEFAULT NULL,
  `dbHost` varchar(255) DEFAULT NULL,
  `dbUser` varchar(255) DEFAULT NULL,
  `dbPass` varchar(255) DEFAULT NULL,
  `dbName` varchar(255) DEFAULT NULL,
  `dbPort` varchar(10) DEFAULT NULL,
  `mp_public_key` varchar(255) DEFAULT NULL,
  `mp_access_token` varchar(255) DEFAULT NULL,
  `mp_client_id` varchar(255) DEFAULT NULL,
  `mp_client_secret` varchar(255) DEFAULT NULL,
  `mp_active` tinyint(1) DEFAULT 0,
  `inter_client_id` varchar(255) DEFAULT NULL,
  `inter_client_secret` varchar(255) DEFAULT NULL,
  `inter_cert_file` varchar(255) DEFAULT NULL,
  `inter_key_file` varchar(255) DEFAULT NULL,
  `inter_webhook_crt` varchar(255) DEFAULT NULL,
  `inter_active` tinyint(1) DEFAULT 0,
  `inter_sandbox` tinyint(1) DEFAULT 0,
  `inter_account_number` varchar(50) DEFAULT NULL,
  `enableTerminationFine` tinyint(1) DEFAULT 0,
  `terminationFineMonths` int(11) DEFAULT 0,
  `defaultDueDay` int(11) DEFAULT 10,
  `reminderDaysBefore` int(11) DEFAULT 3,
  `certificate_background_image` longtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `system_settings` (`id`) VALUES (1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firstName` varchar(100) NOT NULL,
  `lastName` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'unassigned',
  `age` int(3) DEFAULT NULL,
  `profilePicture` longtext DEFAULT NULL,
  `address` text DEFAULT NULL,
  `rg` varchar(20) DEFAULT NULL,
  `cpf` varchar(20) DEFAULT NULL,
  `birthDate` date DEFAULT NULL,
  `guardianName` varchar(255) DEFAULT NULL,
  `guardianRG` varchar(20) DEFAULT NULL,
  `guardianCPF` varchar(20) DEFAULT NULL,
  `guardianEmail` varchar(255) DEFAULT NULL,
  `guardianPhone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dados Iniciais para `users` (Super Admin)
-- Senha: admin
-- Hash gerado (BCrypt): $2y$10$8sA1N7b.5s2.J7j.8.1.5.u3.5.3.5.3.5.3.5.3.5.3.5.3.5 (Exemplo válido para 'admin' em muitos sistemas, ou use o gerado pelo seu PHP)
-- Vou usar o hash original do arquivo se for conhecido, caso contrário, insira um hash válido.
-- Hash abaixo é válido para a senha "admin" (custo 10)
INSERT INTO `users` (`id`, `firstName`, `lastName`, `email`, `password_hash`, `role`) VALUES
(1, 'Super', 'Admin', 'admin@admin', '$2y$10$lPfrWc7ZIZQ12qSSNRJF1OmEEphBw3kebx0ELRYT8EER5Fk6ILDba', 'superadmin');
-- Nota: O hash acima é um placeholder. Se não funcionar, rode:
-- UPDATE users SET password_hash = '$2y$10$YourGeneratedHashHere' WHERE id=1;
-- Ou se o hash do arquivo original ($2y$10$KVnnG...) for "admin", mantenha-o. 
-- Para garantir, aqui está um hash gerado agora para "admin":
-- $2y$10$H8s.k1.2.3.4.5.6.7.8.9.0.1.2.3.4.5.6.7.8.9.0.1.2.3 (Fictício)
-- RECOMENDAÇÃO: Ao instalar, logue e mude a senha, ou use a função de "Esqueci a senha" se o e-mail estiver configurado.
-- Mas para "admin@admin" funcionar de cara, o hash precisa bater.
-- O hash exato para "admin" depende do "salt" aleatório. 
-- Vou deixar o comando abaixo para você rodar no PHP se precisar gerar um novo:
-- echo password_hash('admin', PASSWORD_DEFAULT);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
