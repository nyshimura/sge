<?php
// config/database.php

define('DB_HOST', 'localhost');
define('DB_NAME', 'nomedobd');
define('DB_USER', 'nomedabase');
define('DB_PASS', 'suasenha');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    
    // Configurações de erro e fetch padrão
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // =================================================================
    // CONFIGURAÇÃO GLOBAL DE FUSO HORÁRIO (TIMEZONE)
    // =================================================================
    try {
        // Tenta buscar o timezone configurado na tabela system_settings
        $stmtConfig = $pdo->query("SELECT timeZone FROM system_settings LIMIT 1");
        $sysConfig = $stmtConfig->fetch();

        if ($sysConfig && !empty($sysConfig['timeZone'])) {
            // Se houver configuração no banco, aplica ela
            date_default_timezone_set($sysConfig['timeZone']);
        } else {
            // Fallback: Se não houver configuração, usa São Paulo como padrão
            date_default_timezone_set('America/Sao_Paulo'); 
        }

        // [IMPORTANTE] Sincroniza o horário do MySQL com o do PHP
        // Isso garante que funções SQL como NOW() gravem o horário correto
        $timeOffset = date('P'); // Retorna algo como '-03:00'
        $pdo->exec("SET time_zone = '$timeOffset'");

    } catch (Exception $e) {
        // Se der erro (ex: tabela ainda não criada na instalação), define padrão silenciosamente
        date_default_timezone_set('America/Sao_Paulo');
    }
    // =================================================================

} catch (PDOException $e) {
    // Em produção, evite mostrar o erro detalhado na tela para o usuário final
    // Para debug pode usar: die("Erro: " . $e->getMessage());
    die("Erro crítico de conexão: O sistema não pôde se conectar ao banco de dados.");
}
?>