<?php
// includes/generate_id_card_pdf.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once(__DIR__ . '/functions.php');
require_once(__DIR__ . '/../libs/fpdf/fpdf.php');

// 1. LIMPEZA DE BUFFER
if (ob_get_level()) ob_end_clean();

require_once(__DIR__ . '/../config/database.php');

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if (!$studentId && !$teacherId) {
    die("Faltam parametros (student_id ou teacher_id).");
}

if ($teacherId > 0) {
    // 2A. BUSCA OS DADOS DO PROFESSOR
    $sql = "SELECT firstName, lastName, cpf, profilePicture, role_title FROM users WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $teacherId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) die("Funcionario nao encontrado.");

    $settings = $pdo->query("SELECT teacher_id_card_template_id FROM system_settings WHERE id = 1")->fetch();
    if (empty($settings['teacher_id_card_template_id'])) die("Modelo de carteirinha para professor nao configurado no sistema.");

    $data['id_card_template_id'] = $settings['teacher_id_card_template_id'];
    $data['courseName'] = !empty($data['role_title']) ? $data['role_title'] : 'Professor';
} else {
    // 2B. BUSCA OS DADOS DA MATRÍCULA E DO ALUNO
    if (!$courseId) die("Falta o parametro course_id para o aluno.");
    
    $sql = "SELECT 
                u.firstName, u.lastName, u.cpf, u.profilePicture,
                c.name as courseName, c.id_card_template_id,
                e.status as enrollmentStatus, e.enrollmentDate
            FROM enrollments e
            INNER JOIN users u ON e.studentId = u.id
            INNER JOIN courses c ON e.courseId = c.id
            WHERE e.studentId = :sid AND e.courseId = :cid
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sid' => $studentId, ':cid' => $courseId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) die("Matricula nao encontrada.");
    if (empty($data['id_card_template_id'])) die("Este curso nao possui modelo de carteirinha configurado.");
}

// 3. BUSCA O TEMPLATE
$stmtTpl = $pdo->prepare("SELECT * FROM id_card_templates WHERE id = :id");
$stmtTpl->execute([':id' => $data['id_card_template_id']]);
$template = $stmtTpl->fetch(PDO::FETCH_ASSOC);

if (!$template) die("Modelo de carteirinha nao encontrado.");

// 4. PREPARO DAS IMAGENS TEMPORÁRIAS (Fundo e Foto de Perfil)
function base64ToTempFile($base64String, $prefix = 'img_') {
    if (empty($base64String)) return null;
    $parts = explode(';', $base64String);
    if (count($parts) < 2) return null;
    list($type, $data) = $parts;
    
    $partsData = explode(',', $data);
    if (count($partsData) < 2) return null;
    $data = $partsData[1];
    
    $data = base64_decode($data);
    
    $ext = 'png';
    if (strpos($type, 'jpeg') !== false || strpos($type, 'jpg') !== false) $ext = 'jpg';
    
    $tmpFile = sys_get_temp_dir() . '/' . $prefix . uniqid() . '.' . $ext;
    file_put_contents($tmpFile, $data);
    return $tmpFile;
}

$bgTemp = base64ToTempFile($template['background_image'], 'bg_');

$profileTemp = null;
if (!empty($data['profilePicture'])) {
    $profileTemp = base64ToTempFile($data['profilePicture'], 'prof_');
}
// Fallback para Iniciais (ui-avatars) se não houver foto
if (!$profileTemp) {
    $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($data['firstName']) . "&background=random&size=140&color=fff&format=png";
    $avatarData = false;
    
    // Usa cURL para evitar bloqueios de hospedagens em produção (allow_url_fopen off)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $avatarUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SGE-System');
        $avatarData = curl_exec($ch);
        curl_close($ch);
    }
    
    // Fallback
    if (!$avatarData) {
        $opts = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        $ctx = stream_context_create($opts);
        $avatarData = @file_get_contents($avatarUrl, false, $ctx);
    }

    if ($avatarData) {
        $profileTemp = sys_get_temp_dir() . '/prof_' . uniqid() . '.png';
        file_put_contents($profileTemp, $avatarData);
    }
}

// 5. DECODE DO JSON DE POSIÇÕES
$layout = json_decode($template['template_text'], true);
if (!is_array($layout)) {
    $layout = [];
}

// 6. GERAÇÃO DO PDF
class IDCardPDF extends FPDF {
    public $bg;
    function Header() {
        if ($this->bg && file_exists($this->bg)) {
            $this->Image($this->bg, 0, 0, $this->GetPageWidth(), $this->GetPageHeight());
        }
    }
    
    function CircleClip($x, $y, $r) {
        $this->_out('q');
        $d = $r * 0.552284749831;
        $k = $this->k;
        $h = $this->h;
        
        $x0 = $x; $y0 = $y;
        $cx = $x0 + $r; $cy = $y0 + $r;
        
        $this->_out(sprintf('%.2F %.2F m', ($cx+$r)*$k, ($h-$cy)*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx+$r)*$k, ($h-($cy-$d))*$k,
            ($cx+$d)*$k, ($h-($cy-$r))*$k,
            $cx*$k, ($h-($cy-$r))*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx-$d)*$k, ($h-($cy-$r))*$k,
            ($cx-$r)*$k, ($h-($cy-$d))*$k,
            ($cx-$r)*$k, ($h-$cy)*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx-$r)*$k, ($h-($cy+$d))*$k,
            ($cx-$d)*$k, ($h-($cy+$r))*$k,
            $cx*$k, ($h-($cy+$r))*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx+$d)*$k, ($h-($cy+$r))*$k,
            ($cx+$r)*$k, ($h-($cy+$d))*$k,
            ($cx+$r)*$k, ($h-$cy)*$k));
            
        $this->_out('W n');
    }
    
    function EndClip() {
        $this->_out('Q');
    }
}

// Tamanho CR80 Padrão: 85.6mm x 54mm
$orientation = (isset($template['orientation']) && $template['orientation'] == 'P') ? 'P' : 'L';
$pdf = new IDCardPDF($orientation, 'mm', array(54, 85.6));
$pdf->bg = $bgTemp;
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Função auxiliar ISO
function to_iso($str) {
    return mb_convert_encoding($str ?? '', 'ISO-8859-1', 'UTF-8');
}



// Função para converter hex color para RGB
function hex2rgb($hex) {
    $hex = str_replace("#", "", $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return array($r, $g, $b);
}

// Imprimir Textos Dinâmicos
foreach ($layout as $field => $config) {
    $x = isset($config['x']) ? (float)$config['x'] : 10;
    $y = isset($config['y']) ? (float)$config['y'] : 10;
    $size = isset($config['size']) ? (float)$config['size'] : 10;
    
    $color = isset($config['color']) ? $config['color'] : '#000000';
    list($r, $g, $b) = hex2rgb($color);
    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetFont('Arial', 'B', $size);
    $pdf->SetXY($x, $y);
    
    if ($field == 'name' || $field == 'course' || $field == 'cpf') {
        $text = '';
        if ($field == 'name') $text = to_iso($data['firstName'] . ' ' . $data['lastName']);
        if ($field == 'course') $text = to_iso($data['courseName']);
        if ($field == 'cpf') $text = to_iso($data['cpf']);
        
        $align = isset($config['align']) ? $config['align'] : 'L';
        $w = isset($config['w']) ? (float)$config['w'] : 0;
        
        if ($w > 0) {
            $maxWidth = $w;
        } else {
            $maxWidth = $pdf->GetPageWidth() - $x - 2; // 2mm margin from right edge
        }
        
        // Shrink font size if text is too wide
        $currentSize = $size;
        $pdf->SetFont('Arial', 'B', $currentSize);
        while ($pdf->GetStringWidth($text) > $maxWidth && $currentSize > 4) {
            $currentSize -= 0.5;
            $pdf->SetFont('Arial', 'B', $currentSize);
        }
        
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 0, $text, 0, 0, $align);
        
    } elseif ($field == 'photo') {
        // Foto do aluno
        if ($profileTemp && file_exists($profileTemp)) {
            $w = isset($config['w']) ? (float)$config['w'] : 20;
            $h = isset($config['h']) ? (float)$config['h'] : 25;
            $shape = isset($config['shape']) ? $config['shape'] : 'rect';
            
            if ($shape === 'circle') {
                $r = min($w, $h) / 2;
                $pdf->CircleClip($x, $y, $r);
                
                $info = @getimagesize($profileTemp);
                if ($info) {
                    $origW = $info[0];
                    $origH = $info[1];
                    $ratio = max(($r*2) / $origW, ($r*2) / $origH);
                    $drawW = $origW * $ratio;
                    $drawH = $origH * $ratio;
                    $drawX = $x + $r - ($drawW / 2);
                    $drawY = $y + $r - ($drawH / 2);
                    $pdf->Image($profileTemp, $drawX, $drawY, $drawW, $drawH);
                } else {
                    $pdf->Image($profileTemp, $x, $y, $r*2, $r*2);
                }
                
                $pdf->EndClip();
            } else {
                $pdf->Image($profileTemp, $x, $y, $w, $h);
            }
        }
    }
}

// Exclui temporários
if ($bgTemp && file_exists($bgTemp)) @unlink($bgTemp);
if ($profileTemp && file_exists($profileTemp)) @unlink($profileTemp);

$pdf->Output('I', 'Carteirinha_' . $data['firstName'] . '.pdf');
