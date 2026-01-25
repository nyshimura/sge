<?php
// student/ajax_contract.php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['courseId'])) {
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

$studentId = $_SESSION['user_id'];
$courseId = (int)$_POST['courseId'];
$dueDay = (int)$_POST['dueDay'];

// --- 1. BUSCA DADOS ---
// Aluno (incluindo campos de responsável e RG que já existem na tabela users)
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$studentId]);
$user = $stmtUser->fetch();

// Curso
$stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
$stmtCourse->execute([$courseId]);
$course = $stmtCourse->fetch();

// Configurações e Escola
$settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch();
$school = $pdo->query("SELECT * FROM school_profile WHERE id = 1")->fetch();

if (!$user || !$course) {
    echo json_encode(['error' => 'Dados inválidos.']);
    exit;
}

// --- 2. PREPARAÇÃO DOS DADOS ---

// Dados que podem vir do formulário do modal (caso o aluno esteja preenchendo agora)
$guardianName = $_POST['guardianName'] ?? $user['guardianName'];
$guardianCPF  = $_POST['guardianCPF'] ?? $user['guardianCPF'];
// Se você tiver input de RG no modal, adicione $_POST['guardianRG'], senão pega do banco
$guardianRG   = $user['guardianRG'] ?? ''; 

// Calcula Idade
$birthDate = new DateTime($user['birthDate']);
$isMinor = ((new DateTime())->diff($birthDate)->y < 18);

$alunoNome = $user['firstName'] . ' ' . $user['lastName'];
$alunoCPF = $user['cpf'];
$alunoRG = $user['rg'] ?? '';
$alunoEnd = $user['address'] ?? 'Endereço não informado';

// Define QUEM é o "Contratante" para preencher os placeholders
if ($isMinor) {
    $contratanteNome = $guardianName;
    $contratanteCPF  = $guardianCPF;
    $contratanteRG   = $guardianRG;
    $contratanteEnd  = $alunoEnd; // Assume-se mesmo endereço do aluno
} else {
    $contratanteNome = $alunoNome;
    $contratanteCPF  = $alunoCPF;
    $contratanteRG   = $alunoRG;
    $contratanteEnd  = $alunoEnd;
}

// Formatação Financeira
$valor = number_format($course['monthlyFee'], 2, ',', '.');
$clausulaFin = "restando o compromisso dos responsáveis sobre a mensalidade no valor de R$ {$valor}, vencimento todo dia {$dueDay}";

// Data por Extenso (Manual para garantir funcionamento em qualquer servidor)
$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];
$hoje = new DateTime();
$dataExtenso = $hoje->format('d') . ' de ' . $meses[(int)$hoje->format('m')] . ' de ' . $hoje->format('Y');

// --- 3. PLACEHOLDERS (MAPA DE SUBSTITUIÇÃO) ---
$placeholders = [
    // Dados do Aluno
    '{{aluno_nome}}' => $alunoNome,
    '{{aluno_cpf}}' => $alunoCPF,
    '{{aluno_rg}}' => $alunoRG,
    '{{aluno_endereco}}' => $alunoEnd,
    
    // Dados do Responsável
    '{{responsavel_nome}}' => $guardianName,
    '{{responsavel_cpf}}' => $guardianCPF,
    '{{responsavel_rg}}' => $guardianRG,
    
    // Dados do Contratante (Varia pela idade)
    '{{contratante_nome}}' => $contratanteNome,
    '{{contratante_cpf}}' => $contratanteCPF,
    '{{contratante_rg}}' => $contratanteRG,       // <--- ADICIONADO
    '{{contratante_endereco}}' => $contratanteEnd, // <--- ADICIONADO
    
    // Dados do Curso/Escola
    '{{curso_nome}}' => $course['name'],
    '{{curso_mensalidade}}' => 'R$ ' . $valor,
    '{{vencimento_dia}}' => $dueDay,
    '{{clausula_financeira}}' => $clausulaFin,
    '{{escola_nome}}' => $school['name'],
    '{{escola_cnpj}}' => $school['cnpj'] ?? '',
    
    // Data
    '{{data_atual_extenso}}' => $dataExtenso        // <--- ADICIONADO
];

// --- 4. PROCESSAMENTO DOS TEXTOS ---

// A. Contrato de Prestação de Serviços
$contractText = $settings['enrollmentContractText'];
foreach ($placeholders as $key => $val) {
    $contractText = str_replace($key, $val, $contractText);
}

// B. Termo de Imagem
if ($isMinor) {
    $termRaw = !empty($settings['term_text_minor']) ? $settings['term_text_minor'] : $settings['imageTermsText'];
} else {
    $termRaw = !empty($settings['term_text_adult']) ? $settings['term_text_adult'] : $settings['imageTermsText'];
}

$termText = $termRaw;
foreach ($placeholders as $key => $val) {
    $termText = str_replace($key, $val, $termText);
}

// --- 5. RETORNO ---
echo json_encode([
    'contract' => nl2br($contractText),
    'terms'    => nl2br($termText)
]);
?>
