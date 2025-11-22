<?php
// api/handlers/contract_handler.php

require_once __DIR__ . '/../fpdf/fpdf.php';
require_once __DIR__ . '/pdf_classes.php';    // Para PDF_Contract
require_once __DIR__ . '/pdf_helpers.php';       // Para to_iso, add_centered_logo
require_once __DIR__ . '/document_data_helper.php'; // Para get_document_details, get_placeholders

/**
 * Gera PDF do Contrato
 */
function handle_generate_contract_pdf($conn, $data) {
    error_reporting(0); ini_set('display_errors', 0);
    $studentId = isset($_GET['studentId']) ? (int)$_GET['studentId'] : 0;
    $courseId = isset($_GET['courseId']) ? (int)$_GET['courseId'] : 0;
    if ($studentId <= 0 || $courseId <= 0) { /* ... erro 400 ... */ }

    $tmp_files = []; // Array para guardar caminhos de arquivos temporários
    try {
        $details = get_document_details($conn, $studentId, $courseId);
        if (!$details || empty($details['enrollmentContractText'])) { /* ... erro 404 ... */ }
        $replacements = get_placeholders($details);
        $documentText = $details['enrollmentContractText'];
        foreach ($replacements as $ph => $val) { $documentText = str_replace($ph, $val ?? '', $documentText); }

        // Sidebar Text
        $formattedAcceptedAt = "N/A"; $documentHash = "N/A"; $acceptedAtTimestamp = time();
        if (!empty($details['contractAcceptedAt'])) { try { $dateUTC = new DateTime($details['contractAcceptedAt'], new DateTimeZone('UTC')); $dateSP = $dateUTC->setTimezone(new DateTimeZone('America/Sao_Paulo')); $formattedAcceptedAt = $dateSP->format('d/m/Y H:i:s'); $acceptedAtTimestamp = $dateSP->getTimestamp(); } catch (Exception $e) { $formattedAcceptedAt = "Inválida"; } }
        $hashData = implode('|', [$details['studentId']??'',$details['courseId']??'',$details['schoolCnpj']??'',$acceptedAtTimestamp,substr($documentText,0,100)]); $documentHash = substr(hash('sha256',$hashData),0,16);
        $sidebarText = "Hash: ".$documentHash."  |  Aceito em: ".$formattedAcceptedAt." (Horario de Brasilia)";

        $pdf = new PDF_Contract('P', 'mm', 'A4');
        $pdf->SetSidebarText($sidebarText);
        $pdf->AddPage();
        $pdf->SetMargins(20, 15, 15); $pdf->SetAutoPageBreak(true, 15);

        // ADICIONA LOGO CENTRALIZADO
        $posY_after_logo = add_centered_logo($pdf, $details['profilePicture'] ?? null, $tmp_files);
        $pdf->SetY($posY_after_logo); 
        $pdf->Ln(5); 

        $pdf->SetFont('Arial', 'B', 14); $pdf->Cell(0, 10, to_iso('CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS'), 0, 1, 'C'); $pdf->Ln(10); $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(0,0,0);
        $pdf->MultiCell(0, 5, to_iso($documentText)); $pdf->Ln(10);

        // Assinaturas
        $line_y = $pdf->GetY(); if ($line_y > ($pdf->GetPageHeight() - 50)) { $pdf->AddPage(); $line_y = $pdf->GetY(); } $pageWidth = $pdf->GetPageWidth(); $margin = 20; $line_width = ($pageWidth - (2 * $margin) - 10) / 2; $line_start_contratante = $margin; $line_start_contratado = $margin + $line_width + 10;
        $pdf->Line($line_start_contratante, $line_y, $line_start_contratante + $line_width, $line_y); $pdf->SetXY($line_start_contratante, $line_y + 1); $pdf->MultiCell($line_width, 4, to_iso("CONTRATANTE:\n" . ($replacements['{{contratante_nome}}'] ?? '') . "\nCPF: " . ($replacements['{{contratante_cpf}}'] ?? '')), 0, 'C');
        $pdf->Line($line_start_contratado, $line_y, $line_start_contratado + $line_width, $line_y);
        
        if (!empty($details['signatureImage'])) {
            // ... (código para adicionar assinatura) ...
             $img_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $details['signatureImage'])); $finfo=finfo_open(); $mime=finfo_buffer($finfo,$img_data,FILEINFO_MIME_TYPE); finfo_close($finfo); $ext=($mime==='image/jpeg')?'.jpg':'.png';
            $tmp_sig_path = sys_get_temp_dir().'/sig_sge_'.uniqid().$ext;
            if(file_put_contents($tmp_sig_path, $img_data)) {
                $tmp_files[] = $tmp_sig_path; 
                $sig_width=40; $sig_height=20; $sig_x=$line_start_contratado+($line_width/2)-($sig_width/2); $sig_y=$line_y-$sig_height+2;
                $pdf->Image($tmp_sig_path, $sig_x, $sig_y, $sig_width, $sig_height);
            }
        }
        $pdf->SetXY($line_start_contratado, $line_y + 1); $pdf->MultiCell($line_width, 4, to_iso("CONTRATADO:\n" . ($replacements['{{escola_nome}}'] ?? '') . "\nCNPJ: " . ($replacements['{{escola_cnpj}}'] ?? '')), 0, 'C');

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
