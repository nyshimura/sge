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

    // --- LÓGICA DE REDIRECIONAMENTO INTELIGENTE ---
    
    // A. Se não estiver logado
    if (!isset($_SESSION['user_id'])) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $currentLink = "$protocol://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $returnUrl = urlencode($currentLink);
        header("Location: ../index.html?returnUrl={$returnUrl}#login"); 
        exit;
    }

    // B. Busca detalhes do contrato
    if ($studentId <= 0 || $courseId <= 0) {
        die("Link inválido ou incompleto.");
    }

    $details = get_document_details($conn, $studentId, $courseId);
    
    if (!$details) {
        die("Matrícula não encontrada ou dados indisponíveis.");
    }

    // C. Verifica Permissões (Aluno, Admin ou RESPONSÁVEL)
    $currentUserId = $_SESSION['user_id'];
    $currentUserRole = $_SESSION['role'] ?? '';
    
    $stmtUser = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmtUser->execute([$currentUserId]);
    $currentUserEmail = $stmtUser->fetchColumn();

    $isStudent = ($currentUserId == $studentId);
    $isAdmin = ($currentUserRole === 'admin' || $currentUserRole === 'superadmin' || $currentUserRole === 'teacher'); 
    $isGuardian = (!empty($details['guardianEmail']) && strcasecmp($currentUserEmail, $details['guardianEmail']) === 0);

    if (!$isStudent && !$isAdmin && !$isGuardian) {
        // --- MUDANÇA AQUI: HTML COMPLETO COM TEMA ---
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
                <div class='auth-container' style='max-width: 500px; width: 100%;'>
                    <h2 class='error-message' style='font-size: 1.8rem; margin-bottom: 20px;'>🚫 Acesso Negado</h2>
                    
                    <div class='list-item' style='flex-direction: column; align-items: flex-start; margin-bottom: 20px;'>
                        <span class='list-item-subtitle'>Você está logado como:</span>
                        <span class='list-item-title'>" . htmlspecialchars($currentUserEmail) . "</span>
                    </div>

                    <div class='list-item' style='flex-direction: column; align-items: flex-start; margin-bottom: 20px;'>
                        <span class='list-item-subtitle'>Este contrato pertence ao aluno(a):</span>
                        <span class='list-item-title'>" . htmlspecialchars($details['firstName']) . "</span>
                    </div>

                    <p style='color: var(--text-muted); margin-bottom: 30px; font-size: 0.95rem;'>
                        Se você é o responsável, verifique se está usando o <strong>mesmo e-mail</strong> cadastrado na matrícula.
                    </p>
                    
                    <a href='../index.html#dashboard' class='auth-button' style='text-decoration: none; display: block; text-align: center;'>
                        Voltar ao Painel
                    </a>
                </div>
            </div>
        </body>
        </html>";
        exit;
    }

    $tmp_files = []; 
    try {
        if (empty($details['enrollmentContractText'])) { 
            throw new Exception("Texto do contrato não configurado."); 
        }
        
        $replacements = get_placeholders($details);
        $documentText = $details['enrollmentContractText'];
        foreach ($replacements as $ph => $val) { 
            $documentText = str_replace($ph, $val ?? '', $documentText); 
        }

        // Sidebar Text
        $formattedAcceptedAt = "N/A"; $documentHash = "N/A"; $acceptedAtTimestamp = time();
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
        // Margens ajustadas
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
        error_log("!!! Erro FATAL PDF contrato S:$studentId, C:$courseId: ".$e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
        header("HTTP/1.1 500 Internal Server Error"); header("Content-Type: text/plain; charset=utf-8"); echo "Erro interno ao gerar PDF."; exit;
    }
}
?>