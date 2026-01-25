-- SGE SYSTEM - DATABASE LIMPA (COM CONTRATOS PADRÃO)
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
  `term_text_adult` text DEFAULT NULL,
  `term_text_minor` text DEFAULT NULL,
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
-- Dados iniciais de configuração (COM TEXTOS PADRÃO)
--
INSERT INTO `system_settings` (`id`, `site_url`, `language`, `enrollmentContractText`, `term_text_adult`, `term_text_minor`) VALUES
(1, 'http://localhost', 'pt-BR', 
'As partes acima qualificadas têm, entre si, justo e contratado o presente CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS, que se regerá pelas cláusulas e condições a seguir descritas:\n\nCLÁUSULA 1 – DO OBJETO\n1.1. O objeto do presente contrato é a prestação de serviços educacionais de ensino livre pela CONTRATADA em favor do ALUNO indicado no preâmbulo, referente ao curso de {{curso_nome}}.\n1.2. A orientação técnica e pedagógica sobre a prestação dos serviços de ensino é de inteira responsabilidade da CONTRATADA.\n\nCLÁUSULA 2 – DO PAGAMENTO E DA BOLSA\n2.1. Pelos serviços educacionais ora contratados, foi acordado o valor de {{curso_mensalidade}}, {{clausula_financeira}}.\n2.2. O pagamento das mensalidades deverá ser efetuado até a data de vencimento estipulada. O não recebimento do boleto ou aviso de cobrança não exime o CONTRATANTE do pagamento.\n2.3. Em caso de atraso no pagamento, incidirá multa de 2% (dois por cento) e juros de mora de 1% (um por cento) ao mês.\n\nCLÁUSULA 3 – DA FREQUÊNCIA E CERTIFICAÇÃO\n3.1. O ALUNO deverá possuir frequência mínima de 75% (setenta e cinco por cento) e aproveitamento suficiente nas avaliações para fazer jus ao certificado de conclusão.\n3.2. A CONTRATADA reserva-se o direito de emitir o certificado apenas após a conclusão de toda a carga horária e quitação de eventuais débitos pendentes, salvo em casos de bolsa integral.\n\nCLÁUSULA 4 – DA RESCISÃO\n4.1. O presente contrato poderá ser rescindido pelo CONTRATANTE a qualquer tempo, mediante solicitação formal por escrito na secretaria da escola.\n4.2. O cancelamento não exime o CONTRATANTE do pagamento das mensalidades vencidas até a data da solicitação.\n4.3. A CONTRATADA poderá rescindir este contrato em caso de indisciplina grave por parte do ALUNO ou inadimplência superior a 60 (sessenta) dias.\n\nCLÁUSULA 5 – DO USO DE IMAGEM\n5.1. As regras referentes ao uso de imagem do aluno estão dispostas em termo próprio anexo a este contrato, devendo ser assinado especificamente para este fim.\n\nCLÁUSULA 6 – DISPOSIÇÕES GERAIS\n6.1. O ALUNO (ou seu responsável) declara estar ciente e de acordo com as normas e o regimento interno da escola.\n6.2. A CONTRATADA não se responsabiliza por objetos de valor deixados nas dependências da escola.\n\nE, por estarem assim justos e contratados, firmam o presente instrumento para que produza seus efeitos legais.\n\n{{escola_nome}}\nCNPJ: {{escola_cnpj}}',

'Eu, {{aluno_nome}}, inscrito(a) no CPF sob o n.º {{aluno_cpf}}, residente e domiciliado(a) em {{aluno_endereco}}, AUTORIZO o uso de minha imagem em todo e qualquer material entre fotos, documentos e outros meios de comunicação, para ser utilizada em campanhas promocionais e institucionais da {{escola_nome}}. A presente autorização é concedida a título gratuito, abrangendo o uso da imagem acima mencionada em todo território nacional e no exterior, das seguintes formas: (I) Out-door; (II) Bus-door; folhetos em geral (encartes, mala direta, catálogo, etc.); (III) Folder de apresentação; (IV) Anúncios em revistas e jornais em geral; (V) Home Page; (VI) Cartazes; (VII) Back-light; (VIII) Mídia eletrônica (painéis, videotapes, televisão, cinema, programa para rádio, entre outros).',

'Eu, {{responsavel_nome}}, inscrito(a) no CPF sob o n.º {{responsavel_cpf}}, na qualidade de responsável legal pelo(a) menor {{aluno_nome}}, AUTORIZO o uso da imagem do(a) referido(a) menor em todo e qualquer material entre fotos, documentos e outros meios de comunicação, para ser utilizada em campanhas promocionais e institucionais da {{escola_nome}}. A presente autorização é concedida a título gratuito, abrangendo o uso da imagem acima mencionada em todo território nacional e no exterior, das seguintes formas: (I) Out-door; (II) Bus-door; folhetos em geral (encartes, mala direta, catálogo, etc.); (III) Folder de apresentação; (IV) Anúncios em revistas e jornais em geral; (V) Home Page; (VI) Cartazes; (VII) Back-light; (VIII) Mídia eletrônica (painéis, videotapes, televisão, cinema, programa para rádio, entre outros).');

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
  `cnpj` varchar(30) DEFAULT NULL,
  `schoolCity` varchar(100) DEFAULT NULL,
  `profilePicture` longtext DEFAULT NULL,
  `signatureImage` longtext DEFAULT NULL,
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
  `thumbnail` longtext DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `status` varchar(20) DEFAULT 'Aberto',
  `totalSlots` int(11) DEFAULT NULL,
  `carga_horaria` varchar(50) DEFAULT NULL,
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
  `status` enum('Pendente','Aprovada','Rejeitada','Cancelada','Concluido', 'Ativo') DEFAULT 'Pendente',
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
  `commissionRate` decimal(5,2) DEFAULT 0.00,
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

-- --------------------------------------------------------

--
-- Estrutura da tabela `notifications`
--
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `type` varchar(20) DEFAULT 'warning',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
