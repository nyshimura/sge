-- SQL Schema for SGE - Sistema de Gestão Escolar
-- Version: 1.3 (Updated with reminder system)
-- Description: Creates the database structure with only the essential initial data.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `banco_de_dados_sge`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `studentId` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Presente','Falta') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `certificates`
--

CREATE TABLE `certificates` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `completion_date` date NOT NULL COMMENT 'Data de Conclusão informada no certificado',
  `verification_hash` varchar(64) NOT NULL COMMENT 'Hash SHA-256 único para verificação',
  `generated_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'Data/Hora da geração do PDF'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `teacherId` int(11) NOT NULL,
  `totalSlots` int(11) DEFAULT NULL COMMENT 'NULL para vagas ilimitadas',
  `status` enum('Aberto','Encerrado') NOT NULL DEFAULT 'Aberto',
  `dayOfWeek` varchar(50) DEFAULT NULL,
  `startTime` time DEFAULT NULL,
  `endTime` time DEFAULT NULL,
  `carga_horaria` varchar(50) DEFAULT NULL COMMENT 'Carga horária do curso (ex: 40 horas)',
  `schedule_json` TEXT DEFAULT NULL COMMENT 'Armazena múltiplos dias e horários em formato JSON',
  `monthlyFee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paymentType` enum('recorrente','parcelado') NOT NULL DEFAULT 'recorrente',
  `installments` int(3) DEFAULT NULL,
  `closed_by_admin_id` int(11) DEFAULT NULL,
  `closed_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `enrollments`
--

CREATE TABLE `enrollments` (
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `status` enum('Pendente','Aprovada','Cancelada') NOT NULL DEFAULT 'Pendente',
  `billingStartDate` date DEFAULT NULL COMMENT 'Data de início para geração de cobranças',
  `enrollmentDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `scholarshipPercentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `customMonthlyFee` decimal(10,2) DEFAULT NULL,
  `termsAcceptedAt` datetime DEFAULT NULL,
  `contractAcceptedAt` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `studentId` int(11) NOT NULL,
  `courseId` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `referenceDate` date NOT NULL COMMENT 'Primeiro dia do mês de referência (ex: 2023-10-01)',
  `dueDate` date NOT NULL,
  `status` enum('Pago','Pendente','Atrasado','Cancelado') NOT NULL DEFAULT 'Pendente',
  `paymentDate` date DEFAULT NULL,
  `reminderSent` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 se o lembrete de vencimento já foi enviado',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
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
  `signatureImage` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `school_profile`
--

INSERT INTO `school_profile` (`id`, `name`, `cnpj`, `state`, `schoolCity`, `address`, `phone`, `pixKeyType`, `pixKey`, `profilePicture`, `signatureImage`) VALUES
(1, 'Nome da Sua Escola', '00.000.000/0000-00', 'SP', 'Sua Cidade', 'Seu Endereço Completo', '00000000000', 'CNPJ', 'SuaChavePIX', NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `smtpServer` varchar(255) DEFAULT NULL,
  `smtpPort` varchar(10) DEFAULT NULL,
  `smtpUser` varchar(255) DEFAULT NULL,
  `smtpPass` varchar(255) DEFAULT NULL,
  `site_url` varchar(255) DEFAULT 'http://localhost/seu_projeto/',
  `email_approval_subject` varchar(255) DEFAULT 'Sua Matrícula foi Aprovada!',
  `email_approval_body` text DEFAULT 'Olá {{responsavel_nome}},\n\nParabéns! A matrícula de {{aluno_nome}} no curso {{curso_nome}} foi aprovada.\n\nVeja seu contrato:\n{{link_contrato}}\n\nAtenciosamente,\nEquipe {{escola_nome}}',
  `email_reset_subject` varchar(255) DEFAULT 'Redefinição de Senha Solicitada',
  `email_reset_body` text DEFAULT 'Olá {{user_name}},\n\nRecebemos uma solicitação para redefinir sua senha no sistema {{escola_nome}}.\n\nClique no link abaixo para criar uma nova senha (link expira em 1 hora):\n{{reset_link}}\n\nSe não foi você, ignore este e-mail.\n\nAtenciosamente,\nEquipe {{escola_nome}}',
  `email_reminder_subject` varchar(255) DEFAULT 'Lembrete: Sua mensalidade vence em breve',
  `email_reminder_body` text DEFAULT 'Olá {{aluno_nome}},\n\nEste é um lembrete amigável de que a mensalidade do curso {{curso_nome}} vence no dia {{vencimento_data}}.\nValor: R$ {{valor}}\n\nCaso já tenha efetuado o pagamento, por favor, desconsidere este e-mail.\n\nAtenciosamente,\n{{escola_nome}}',
  `reminderDaysBefore` int(11) NOT NULL DEFAULT 3 COMMENT 'Dias antes do vencimento para enviar o lembrete',
  `certificate_template_text` text DEFAULT 'Certificamos que {{aluno_nome}}, portador(a) do RG n.º {{aluno_rg}} e inscrito(a) no CPF sob o n.º {{aluno_cpf}}, concluiu com êxito o curso de {{curso_nome}}, ministrado pelo professor(a) {{professor_nome}}, com carga horária total de {{curso_carga_horaria}} horas, concluído em {{data_conclusao}}.\nEste certificado é emitido por {{escola_nome}}, inscrita no CNPJ sob o n.º {{escola_cnpj}}, para atestar a participação e aprovação do(a) aluno(a).\n\n{{data_emissao_extenso}}.',
  `certificate_background_image` mediumtext DEFAULT NULL COMMENT 'Imagem de fundo do certificado (base64)',
  `language` varchar(10) NOT NULL DEFAULT 'pt-BR',
  `timeZone` varchar(100) NOT NULL DEFAULT 'America/Sao_Paulo',
  `currencySymbol` varchar(5) NOT NULL DEFAULT 'R$',
  `enableTerminationFine` tinyint(1) NOT NULL DEFAULT 0,
  `terminationFineMonths` int(11) NOT NULL DEFAULT 1,
  `defaultDueDay` int(2) NOT NULL DEFAULT 10,
  `geminiApiKey` varchar(255) DEFAULT NULL,
  `geminiApiEndpoint` varchar(255) DEFAULT NULL,
  `imageTermsText` text DEFAULT 'Eu, {{contratante_nome}}, portador da cédula de identidade nº {{contratante_rg}} e CPF nº {{contratante_cpf}}, responsável pelo aluno {{aluno_nome}}, autorizo o {{escola_nome}} a utilizar a imagem do(a) aluno(a) nos vídeos e fotos para as redes sociais, site e divulgação em jornais e revistas no que se remete ao centro cultural. Ciente que os mesmos poderão ser veiculados em redes sociais e mídias diversas pela equipe do projeto sem tempo determinado nem avisos prévios. Fica ainda autorizada, de livre e espontânea vontade, para os mesmos fins, a cessão de direitos de veiculação, não recebendo para tanto qualquer tipo de remuneração.\n\n{{data_atual_extenso}}',
  `enrollmentContractText` text DEFAULT 'CONTRATANTE: {{contratante_nome}}\nALUNO: {{aluno_nome}}\nEnd.: {{contratante_endereco}}\nRG: {{contratante_rg}} CPF: {{contratante_cpf}}\nCONTRATADA: {{escola_nome}}. CNPJ: {{escola_cnpj}}\n\n(Contratante, doravante RESPONSÁVEL PELO ALUNO / ALUNO. Contratada, doravante {{escola_nome}}.) As partes acima qualificadas têm, entre si, justo e acertado, o presente Contrato de Prestação de Serviços, que será regido pelas cláusulas seguintes e pelas condições de preço, forma e termo de pagamento abaixo descritos:\n\nCLÁUSULA 1 - OBJETO\n1.1- O objeto do presente contrato é a prestação dos serviços voltados a ministrar aulas de {{curso_nome}} ao ALUNO no estabelecimento da {{escola_nome}}.\n1.2- A duração deste contrato é indeterminada e o seu cancelamento dar-se-á mediante a comunicação prévia de 30 dias.\n\nCLÁUSULA 2 - DAS FÉRIAS E DO CALENDÁRIO\n2.1- Os cursos de {{curso_nome}} oferecidos pelo {{escola_nome}}, seguirão o calendário escolar, obtendo dois recessos anuais de 20 dias, sendo o primeiro em julho e o segundo entre dezembro e janeiro.\n2.2- Não haverá aula em feriados nacionais, municipais, religiosos e nos dias das Apresentações. Em meses com cinco semanas não será cobrada aula excedente.\n2.3- No período de férias, as mensalidades serão cobradas normalmente. O ALUNO somente garantirá a manutenção de seu horário, caso esteja em dia com as mensalidades.\n\nCLÁUSULA 3 – DAS MENSALIDADES\n3.1 Os cursos de {{curso_nome}} terão isenção de matrícula, restando o compromisso dos responsáveis sobre a mensalidade estabelecida no valor de R$ {{curso_mensalidade}} ({{curso_mensalidade_extenso}}) a ser paga de maneira antecipada sobre o mês a ser cursado, com vencimento para todo dia {{vencimento_dia}} de cada mês.\n\n{{data_atual_extenso}}.',
  `dbHost` varchar(255) DEFAULT NULL,
  `dbUser` varchar(255) DEFAULT NULL,
  `dbPass` varchar(255) DEFAULT NULL,
  `dbName` varchar(255) DEFAULT NULL,
  `dbPort` varchar(10) DEFAULT NULL,
  `mp_active` varchar(10) DEFAULT 'false',
  `mp_public_key` varchar(255) DEFAULT '',
  `mp_access_token` varchar(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `system_settings`
--

INSERT INTO `system_settings` (`id`, `smtpServer`, `smtpPort`, `smtpUser`, `smtpPass`, `site_url`, `email_approval_subject`, `email_approval_body`, `email_reset_subject`, `email_reset_body`, `email_reminder_subject`, `email_reminder_body`, `reminderDaysBefore`, `certificate_template_text`, `certificate_background_image`, `language`, `timeZone`, `currencySymbol`, `enableTerminationFine`, `terminationFineMonths`, `defaultDueDay`, `geminiApiKey`, `geminiApiEndpoint`, `imageTermsText`, `enrollmentContractText`, `dbHost`, `dbUser`, `dbPass`, `dbName`, `dbPort`, `mp_active`, `mp_public_key`, `mp_access_token`) VALUES
(1, NULL, NULL, NULL, NULL, 'http://localhost/seu_projeto/', 'Sua Matrícula foi Aprovada!', 'Olá {{responsavel_nome}},\n\nParabéns! A matrícula de {{aluno_nome}} no curso {{curso_nome}} foi aprovada.\n\nVeja seu contrato:\n{{link_contrato}}\n\nAtenciosamente,\nEquipe {{escola_nome}}', 'Redefinição de Senha Solicitada', 'Olá {{user_name}},\n\nRecebemos uma solicitação para redefinir sua senha no sistema {{escola_nome}}.\n\nClique no link abaixo para criar uma nova senha (link expira em 1 hora):\n{{reset_link}}\n\nSe não foi você, ignore este e-mail.\n\nAtenciosamente,\nEquipe {{escola_nome}}', 'Lembrete: Sua mensalidade vence em breve', 'Olá {{aluno_nome}},\n\nEste é um lembrete amigável de que a mensalidade do curso {{curso_nome}} vence no dia {{vencimento_data}}.\nValor: R$ {{valor}}\n\nCaso já tenha efetuado o pagamento, por favor, desconsidere este e-mail.\n\nAtenciosamente,\nEquipe {{escola_nome}}', 3, 'Certificamos que {{aluno_nome}}, portador(a) do RG n.º {{aluno_rg}} e inscrito(a) no CPF sob o n.º {{aluno_cpf}}, concluiu com êxito o curso de {{curso_nome}}, ministrado pelo professor(a) {{professor_nome}}, com carga horária total de {{curso_carga_horaria}} horas, concluído em {{data_conclusao}}.\nEste certificado é emitido por {{escola_nome}}, inscrita no CNPJ sob o n.º {{escola_cnpj}}, para atestar a participação e aprovação do(a) aluno(a).\n\n{{data_emissao_extenso}}.', NULL, 'pt-BR', 'America/Sao_Paulo', 'R$', 0, 1, 10, NULL, NULL, 'Eu, {{contratante_nome}}, portador da cédula de identidade nº {{contratante_rg}} e CPF nº {{contratante_cpf}}, responsável pelo aluno {{aluno_nome}}, autorizo o {{escola_nome}} a utilizar a imagem do(a) aluno(a) nos vídeos e fotos para as redes sociais, site e divulgação em jornais e revistas no que se remete ao centro cultural. Ciente que os mesmos poderão ser veiculados em redes sociais e mídias diversas pela equipe do projeto sem tempo determinado nem avisos prévios. Fica ainda autorizada, de livre e espontânea vontade, para os mesmos fins, a cessão de direitos de veiculação, não recebendo para tanto qualquer tipo de remuneração.\n\n{{data_atual_extenso}}', 'CONTRATANTE: {{contratante_nome}}\nALUNO: {{aluno_nome}}\nEnd.: {{contratante_endereco}}\nRG: {{contratante_rg}} CPF: {{contratante_cpf}}\nCONTRATADA: {{escola_nome}}. CNPJ: {{escola_cnpj}}\n\n(Contratante, doravante RESPONSÁVEL PELO ALUNO / ALUNO. Contratada, doravante {{escola_nome}}.) As partes acima qualificadas têm, entre si, justo e acertado, o presente Contrato de Prestação de Serviços, que será regido pelas cláusulas seguintes e pelas condições de preço, forma e termo de pagamento abaixo descritos:\n\nCLÁUSULA 1 - OBJETO\n1.1- O objeto do presente contrato é a prestação dos serviços voltados a ministrar aulas de {{curso_nome}} ao ALUNO no estabelecimento da {{escola_nome}}.\n1.2- A duração deste contrato é indeterminada e o seu cancelamento dar-se-á mediante a comunicação prévia de 30 dias.\n\nCLÁUSULA 2 - DAS FÉRIAS E DO CALENDÁRIO\n2.1- Os cursos de {{curso_nome}} oferecidos pelo {{escola_nome}}, seguirão o calendário escolar, obtendo dois recessos anuais de 20 dias, sendo o primeiro em julho e o segundo entre dezembro e janeiro.\n2.2- Não haverá aula em feriados nacionais, municipais, religiosos e nos dias das Apresentações. Em meses com cinco semanas não será cobrada aula excedente.\n2.3- No período de férias, as mensalidades serão cobradas normalmente. O ALUNO somente garantirá a manutenção de seu horário, caso esteja em dia com as mensalidades.\n\nCLÁUSULA 3 – DAS MENSALIDADES\n3.1 Os cursos de {{curso_nome}} terão isenção de matrícula, restando o compromisso dos responsáveis sobre a mensalidade estabelecida no valor de R$ {{curso_mensalidade}} ({{curso_mensalidade_extenso}}) a ser paga de maneira antecipada sobre o mês a ser cursado, com vencimento para todo dia {{vencimento_dia}} de cada mês.\n\n{{data_atual_extenso}}.', NULL, NULL, NULL, NULL, NULL, 'false', '', '');


-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `users`
-- Inserindo apenas o usuário superadmin inicial. Senha padrão é 'admin'
--

INSERT INTO `users` (`id`, `firstName`, `lastName`, `email`, `phone`, `password_hash`, `reset_token`, `reset_token_expires_at`, `role`, `age`, `profilePicture`, `address`, `rg`, `cpf`, `birthDate`, `guardianName`, `guardianRG`, `guardianCPF`, `guardianEmail`, `guardianPhone`, `created_at`) VALUES
(1, 'Super', 'Admin', 'admin@admin', NULL, '$2y$10$/J6yz5uYX5iITNf4PvjKruiKJuLPSdxyIhGKGbnXDa6qmhxk5WGea', NULL, NULL, 'superadmin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-02 23:26:14');

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`courseId`,`studentId`,`date`),
  ADD KEY `studentId` (`studentId`);

--
-- Índices de tabela `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `verification_hash` (`verification_hash`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Índices de tabela `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacherId` (`teacherId`),
  ADD KEY `closed_by_admin_id` (`closed_by_admin_id`);

--
-- Índices de tabela `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`studentId`,`courseId`),
  ADD KEY `courseId` (`courseId`);

--
-- Índices de tabela `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `studentId` (`studentId`),
  ADD KEY `courseId` (`courseId`);

--
-- Índices de tabela `school_profile`
--
ALTER TABLE `school_profile`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT de tabela `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT de tabela `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT de tabela `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`teacherId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `courses_ibfk_2` FOREIGN KEY (`closed_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;