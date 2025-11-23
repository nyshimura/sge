<?php
// api/handlers/contract_handler.php

require_once __DIR__ . '/../fpdf/fpdf.php';
require_once __DIR__ . '/pdf_classes.php';    
require_once __DIR__ . '/pdf_helpers.php';       
require_once __DIR__ . '/document_data_helper.php'; 

/**
 * Gera PDF do Contrato
 */
function handle_generate_contract_pdf($conn, $data) {
    error_reporting(0); 
    ini_set('display_errors', 0);

    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $studentId = isset($_GET['studentId']) ? (int)$_GET['studentId'] : 0;
    $courseId = isset($_GET['courseId']) ? (int)$_GET['courseId'] : 0;

    // --- 1. REDIRECIONAMENTO INTELIGENTE (Login) ---
    if (!isset($_SESSION['user_id'])) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $currentLink = "$protocol://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $returnUrl = urlencode($currentLink);
        header("Location: ../index.html?returnUrl={$returnUrl}#login"); 
        exit;
    }

    // --- 2. BUSCA DADOS ---
    if ($studentId <= 0 || $courseId <= 0) {
        die("Link inválido.");
    }

    $details = get_document_details($conn, $studentId, $courseId);
    
    if (!$details) {
        die("Matrícula não encontrada.");
    }

    // --- 3. VERIFICAÇÃO DE PERMISSÃO ---
    
    $currentUserId = $_SESSION['user_id'];
    
    // CORREÇÃO AQUI: Tenta 'user_role' (padrão do index.php) e 'role' (possível legado)
    $currentUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
    
    // Busca e-mail do usuário logado
    $stmtUser = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmtUser->execute([$currentUserId]);
    $currentUserEmail = trim($stmtUser->fetchColumn());
    $guardianEmail = isset($details['guardianEmail']) ? trim($details['guardianEmail']) : '';

    // Definição dos Papéis:
    
    // A. É o próprio aluno?
    $isStudent = ($currentUserId == $studentId);
    
    // B. É Admin ou Superadmin?
    $isAdmin = ($currentUserRole === 'admin' || $currentUserRole === 'superadmin'); 
    
    // C. É o Responsável Financeiro?
    $isGuardian = (!empty($guardianEmail) && strcasecmp($currentUserEmail, $guardianEmail) === 0);

    // Bloqueio de Segurança
    if (!$isStudent && !$isAdmin && !$isGuardian) {
        header('Content-Type: text/html; charset=utf-8');
        echo "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Acesso Negado</title>
            <link rel='stylesheet' href='../index.css'>
        </head>
        <body>
            <div class='app-content' style='display: flex; justify-content: center; align-items: center; min-height: 80vh;'>
                <div class='auth-container' style='max-width: 500px; width: 100%; text-align: center;'>
                    <h2 class='error-message' style='font-size: 1.8rem; margin-bottom: 20px;'>🚫 Acesso Restrito</h2>
                    
                    <p>Você está logado como: <strong>" . htmlspecialchars($currentUserEmail) . "</strong></p>
                    <p>Cargo: <strong>" . htmlspecialchars($currentUserRole) . "</strong></p>
                    <p>Contrato do aluno(a): <strong>" . htmlspecialchars($details['firstName']) . "</strong></p>
                    
                    <div style='background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0; font-size: 0.9rem; text-align: left;'>
                        <strong>Motivo:</strong><br>
                        Seu usuário não tem permissão para ver este documento.<br>
                        Apenas Aluno, Responsável ou Admin têm acesso.
                    </div>
                    
                    <a href='../index.html#dashboard' class='auth-button' style='text-decoration: none; display: block;'>
                        Voltar ao Painel
                    </a>
                </div>
            </div>
        </body>
        </html>";
        exit;
    }

    // --- 4. GERAÇÃO DO PDF ---
    $tmp_files = []; 
    try {
        if (empty($details['enrollmentContractText'])) { 
            throw new Exception("Texto do contrato não configurado no sistema."); 
        }
        
        $replacements = get_placeholders($details);
        $documentText = $details['enrollmentContractText'];
        foreach ($replacements as $ph => $val) { 
            $documentText = str_replace($ph, $val ?? '', $documentText); 
        }

        // Sidebar Text
        $formattedAcceptedAt = "N/A"; 
        $documentHash = "N/A"; 
        $acceptedAtTimestamp = time();
        
        if (!empty($details['contractAcceptedAt'])) { 
            try { 
                $dateUTC = new DateTime($details['contractAcceptedAt'], new DateTimeZone('UTC')); 
                $dateSP = $dateUTC->setTimezone(new DateTimeZone('America/Sao_Paulo')); 
                $formattedAcceptedAt = $dateSP->format('d/m/Y H:i:s'); 
                $acceptedAtTimestamp = $dateSP->getTimestamp(); 
            } catch (Exception $e) { $formattedAcceptedAt = "Inválida"; } 
        }
        
        $hashData = implode('|', [$details['studentId']??'',$details['courseId']??'',$details['schoolCnpj']??'',$acceptedAtTimestamp,substr($documentText,0,100)]); 
        $documentHash = substr(hash('sha256',$hashData),0,16);
        $sidebarText = "Hash: ".$documentHash."  |  Aceito em: ".$formattedAcceptedAt." (Horario de Brasilia)";

        $pdf = new PDF_Contract('P', 'mm', 'A4');
        $pdf->SetSidebarText($sidebarText);
        $pdf->AddPage();
        $pdf->SetMargins(20, 15, 15); 
        $pdf->SetAutoPageBreak(true, 15);

        // Logo
        $posY_after_logo = add_centered_logo($pdf, $details['profilePicture'] ?? null, $tmp_files);
        $pdf->SetY($posY_after_logo); 
        $pdf->Ln(5); 

        $pdf->SetFont('Arial', 'B', 14); 
        $pdf->Cell(0, 10, to_iso('CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS'), 0, 1, 'C'); 
        $pdf->Ln(5); 
        
        $pdf->SetFont('Arial', '', 10); 
        $pdf->SetTextColor(0,0,0);
        $pdf->MultiCell(0, 4, to_iso($documentText)); 
        $pdf->Ln(5);

        // Assinaturas
        $line_y = $pdf->GetY(); 
        if ($line_y > ($pdf->GetPageHeight() - 30)) { 
            $pdf->AddPage(); 
            $line_y = $pdf->GetY(); 
        } 
        
        $pageWidth = $pdf->GetPageWidth(); 
        $margin = 20; 
        $line_width = ($pageWidth - (2 * $margin) - 10) / 2; 
        $line_start_contratante = $margin; 
        $line_start_contratado = $margin + $line_width + 10;
        
        $pdf->Line($line_start_contratante, $line_y, $line_start_contratante + $line_width, $line_y); 
        $pdf->SetXY($line_start_contratante, $line_y + 1); 
        $pdf->MultiCell($line_width, 4, to_iso("CONTRATANTE:\n" . ($replacements['{{contratante_nome}}'] ?? '') . "\nCPF: " . ($replacements['{{contratante_cpf}}'] ?? '')), 0, 'C');
        
        $pdf->Line($line_start_contratado, $line_y, $line_start_contratado + $line_width, $line_y);
        
        if (!empty($details['signatureImage'])) {
             $img_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $details['signatureImage'])); 
             if ($img_data) {
                $finfo=finfo_open(); $mime=finfo_buffer($finfo,$img_data,FILEINFO_MIME_TYPE); finfo_close($finfo); 
                $ext=($mime==='image/jpeg')?'.jpg':'.png';
                $tmp_sig_path = sys_get_temp_dir().'/sig_sge_'.uniqid().$ext;
                if(file_put_contents($tmp_sig_path, $img_data)) {
                    $tmp_files[] = $tmp_sig_path; 
                    $sig_width=40; $sig_height=20; 
                    $sig_x=$line_start_contratado+($line_width/2)-($sig_width/2); 
                    $sig_y=$line_y-$sig_height-2; 
                    $pdf->Image($tmp_sig_path, $sig_x, $sig_y, $sig_width, $sig_height);
                }
             }
        }
        
        $pdf->SetXY($line_start_contratado, $line_y + 1); 
        $pdf->MultiCell($line_width, 4, to_iso("CONTRATADO:\n" . ($replacements['{{escola_nome}}'] ?? '') . "\nCNPJ: " . ($replacements['{{escola_cnpj}}'] ?? '')), 0, 'C');

        foreach ($tmp_files as $file) { if (file_exists($file)) @unlink($file); }

        header('Content-Type: application/pdf');
        $pdf->Output('I', 'contrato_' . $studentId . '_' . $courseId .'.pdf');
        exit;

    } catch (Exception $e) {
        foreach ($tmp_files as $file) { if (file_exists($file)) @unlink($file); } 
        error_log("Erro FATAL PDF: ".$e->getMessage());
        header("HTTP/1.1 500 Internal Server Error"); 
        echo "Erro interno ao gerar PDF: " . $e->getMessage(); 
        exit;
    }
}
?>
