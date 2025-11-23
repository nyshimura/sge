<?php
/**
 * Ponto de Entrada da API (Roteador Principal)
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// 1. Inicia a Sessão
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 2. Carrega configurações e banco de dados
require_once __DIR__ . '/config.php';

// 3. Define a ação solicitada
$action = $_REQUEST['action'] ?? '';

// 4. Definição de Rotas e Arquivos
$actionMap = [
    // Auth
    'login' => __DIR__ . '/handlers/auth_handlers.php',
    'register' => __DIR__ . '/handlers/auth_handlers.php',
    'logout' => __DIR__ . '/handlers/auth_handlers.php',
    'requestPasswordReset' => __DIR__ . '/handlers/auth_handlers.php',
    'resetPassword' => __DIR__ . '/handlers/auth_handlers.php',
    
    // User & Profile
    'getUserProfile' => __DIR__ . '/handlers/user_handlers.php',
    'getProfileData' => __DIR__ . '/handlers/user_handlers.php',
    'updateUserProfile' => __DIR__ . '/handlers/user_handlers.php', 
    'uploadProfilePicture' => __DIR__ . '/handlers/user_handlers.php',
    'listUsers' => __DIR__ . '/handlers/user_handlers.php',
    'createUser' => __DIR__ . '/handlers/user_handlers.php',
    'deleteUser' => __DIR__ . '/handlers/user_handlers.php',
    'getFilteredUsers' => __DIR__ . '/handlers/user_handlers.php',
    'updateUserRole' => __DIR__ . '/handlers/user_handlers.php',
    'getTeachers' => __DIR__ . '/handlers/user_handlers.php',
    'getActiveStudents' => __DIR__ . '/handlers/user_handlers.php',
    'getDashboardData' => __DIR__ . '/handlers/user_handlers.php',
    
    // School & System
    'getSchoolProfile' => __DIR__ . '/handlers/system_handlers.php',
    'updateSchoolProfile' => __DIR__ . '/handlers/system_handlers.php',
    'uploadSchoolLogo' => __DIR__ . '/handlers/system_handlers.php',
    'getSystemSettings' => __DIR__ . '/handlers/system_handlers.php',
    'updateSystemSettings' => __DIR__ . '/handlers/system_handlers.php',
    'updateDocumentTemplates' => __DIR__ . '/handlers/system_handlers.php',
    'exportDatabase' => __DIR__ . '/handlers/system_handlers.php',
    
    // Course
    'createCourse' => __DIR__ . '/handlers/course_handlers.php',
    'updateCourse' => __DIR__ . '/handlers/course_handlers.php',
    'getCourses' => __DIR__ . '/handlers/course_handlers.php',
    'getCourseDetails' => __DIR__ . '/handlers/course_handlers.php',
    'endCourse' => __DIR__ . '/handlers/course_handlers.php',
    'reopenCourse' => __DIR__ . '/handlers/course_handlers.php',
    'saveAttendance' => __DIR__ . '/handlers/course_handlers.php',
    'getAttendance' => __DIR__ . '/handlers/course_handlers.php',
    'getAttendanceData' => __DIR__ . '/handlers/course_handlers.php',
    
    // Enrollment
    'initiateEnrollment' => __DIR__ . '/handlers/enrollment_handlers.php',
    'getEnrollmentDocuments' => __DIR__ . '/handlers/enrollment_handlers.php',
    'submitEnrollment' => __DIR__ . '/handlers/enrollment_handlers.php',
    'approveEnrollment' => __DIR__ . '/handlers/enrollment_handlers.php',
    'cancelEnrollment' => __DIR__ . '/handlers/enrollment_handlers.php',
    'reactivateEnrollment' => __DIR__ . '/handlers/enrollment_handlers.php',
    'updateEnrollmentDetails' => __DIR__ . '/handlers/enrollment_handlers.php',
    'submitReenrollment' => __DIR__ . '/handlers/enrollment_handlers.php',
    
    // Financial
    'generatePayment' => __DIR__ . '/handlers/financial_handlers.php',
    'getFinancialDashboard' => __DIR__ . '/handlers/financial_handlers.php',
    'getFinancialDashboardData' => __DIR__ . '/handlers/financial_handlers.php',
    'getFinancialReport' => __DIR__ . '/handlers/financial_handlers.php',
    'getDefaulters' => __DIR__ . '/handlers/financial_handlers.php',
    'getDefaultersReport' => __DIR__ . '/handlers/financial_handlers.php',
    'updatePaymentStatus' => __DIR__ . '/handlers/financial_handlers.php',
    'bulkUpdatePaymentStatus' => __DIR__ . '/handlers/financial_handlers.php',
    'getPaymentHistory' => __DIR__ . '/handlers/financial_handlers.php',
    'getStudentPayments' => __DIR__ . '/handlers/financial_handlers.php',
    'generateReceiptPdf' => __DIR__ . '/handlers/receipt_handler.php',
    
    // Certificates
    'generateCertificate' => __DIR__ . '/handlers/certificate_handler.php',
    'viewCertificate' => __DIR__ . '/handlers/certificate_handler.php',
    'verifyCertificate' => __DIR__ . '/handlers/certificate_handler.php',
    'getMyCertificates' => __DIR__ . '/handlers/certificate_handler.php',
    'getStudentCertificates' => __DIR__ . '/handlers/certificate_handler.php',
    
    // Contracts & Documents
    'generateContractPdf' => __DIR__ . '/handlers/contract_handler.php',
    'generateImageTermsPdf' => __DIR__ . '/handlers/image_term_handler.php',
    
    // AI
    'generateDescriptionAI' => __DIR__ . '/handlers/ai_handlers.php',
    'generateAiDescription' => __DIR__ . '/handlers/ai_handlers.php',
];

// 5. Controle de Acesso (Segurança)
$publicActions = [
    'login', 
    'register', 
    'requestPasswordReset', 
    'resetPassword', 
    'getSchoolProfile',
    'verifyCertificate',
    'generateContractPdf' 
];

if (!isset($_SESSION['user_id']) && !in_array($action, $publicActions)) {
    send_response(false, ['message' => 'Acesso não autorizado. Faça login novamente.'], 401);
}

// 6. Execução da Ação
if (array_key_exists($action, $actionMap)) {
    $handlerFile = $actionMap[$action];
    
    if (file_exists($handlerFile)) {
        require_once $handlerFile;
        
        // --- MISTURA JSON COM $_REQUEST (CORREÇÃO DEFINITIVA) ---
        // Garante que dados enviados como JSON (axios/fetch) sejam lidos corretamente
        $params = $_REQUEST;
        $jsonInput = file_get_contents('php://input');
        $jsonData = json_decode($jsonInput, true);
        if (is_array($jsonData)) {
            $params = array_merge($params, $jsonData);
        }
        unset($params['action']); 
        // ------------------------------------------------------
        
        // Converte nome da ação para nome da função (snake_case)
        $functionNameSnake = 'handle_' . fromCamelCase($action);
        $functionNameExact = 'handle_' . $action;
        
        if (function_exists($functionNameSnake)) {
            $functionNameSnake($conn, $params);
        } elseif (function_exists($functionNameExact)) {
            $functionNameExact($conn, $params);
        } else {
            send_response(false, ['message' => "Função handler '$functionNameSnake' não encontrada."], 500);
        }
    } else {
        send_response(false, ['message' => "Arquivo handler não encontrado."], 500);
    }
} else {
    send_response(false, ['message' => 'Ação inválida: ' . htmlspecialchars($action)], 400);
}

// --- Funções Auxiliares ---

function send_response($success, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'data' => $data]);
    exit;
}

function fromCamelCase($input) {
    return ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '_$0', $input)), '_');
}
?>