<?php
// api/handlers/pdf_helpers.php

/**
 * Converte string UTF-8 para ISO-8859-1 (compatibilidade FPDF).
 */
function to_iso($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $str);
}

/**
 * Adiciona um logo centralizado no PDF (usado em certificados e contratos).
 * Suporta imagem em Base64 ou caminho de arquivo.
 * Retorna a posição Y logo após a imagem.
 */
function add_centered_logo($pdf, $imgData, &$tmpFilesArray) {
    $startY = $pdf->GetY();
    if (empty($imgData)) return $startY;

    $tmp_path = null;

    // Se for Base64
    if (strpos($imgData, 'data:image') === 0) {
        $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imgData));
        if ($data) {
            // Detecta extensão (jpg/png)
            $finfo = finfo_open();
            $mime = finfo_buffer($finfo, $data, FILEINFO_MIME_TYPE);
            finfo_close($finfo);
            $ext = ($mime === 'image/png') ? '.png' : '.jpg';
            
            $tmp_path = sys_get_temp_dir() . '/doc_logo_' . uniqid() . $ext;
            file_put_contents($tmp_path, $data);
            $tmpFilesArray[] = $tmp_path;
        }
    } else {
        // Se for caminho de arquivo (e existir)
        if (file_exists($imgData)) {
            $tmp_path = $imgData;
        }
    }

    if ($tmp_path && file_exists($tmp_path)) {
        // Tamanho fixo para o logo no cabeçalho
        $width = 30; 
        $height = 30;
        
        // Centraliza na página
        $pageWidth = $pdf->GetPageWidth();
        $x = ($pageWidth - $width) / 2;
        
        $pdf->Image($tmp_path, $x, $startY, $width, $height);
        return $startY + $height; // Retorna nova posição Y
    }

    return $startY;
}

/**
 * Converte um valor numérico (float) para texto por extenso (BRL).
 * Ex: 90.50 -> noventa reais e cinquenta centavos
 */
function valorPorExtenso($valor = 0, $maiusculas = false) {
    // Limpa formatação se vier como string (ex: 1.200,00 -> 1200.00)
    if (is_string($valor) && strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }

    $valor = (float)$valor;
    if($valor == 0) return "zero";

    $singular = ["centavo", "real", "mil", "milhão", "bilhão", "trilhão", "quatrilhão"];
    $plural = ["centavos", "reais", "mil", "milhões", "bilhões", "trilhões", "quatrilhões"];

    $c = ["", "cem", "duzentos", "trezentos", "quatrocentos", "quinhentos", "seiscentos", "setecentos", "oitocentos", "novecentos"];
    $d = ["", "dez", "vinte", "trinta", "quarenta", "cinquenta", "sessenta", "setenta", "oitenta", "noventa"];
    $d10 = ["dez", "onze", "doze", "treze", "quatorze", "quinze", "dezesseis", "dezessete", "dezoito", "dezenove"];
    $u = ["", "um", "dois", "três", "quatro", "cinco", "seis", "sete", "oito", "nove"];

    $z = 0;
    // Formata com 2 casas decimais
    $valor = number_format($valor, 2, ".", ".");
    $inteiro = explode(".", $valor);
    
    $rt = "";
    
    // Adiciona zeros à esquerda para completar trios (ex: 1 -> 001)
    for($i=0; $i<count($inteiro); $i++) {
        for($ii=strlen($inteiro[$i]); $ii<3; $ii++) {
            $inteiro[$i] = "0".$inteiro[$i];
        }
    }

    $fim = count($inteiro) - ($inteiro[count($inteiro)-1] > 0 ? 1 : 2);
    
    for ($i=0; $i<count($inteiro); $i++) {
        $valor = $inteiro[$i];
        
        // Cento ou Cem?
        $rc = (($valor > 100) && ($valor < 200)) ? "cento" : $c[$valor[0]];
        // Dezenas
        $rd = ($valor[1] < 2) ? "" : $d[$valor[1]];
        // Unidades ou Dez a Dezenove
        $ru = ($valor > 0) ? (($valor[1] == 1) ? $d10[$valor[2]] : $u[$valor[2]]) : "";

        // Concatena as partes (cento E vinte E cinco)
        $r = $rc . (($rc && ($rd || $ru)) ? " e " : "") . $rd . (($rd && $ru) ? " e " : "") . $ru;
        
        // Define a escala (mil, milhão, etc)
        $t = count($inteiro)-1-$i;
        
        // Adiciona o nome da escala no plural ou singular
        $r .= $r ? " ".($valor > 1 ? $plural[$t] : $singular[$t]) : "";
        
        if ($valor == "000") $z++; elseif ($z > 0) $z--;
        
        // Lógica do "de" (um milhão DE reais)
        if (($t==1) && ($z>0) && ($inteiro[0] > 0)) $r .= (($z>1) ? " de " : "").$plural[$t];
        
        if ($r) {
            // Vírgula ou "e" entre as partes maiores
            $rt = $rt . ((($i > 0) && ($i <= $fim) && ($inteiro[0] > 0) && ($z < 1)) ? ( ($i < $fim) ? ", " : " e ") : " ") . $r;
        }
    }

    // Remove espaços extras
    $rt = trim($rt);

    if(!$maiusculas){
        return $rt;
    } else {
        return strtoupper($rt);
    }
}
?>
