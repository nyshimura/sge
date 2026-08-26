<?php
// student/ajax_contract.php
require_once '../config/database.php';
require_once '../includes/functions.php';

checkLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Acesso inválido');
}

$courseId = (int)$_POST['courseId'];
$studentId = $_SESSION['user_id'];

// Dados do Formulário (Pré-matrícula)
$inputDueDay = isset($_POST['dueDay']) ? (int)$_POST['dueDay'] : 10;
$respName = $_POST['guardianName'] ?? '';
$respCPF = $_POST['guardianCPF'] ?? '';
$respRG = $_POST['guardianRG'] ?? '';
$respEmail = $_POST['guardianEmail'] ?? '';

try {
    // 1. Buscar Dados
    $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmtUser->execute([':id' => $studentId]);
    $user = $stmtUser->fetch();

    $stmtSchool = $pdo->query("SELECT * FROM school_profile WHERE id = 1");
    $school = $stmtSchool->fetch();

    $stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id");
    $stmtCourse->execute([':id' => $courseId]);
    $course = $stmtCourse->fetch();

    $stmtSettings = $pdo->query("SELECT enrollmentContractText, imageTermsText FROM system_settings WHERE id = 1");
    $settings = $stmtSettings->fetch();

    // 2. Lógica Menor de Idade
    $birthDate = new DateTime($user['birthDate']);
    $isMinor = ((new DateTime())->diff($birthDate)->y < 18);

    if ($isMinor) {
        $c_nome = !empty($respName) ? $respName : ($user['guardianName'] ?? '_________________');
        $c_cpf  = !empty($respCPF) ? $respCPF : ($user['guardianCPF'] ?? '_________________');
        $c_rg   = !empty($respRG) ? $respRG : ($user['guardianRG'] ?? '_________________');
        $c_email = !empty($respEmail) ? $respEmail : ($user['guardianEmail'] ?? '_________________');
        $c_end  = $user['address'] ?? '_________________'; 
    } else {
        $c_nome = $user['firstName'] . ' ' . $user['lastName'];
        $c_cpf  = $user['cpf'];
        $c_rg   = $user['rg'];
        $c_email = $user['email'];
        $c_end  = $user['address'];
    }

    // 3. Lógica Financeira (Na pré-matrícula, assume-se o valor cheio do curso, pois a bolsa é dada pelo ADM depois)
    // Se quiser que apareça "Isento" aqui, precisaria passar essa info, mas geralmente contrato inicial é padrão.
    // Vamos manter a lógica padrão, mas usando a função de extenso para ficar bonito.
    
    $valorFormatado = number_format($course['monthlyFee'], 2, ',', '.');
    
    // Se o valor for > 0, gera texto de cobrança. Se for 0, gera isenção.
    if ($course['monthlyFee'] > 0) {
        $valorExtenso = valorPorExtenso($course['monthlyFee']);
        $clausulaFinanceira = "restando o compromisso dos responsáveis sobre a mensalidade estabelecida no valor de R$ {$valorFormatado} ({$valorExtenso}) a ser paga de maneira antecipada sobre o mês a ser cursado, com vencimento para todo dia {$inputDueDay} de cada mês";
    } else {
        $clausulaFinanceira = "sendo concedida bolsa integral de 100% (cem por cento), isentando o ALUNO e seus responsáveis de quaisquer mensalidades referentes ao curso.";
    }

    // 4. Placeholders
    $placeholders = [
        '{{aluno_nome}}' => $user['firstName'] . ' ' . $user['lastName'],
        '{{aluno_email}}' => $user['email'],
        '{{aluno_cpf}}' => $user['cpf'] ?? 'N/A',
        '{{aluno_rg}}' => $user['rg'] ?? 'N/A',
        '{{aluno_endereco}}' => $user['address'] ?? 'N/A',
        
        '{{responsavel_nome}}' => !empty($respName) ? $respName : 'N/A',
        '{{responsavel_cpf}}' => !empty($respCPF) ? $respCPF : 'N/A',
        '{{responsavel_rg}}' => !empty($respRG) ? $respRG : 'N/A',
        
        '{{contratante_nome}}' => $c_nome,
        '{{contratante_cpf}}' => $c_cpf,
        '{{contratante_rg}}' => $c_rg,
        '{{contratante_email}}' => $c_email,
        '{{contratante_endereco}}' => $c_end,
        
        '{{curso_nome}}' => $course['name'],
        '{{curso_mensalidade}}' => 'R$ ' . $valorFormatado,
        '{{vencimento_dia}}' => $inputDueDay,
        '{{clausula_financeira}}' => $clausulaFinanceira,
        
        '{{escola_nome}}' => $school['name'],
        '{{escola_cnpj}}' => $school['cnpj'],
        '{{escola_endereco}}' => $school['address'],
        
        '{{data_atual_extenso}}' => date('d/m/Y')
    ];

    $contractHtml = strtr($settings['enrollmentContractText'], $placeholders);
    $termsHtml = strtr($settings['imageTermsText'], $placeholders);

    echo json_encode([
        'contract' => nl2br($contractHtml),
        'terms' => nl2br($termsHtml)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>