<?php
// 1. LIMPEZA DE BUFFER (Obrigatório para FPDF)
if (ob_get_level()) ob_end_clean();
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);

// 2. INICIA SESSÃO (Garante que $_SESSION esteja disponível)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// Verifica biblioteca FPDF
if (file_exists('../libs/fpdf/fpdf.php')) {
    require('../libs/fpdf/fpdf.php');
} elseif (file_exists('../libs/fpdf.php')) {
    require('../libs/fpdf.php');
} else {
    die("Erro: Biblioteca FPDF nao encontrada.");
}

// Verifica se está logado
if (!isset($_SESSION['user_id'])) {
    die("Acesso negado. Por favor, faça login.");
}

// --- 1. IDENTIFICAÇÃO ROBUSTA DE PERMISSÃO ---
$currentUserId = $_SESSION['user_id'];

// Consulta o banco para ter certeza do cargo do usuário logado (Infalível)
try {
    $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmtRole->execute([$currentUserId]);
    $userRoleDb = $stmtRole->fetchColumn(); // Retorna 'admin', 'student', etc.
} catch (Exception $e) {
    die("Erro ao verificar permissões.");
}

// Define quem é o aluno alvo do contrato
$targetStudentId = $currentUserId; // Padrão: o próprio usuário

// Se for Admin/Superadmin E tiver passado um ID na URL, muda o alvo
if (in_array($userRoleDb, ['admin', 'superadmin']) && isset($_GET['sid'])) {
    $targetStudentId = (int)$_GET['sid'];
}

// --- 2. RECEBIMENTO DE DADOS DO CONTRATO ---
$courseId = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'contract';

if (!$courseId || !$targetStudentId) {
    die("Dados insuficientes (Curso ou Aluno faltando).");
}

// --- 3. BUSCA DE DADOS DA MATRÍCULA ---
$sql = "SELECT 
            e.contractAcceptedAt, e.termsAcceptedAt, e.customDueDay, e.customMonthlyFee, e.scholarshipPercentage,
            c.name as courseName, c.monthlyFee as standardFee,
            u.firstName, u.lastName, u.email, u.cpf, u.rg, u.address, u.birthDate,
            u.guardianName, u.guardianCPF, u.guardianRG, u.guardianEmail
        FROM enrollments e
        JOIN courses c ON e.courseId = c.id
        JOIN users u ON e.studentId = u.id
        WHERE e.courseId = :cid AND e.studentId = :sid
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([':cid' => $courseId, ':sid' => $targetStudentId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

// --- DEBUG VISUAL (Se der erro, vai mostrar o motivo exato) ---
if (!$data) {
    echo "<h3>Erro: Matrícula não encontrada.</h3>";
    echo "<ul>";
    echo "<li><strong>Usuário Logado (ID):</strong> $currentUserId</li>";
    echo "<li><strong>Perfil Detectado (DB):</strong> $userRoleDb</li>";
    echo "<li><strong>Tentando acessar Aluno (ID):</strong> $targetStudentId</li>";
    echo "<li><strong>No Curso (ID):</strong> $courseId</li>";
    echo "</ul>";
    echo "<p>Se 'Tentando acessar Aluno' for igual a 'Usuário Logado' mas você clicou em outro aluno, o sistema não reconheceu seu admin.</p>";
    exit;
}

$settings = $pdo->query("SELECT enrollmentContractText, imageTermsText FROM system_settings WHERE id = 1")->fetch();
$school = $pdo->query("SELECT * FROM school_profile WHERE id = 1")->fetch();

// --- 4. PREPARAÇÃO DO TEXTO ---
if ($type == 'terms') {
    $rawText = $settings['imageTermsText'];
    $docTitle = "TERMO DE USO DE IMAGEM";
    $acceptedAt = $data['termsAcceptedAt'];
} else {
    $rawText = $settings['enrollmentContractText'];
    $docTitle = "CONTRATO DE PRESTACAO DE SERVICOS EDUCACIONAIS";
    $acceptedAt = $data['contractAcceptedAt'];
}

// Menor de Idade
$birthDate = new DateTime($data['birthDate']);
$isMinor = ((new DateTime())->diff($birthDate)->y < 18);

$alunoNome = strtoupper($data['firstName'] . ' ' . $data['lastName']);
$alunoRG = $data['rg'] ?? '';
$alunoCPF = $data['cpf'] ?? '';
$alunoEnd = !empty($data['address']) ? $data['address'] : 'Endereço não informado';

if ($isMinor) {
    $c_nome = strtoupper($data['guardianName']); 
    $c_cpf = $data['guardianCPF']; 
    $c_rg = $data['guardianRG'];
} else {
    $c_nome = $alunoNome; 
    $c_cpf = $alunoCPF; 
    $c_rg = $alunoRG;
}

// Financeiro
$baseFee = (float)$data['standardFee'];
$customFee = !empty($data['customMonthlyFee']) ? (float)$data['customMonthlyFee'] : null;
$scholarship = !empty($data['scholarshipPercentage']) ? (float)$data['scholarshipPercentage'] : 0;

if ($customFee !== null) {
    $finalFee = $customFee;
} else {
    $finalFee = max(0, $baseFee - ($baseFee * ($scholarship / 100)));
}

$diaVenc = !empty($data['customDueDay']) ? $data['customDueDay'] : '10';

if ($finalFee <= 0.01) {
    $clausulaFinanceira = "sendo concedida bolsa integral de 100% (cem por cento), isentando o ALUNO e seus responsáveis de quaisquer mensalidades";
    $valFmt = "ISENTO";
} else {
    $valFmt = number_format($finalFee, 2, ',', '.');
    $clausulaFinanceira = "restando o compromisso dos responsáveis sobre a mensalidade no valor de R$ {$valFmt}, vencimento todo dia {$diaVenc}";
}

function dataExtenso($data = null) {
    $meses = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
    $d = $data ? new DateTime($data) : new DateTime();
    return $d->format('d') . ' de ' . $meses[(int)$d->format('m')] . ' de ' . $d->format('Y');
}

// Placeholders
$placeholders = [
    '{{aluno_nome}}' => $alunoNome, '{{aluno_cpf}}' => $alunoCPF,
    '{{responsavel_nome}}' => $data['guardianName'], '{{responsavel_cpf}}' => $data['guardianCPF'],
    '{{contratante_nome}}' => $c_nome, '{{contratante_cpf}}' => $c_cpf, '{{contratante_rg}}' => $c_rg,
    '{{contratante_endereco}}' => $alunoEnd,
    '{{curso_nome}}' => $data['courseName'], '{{curso_mensalidade}}' => 'R$ '.$valFmt,
    '{{vencimento_dia}}' => $diaVenc, '{{clausula_financeira}}' => $clausulaFinanceira,
    '{{escola_nome}}' => $school['name'], '{{escola_cnpj}}' => $school['cnpj'],
    '{{data_atual_extenso}}' => dataExtenso()
];

$finalText = strtr($rawText, $placeholders);
$subs = ['–'=>'-', '—'=>'-', '“'=>'"', '”'=>'"', '‘'=>"'", '’'=>"'", '?'=>'-'];
$finalText = str_replace("CLÁUSULA 3 ? DAS", "CLÁUSULA 3 - DAS", $finalText);
$finalText = strtr($finalText, $subs);
$finalText = str_replace(['<br>', '<br/>'], "\n", $finalText);
$finalText = strip_tags($finalText);

// --- 4. TRATAMENTO DE IMAGEM ---
function prepareImageForFPDF($dbData) {
    if (empty($dbData)) return false;

    if (strpos($dbData, 'data:image') === 0) {
        if (preg_match('/^data:image\/(\w+);base64,/', $dbData, $type)) {
            $dbData = substr($dbData, strpos($dbData, ',') + 1);
            $type = strtolower($type[1]);
            if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) return false;
            $dbData = base64_decode($dbData);
            if ($dbData === false) return false;
            $tempFile = sys_get_temp_dir() . '/' . uniqid('img_') . '.' . $type;
            file_put_contents($tempFile, $dbData);
            return ['path' => $tempFile, 'is_temp' => true];
        }
    }
    $cleanPath = ltrim($dbData, '/');
    $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $paths = [$root . '/' . $cleanPath, '../' . $cleanPath];
    foreach($paths as $p) { if(file_exists($p)) return ['path' => $p, 'is_temp' => false]; }
    return false;
}

$logoInfo = prepareImageForFPDF($school['profilePicture']);
$sigInfo = prepareImageForFPDF($school['signatureImage']);

// --- 5. CLASSE PDF ---
class PDF extends FPDF {
    public $logoFile;
    function Header() {
        if ($this->logoFile && file_exists($this->logoFile)) {
            $this->Image($this->logoFile, 92, 6, 26); 
            $this->Ln(26);
        } else {
            $this->Ln(6);
        }
    }
    function Footer() {}
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->logoFile = $logoInfo ? $logoInfo['path'] : false;
$pdf->SetMargins(12, 6, 12);
$pdf->SetAutoPageBreak(true, 5);
$pdf->AliasNbPages();
$pdf->AddPage();

// TÍTULO
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, utf8_decode(strtoupper($docTitle)), 0, 1, 'C');

// CABEÇALHO DADOS
$h = 4.5; 
$pdf->SetFont('Arial', '', 9);

// Contratante
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(28, $h, utf8_decode("CONTRATANTE:"), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, $h, utf8_decode($c_nome), 0, 1);

// Aluno
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(14, $h, utf8_decode("ALUNO:"), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(80, $h, utf8_decode($alunoNome), 0, 0);

// Endereço
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(9, $h, utf8_decode("End.:"), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, $h, utf8_decode($alunoEnd), 0, 1);

// RG/CPF
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(8, $h, utf8_decode("RG:"), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(30, $h, utf8_decode($c_rg), 0, 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(10, $h, utf8_decode("CPF:"), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, $h, utf8_decode($c_cpf), 0, 1);

// Contratada
$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, $h, utf8_decode("CONTRATADA:"), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, $h, utf8_decode($school['name'] . ". CNPJ: " . $school['cnpj']), 0, 1);
$pdf->Ln(2);

// TEXTO
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 4.5, utf8_decode($finalText), 0, 'J');

// --- RODAPÉ DE ASSINATURAS ---
$pdf->Ln(6);

// === DATA CENTRALIZADA SOBRE O CONTRATANTE ===
$cidade = !empty($school['schoolCity']) ? $school['schoolCity'] : 'Guarulhos';
$dataImpressao = $acceptedAt ? dataExtenso($acceptedAt) : dataExtenso();
$pdf->SetFont('Arial', '', 9);

// Truque: Movemos o cursor para X=12 (início da coluna do contratante)
// A coluna tem 85mm. Usamos Cell(85...) com 'C' para centralizar a data dentro desse espaço.
$pdf->SetX(12);
$pdf->Cell(85, 5, utf8_decode("$cidade, $dataImpressao"), 0, 1, 'C');
$pdf->Ln(4);

// Verifica espaço
if ($pdf->GetY() > 255) $pdf->AddPage();

$yStart = $pdf->GetY();
$colWidth = 85;

// -- ESQUERDA: CONTRATANTE --
$pdf->SetXY(12, $yStart);
$pdf->Cell($colWidth, 4, "___________________________________", 0, 1, 'C');
$pdf->SetX(12);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($colWidth, 4, utf8_decode("CONTRATANTE"), 0, 1, 'C');
$pdf->SetX(12);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell($colWidth, 3, utf8_decode($c_nome), 0, 1, 'C');
$pdf->SetX(12);
$pdf->Cell($colWidth, 3, utf8_decode("CPF: " . $c_cpf), 0, 1, 'C');

// -- DIREITA: ESCOLA --
// Imagem carimbo
$pdf->SetXY(110, $yStart - 12); 
if ($sigInfo && file_exists($sigInfo['path'])) {
    $pdf->Image($sigInfo['path'], 138, $pdf->GetY(), 30);
}
$pdf->SetXY(110, $yStart);
$pdf->Cell($colWidth, 4, "___________________________________", 0, 1, 'C');
$pdf->SetX(110);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($colWidth, 4, utf8_decode("CONTRATADO"), 0, 1, 'C');
$pdf->SetX(110);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell($colWidth, 3, utf8_decode($school['name']), 0, 1, 'C');
$pdf->SetX(110);
$pdf->Cell($colWidth, 3, utf8_decode("CNPJ: " . $school['cnpj']), 0, 1, 'C');

$pdf->Ln(10);

// Validação Digital
if ($acceptedAt) {
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'I', 6);
    $msg = "Assinado digitalmente em " . date('d/m/Y H:i', strtotime($acceptedAt)) . " | IP: " . $_SERVER['REMOTE_ADDR'];
    $pdf->Cell(0, 4, utf8_decode($msg), 0, 1, 'C', true);
}

$pdf->Output('I', 'Contrato.pdf');

// Limpeza
if ($logoInfo && $logoInfo['is_temp']) @unlink($logoInfo['path']);
if ($sigInfo && $sigInfo['is_temp']) @unlink($sigInfo['path']);
?>