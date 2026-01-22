<?php
// includes/functions.php

// Inicia a sessão apenas se ainda não existir
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Limpeza básica contra XSS
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Verifica se o usuário está logado. Se não, manda pro login.
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php"); // Ajustado caminho para sair da subpasta se necessário
        exit;
    }
}

// Verifica se o usuário tem permissão para estar naquela página
function checkRole($allowed_roles) {
    // CORREÇÃO 1: Alterado de user_type para user_role para bater com o seu login.php
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowed_roles)) {
        
        // CORREÇÃO 2: Redireciona para o index da raiz com erro, em vez de dashboard.php
        header("Location: ../login.php?error=sem_permissao");
        exit;
    }
}

/**
 * Converte valor numérico para extenso (em Reais)
 */
function valorPorExtenso($valor = 0, $maiusculas = false) {
    if(!$valor) return "zero reais";

    $singular = ["centavo", "real", "mil", "milhão", "bilhão", "trilhão", "quatrilhão"];
    $plural = ["centavos", "reais", "mil", "milhões", "bilhões", "trilhões", "quatrilhões"];

    $c = ["", "cem", "duzentos", "trezentos", "quatrocentos", "quinhentos", "seiscentos", "setecentos", "oitocentos", "novecentos"];
    $d = ["", "dez", "vinte", "trinta", "quarenta", "cinquenta", "sessenta", "setenta", "oitenta", "noventa"];
    $d10 = ["dez", "onze", "doze", "treze", "quatorze", "quinze", "dezesseis", "dezessete", "dezoito", "dezenove"];
    $u = ["", "um", "dois", "três", "quatro", "cinco", "seis", "sete", "oito", "nove"];

    $z = 0;
    $valor = number_format($valor, 2, ".", ".");
    $inteiro = explode(".", $valor);
    $cont = count($inteiro);
    if ($cont > 1) {
        for ($i = 0; $i < $cont - 1; $i++) 
            $inteiro[$i] = str_pad($inteiro[$i], 3, "0", STR_PAD_LEFT);
    }
    
    $fim = count($inteiro) - ($inteiro[count($inteiro) - 1] > 0 ? 1 : 2);
    $rt = "";
    
    for ($i = 0; $i < $cont; $i++) {
        $valor = $inteiro[$i];
        $rc = (($valor > 100) && ($valor < 200)) ? "cento" : $c[$valor[0]];
        $rd = ($valor[1] < 2) ? "" : $d[$valor[1]];
        $ru = ($valor > 0) ? (($valor[1] == 1) ? $d10[$valor[2]] : $u[$valor[2]]) : "";

        $r = $rc . (($rc && ($rd || $ru)) ? " e " : "") . $rd . (($rd && $ru) ? " e " : "") . $ru;
        $t = count($inteiro) - 1 - $i;
        
        $r .= $r ? " " . ($valor > 1 ? $plural[$t] : $singular[$t]) : "";
        if ($valor == "000") $z++; elseif ($z > 0) $z--;
        
        if (($t == 1) && ($z > 0) && ($inteiro[0] > 0)) $r .= (($z > 1) ? " de " : "") . $plural[$t];
        if ($r) $rt = $rt . ((($i > 0) && ($i <= $fim) && ($inteiro[0] > 0) && ($z < 1)) ? ( ($i < $fim) ? ", " : " e ") : " ") . $r;
    }

    if (!$maiusculas) {
        return trim($rt ? $rt : "zero");
    } else {
        return trim(strtoupper($rt) ? strtoupper($rt) : "Zero");
    }
}
?>