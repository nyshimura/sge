<?php
// includes/generate_certificate_pdf.php

// 1. Configurações de Ambiente
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);

// 2. Localização do Banco de Dados
$db_file = null;
$possible_paths = [__DIR__ . '/../config/db.php', __DIR__ . '/../config/database.php'];
foreach ($possible_paths as $path) { if (file_exists($path)) { $db_file = $path; break; } }
if ($db_file) { require_once $db_file; } else { die("Erro: Banco de dados nao encontrado."); }

// Bibliotecas
require_once(__DIR__ . '/../libs/fpdf/fpdf.php');
require_once(__DIR__ . '/../libs/phpqrcode/qrlib.php');

// Função para evitar erro de utf8_decode no PHP 8.2+
function to_iso($text) { return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'); }
if (ob_get_level()) ob_end_clean();

// --- 3. RECEBIMENTO DO HASH ---
$hash = isset($_GET['hash']) ? preg_replace('/[^a-f0-9]/', '', $_GET['hash']) : '';
if (strlen($hash) !== 64) die("Hash invalido.");

// --- 4. BUSCA DE DADOS COMPLETA ---
try {
    // [ALTERAÇÃO] Adicionado cert.custom_workload na consulta
    $sql = "SELECT 
                cert.completion_date, cert.verification_hash, cert.custom_workload,
                u.firstName, u.lastName, u.cpf, u.rg,
                c.name as courseName, c.carga_horaria,
                prof.firstName as profNome, prof.lastName as profSobrenome,
                s.name as schoolName, s.cnpj as schoolCnpj, s.profilePicture, s.signatureImage, s.schoolCity, s.state,
                st.certificate_template_text, st.certificate_background_image, st.site_url
            FROM certificates cert
            INNER JOIN users u ON cert.student_id = u.id
            INNER JOIN courses c ON cert.course_id = c.id
            LEFT JOIN users prof ON c.teacherId = prof.id
            CROSS JOIN school_profile s
            CROSS JOIN system_settings st
            WHERE cert.verification_hash = :hash AND s.id = 1 AND st.id = 1
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':hash' => $hash]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) die("Certificado nao encontrado.");

} catch (PDOException $e) { die("Erro BD"); }

// --- 5. PROCESSAMENTO DE IMAGENS ---
function processImageBase64($base64Data) {
    if (empty($base64Data)) return false;
    if (strpos($base64Data, ',') !== false) { $base64Data = explode(',', $base64Data)[1]; }
    $decoded = base64_decode($base64Data);
    if (!$decoded) return false;
    $tmpFile = sys_get_temp_dir() . '/img_' . uniqid() . '.png';
    file_put_contents($tmpFile, $decoded);
    return $tmpFile;
}

$bgPath   = processImageBase64($data['certificate_background_image']);
$logoPath = processImageBase64($data['profilePicture']);
$sigPath  = processImageBase64($data['signatureImage']);

// --- 6. DATA POR EXTENSO ---
$meses = [1=>"Janeiro", 2=>"Fevereiro", 3=>"Março", 4=>"Abril", 5=>"Maio", 6=>"Junho", 7=>"Julho", 8=>"Agosto", 9=>"Setembro", 10=>"Outubro", 11=>"Novembro", 12=>"Dezembro"];
$dataExtenso = $data['schoolCity'] . ", " . date('d', strtotime($data['completion_date'])) . " de " . $meses[(int)date('m', strtotime($data['completion_date']))] . " de " . date('Y', strtotime($data['completion_date']));

// --- 7. SUBSTITUIÇÃO DE PLACEHOLDERS ---
// [LÓGICA NOVA] Verifica se tem carga horária personalizada, senão usa a do curso
$cargaHorariaFinal = !empty($data['custom_workload']) ? $data['custom_workload'] : ($data['carga_horaria'] ?? '0');

$template = $data['certificate_template_text'];
$placeholders = [
    '{{aluno_nome}}'            => strtoupper($data['firstName'] . ' ' . $data['lastName']),
    '{{aluno_cpf}}'             => $data['cpf'],
    '{{aluno_rg}}'              => $data['rg'] ?: '---',
    '{{curso_nome}}'            => strtoupper($data['courseName']),
    '{{curso_carga_horaria}}'   => $cargaHorariaFinal, // Usa a variável calculada acima
    '{{professor_nome}}'        => strtoupper(($data['profNome'] ?? '') . ' ' . ($data['profSobrenome'] ?? 'Direção')),
    '{{escola_nome}}'           => $data['schoolName'],
    '{{escola_cnpj}}'           => $data['schoolCnpj'],
    '{{data_conclusao}}'        => date('d/m/Y', strtotime($data['completion_date'])),
    '{{data_emissao_extenso}}'  => $dataExtenso,
    '{{dataemissaoextenso}}'    => $dataExtenso 
];

$textoFinal = str_replace(array_keys($placeholders), array_values($placeholders), $template);

// --- 8. GERAÇÃO DO PDF ---
class PDF_Final extends FPDF {
    public $bg;
    function Header() {
        if ($this->bg && file_exists($this->bg)) {
            $this->Image($this->bg, 0, 0, $this->GetPageWidth(), $this->GetPageHeight());
        }
    }
}

$pdf = new PDF_Final('L', 'mm', 'A4');
$pdf->bg = $bgPath;
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Logo
if ($logoPath && file_exists($logoPath)) {
    $pdf->Image($logoPath, ($pdf->GetPageWidth()/2) - 14, 14, 25);
}

// Título e Conteúdo
$pdf->SetY(46);
$pdf->SetFont('Arial', 'B', 30);
$pdf->Cell(0, 15, to_iso("CERTIFICADO"), 0, 1, 'C');

$pdf->SetY(60);
$pdf->SetX(40);
$pdf->SetFont('Arial', '', 14);
$pdf->SetMargins(30, 20, 30);
$pdf->MultiCell(0, 8, to_iso($textoFinal), 20, 'C');

// --- 9. BLOCO DE VERIFICAÇÃO COM HYPERLINK ---
$siteUrl = rtrim($data['site_url'], '/');
$verifUrl = $siteUrl . "/verificar/?hash=" . $hash;

$tmpQr = sys_get_temp_dir() . '/qr_' . uniqid() . '.png';
QRcode::png($verifUrl, $tmpQr, QR_ECLEVEL_L, 3, 1);

$qrX = 50;
$qrY = $pdf->GetPageHeight() - 45;

// QR Code Clicável
$pdf->Image($tmpQr, $qrX, $qrY, 22, 22, 'PNG', $verifUrl);

// Texto ao lado do QR Code
$pdf->SetXY($qrX + 24, $qrY + 8); 
$pdf->SetFont('Arial', '', 6);
$pdf->SetTextColor(50, 50, 50);

// Texto explicativo
$pdf->Write(3, to_iso("Verifique a autenticidade em:\n"));
$pdf->SetX($qrX + 24);

// Link clicável
$pdf->Write(4, to_iso($siteUrl . "/verificar/"), $verifUrl);

// Código SHA-256
$pdf->Ln(4);
$pdf->SetX($qrX + 24);
$pdf->SetTextColor(50, 50, 50);
$pdf->MultiCell(0, 4, to_iso("Código: " . $hash), 0, 'L');

// --- 10. ASSINATURA ---
if ($sigPath && file_exists($sigPath)) {
    $pdf->Image($sigPath, $pdf->GetPageWidth() - 105, $pdf->GetPageHeight() - 85, 50);
}
// Linha de assinatura sólida
$pdf->Line($pdf->GetPageWidth() - 0, $pdf->GetPageHeight() - 0, $pdf->GetPageWidth() - 0, $pdf->GetPageHeight() - 0);
$pdf->SetXY($pdf->GetPageWidth() - 110, $pdf->GetPageHeight() - 56);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(60, 1, to_iso($data['schoolName']), 0, 0, 'C');

// Saída final
$pdf->Output('I', 'Certificado.pdf');

// Limpeza de arquivos temporários
$files = [$bgPath, $logoPath, $sigPath, $tmpQr];
foreach ($files as $f) { if ($f && file_exists($f)) @unlink($f); }
?>