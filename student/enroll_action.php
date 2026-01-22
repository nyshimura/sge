<?php
// student/enroll_action.php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
    exit;
}

$studentId = $_SESSION['user_id'];

// 1. CAPTURA DADOS
$inputData = $_POST;
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

if (is_array($jsonData)) {
    $inputData = array_merge($inputData, $jsonData);
}

// 2. DETECTA AÇÃO
$action = '';
if (isset($inputData['acceptContract']) && ($inputData['acceptContract'] == 'on' || $inputData['acceptContract'] == true)) {
    $action = 'accept_contract';
} elseif (isset($inputData['acceptTerms']) && ($inputData['acceptTerms'] == 'on' || $inputData['acceptTerms'] == true)) {
    $action = 'accept_terms';
} else {
    $action = isset($inputData['action']) ? $inputData['action'] : '';
}

$courseId = isset($inputData['courseId']) ? (int)$inputData['courseId'] : 0;

if (!$courseId || empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'accept_contract') {
        
        // --- ATUALIZAÇÃO DOS DADOS DO USUÁRIO (Sempre atualiza dados cadastrais) ---
        $birthDate = !empty($inputData['birthDate']) ? $inputData['birthDate'] : null;
        $guardianName = !empty($inputData['guardianName']) ? $inputData['guardianName'] : null;
        $guardianCPF = !empty($inputData['guardianCPF']) ? $inputData['guardianCPF'] : null;
        $guardianEmail = !empty($inputData['guardianEmail']) ? $inputData['guardianEmail'] : null;
        $guardianPhone = !empty($inputData['guardianPhone']) ? $inputData['guardianPhone'] : null;

        if ($birthDate || $guardianName) {
            $sqlUser = "UPDATE users SET 
                        birthDate = :bd,
                        guardianName = :gn,
                        guardianCPF = :gcpf,
                        guardianEmail = :gemail,
                        guardianPhone = :gphone
                        WHERE id = :uid";
            
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute([
                ':bd' => $birthDate,
                ':gn' => $guardianName,
                ':gcpf' => $guardianCPF,
                ':gemail' => $guardianEmail,
                ':gphone' => $guardianPhone,
                ':uid' => $studentId
            ]);
        }

        // --- LÓGICA DE AUTO-MATRÍCULA (INSERT ou UPDATE) ---
        
        // 1. Verifica se já existe matrícula
        $check = $pdo->prepare("SELECT status FROM enrollments WHERE studentId = :sid AND courseId = :cid");
        $check->execute([':sid' => $studentId, ':cid' => $courseId]);
        $existingEnrollment = $check->fetch(PDO::FETCH_ASSOC);

        $dueDay = date('d');
        $today = date('Y-m-d H:i:s');
        $billingStart = date('Y-m-d'); // Começa a cobrar hoje

        if ($existingEnrollment) {
            // A. CENÁRIO: Matrícula já existe (Admin cadastrou ou PENDENTE) -> APENAS ATUALIZA
            $sqlEnroll = "UPDATE enrollments SET 
                        contractAcceptedAt = :now,
                        status = 'Aprovada', 
                        customDueDay = :dueDay
                    WHERE studentId = :sid AND courseId = :cid";
            
            $stmtEnroll = $pdo->prepare($sqlEnroll);
            $stmtEnroll->execute([
                ':now'    => $today,
                ':dueDay' => $dueDay,
                ':sid'    => $studentId, 
                ':cid'    => $courseId
            ]);
            
            $msg = 'Contrato aceito e matrícula atualizada!';

        } else {
            // B. CENÁRIO: Matrícula nova (Auto-Matrícula) -> INSERE NOVO REGISTRO
            
            // Busca o preço do curso para preencher o customMonthlyFee inicial
            // Se não achar o curso, usa 0.00
            $stmtPrice = $pdo->prepare("SELECT monthlyFee FROM courses WHERE id = :cid");
            $stmtPrice->execute([':cid' => $courseId]);
            $coursePrice = $stmtPrice->fetchColumn();
            $price = $coursePrice ? $coursePrice : 0.00;

            $sqlInsert = "INSERT INTO enrollments (
                            studentId, 
                            courseId, 
                            status, 
                            enrollmentDate, 
                            billingStartDate, 
                            scholarshipPercentage, 
                            customMonthlyFee, 
                            contractAcceptedAt, 
                            customDueDay
                        ) VALUES (
                            :sid, 
                            :cid, 
                            'Pendente', 
                            :now, 
                            :billStart, 
                            0, 
                            :price, 
                            :now, 
                            :dueDay
                        )";

            $stmtInsert = $pdo->prepare($sqlInsert);
            $stmtInsert->execute([
                ':sid'       => $studentId,
                ':cid'       => $courseId,
                ':now'       => $today,
                ':billStart' => $billingStart,
                ':price'     => $price,
                ':dueDay'    => $dueDay
            ]);
            
            $msg = 'Matrícula realizada e contrato aceito com sucesso!';
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $msg]);

    } elseif ($action === 'accept_terms') {
        
        // Para termos, se a matrícula não existir, não faz sentido aceitar termo de imagem
        // Então mantemos o UPDATE, mas garantimos que não erro fatal
        $sql = "UPDATE enrollments SET termsAcceptedAt = NOW() WHERE studentId = :sid AND courseId = :cid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':sid' => $studentId, ':cid' => $courseId]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Termos aceitos.']);
    
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>