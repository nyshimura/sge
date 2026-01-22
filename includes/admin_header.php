<?php
// includes/admin_header.php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Garante que está logado
checkLogin();

// Permite 'admin' E 'superadmin' acessarem o painel
checkRole(['admin', 'superadmin']);

// Busca configurações básicas (Nome da Escola)
try {
    $stmtSettings = $pdo->query("SELECT name FROM school_profile WHERE id = 1");
    $schoolSettings = $stmtSettings->fetch();
    $sysName = $schoolSettings['name'] ?? 'SGE System';
} catch (Exception $e) {
    $sysName = 'SGE System';
}

// Define a imagem de perfil (Sessão ou Padrão)
$userPic = isset($_SESSION['user_pic']) && !empty($_SESSION['user_pic']) 
    ? $_SESSION['user_pic'] 
    : '../assets/img/default-user.png'; // Caminho para uma imagem padrão, ou use URL externa se preferir

// Se a imagem da sessão não for base64 e não existir arquivo, usa fallback
if (strpos($userPic, 'data:image') === false && !file_exists($userPic)) {
    // Fallback: Avatar gerado por iniciais
    $userPic = "https://ui-avatars.com/api/?name=".urlencode($_SESSION['user_name'])."&background=random&color=fff";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?php echo htmlspecialchars($sysName); ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=1.2"> 
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    
    <?php include 'admin_sidebar.php'; ?>

    <div class="main-content">
        
        <div class="top-bar" style="display:flex; justify-content:space-between; align-items:center;">
            <h2 style="margin:0; font-size:1.1rem; font-weight:600; color:#333; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width: 60%;">
                <?php echo isset($pageTitle) ? $pageTitle : 'Painel Administrativo'; ?>
            </h2>
            
            <div class="user-info" style="display:flex; align-items:center;">
                <a href="../profile/index.php" class="user-profile-link" title="Meu Perfil">
                    <span class="header-user-name">
                        Olá, <strong><?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]); // Mostra só o primeiro nome ?></strong>
                    </span>
                    <img src="<?php echo $userPic; ?>" alt="Perfil" class="header-avatar">
                </a>

                <a href="../logout.php" class="header-logout" title="Sair">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
        
        <div class="content-wrapper">