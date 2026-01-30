<?php
// includes/teacher_header.php
require_once '../config/database.php';
require_once '../includes/functions.php';

checkLogin();
checkRole(['teacher']); // Garante que é professor

// 1. Busca Nome da Escola (Para o Título da Aba)
try {
    $stmtSettings = $pdo->query("SELECT name FROM school_profile WHERE id = 1");
    $school = $stmtSettings->fetch();
    $sysName = $school['name'] ?? 'Área do Professor';
} catch (Exception $e) {
    $sysName = 'Área do Professor';
}

// 2. Lógica da Imagem de Perfil
$userPic = isset($_SESSION['user_pic']) && !empty($_SESSION['user_pic']) 
    ? $_SESSION['user_pic'] 
    : '../assets/img/default-user.png'; 

// Fallback se a imagem não existir fisicamente e não for base64
if (strpos($userPic, 'data:image') === false && !file_exists($userPic)) {
    $userPic = "https://ui-avatars.com/api/?name=".urlencode($_SESSION['user_name'])."&background=random&color=fff";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professor - <?php echo htmlspecialchars($sysName); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher.css?v=1.1"> 
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    
    <?php include 'teacher_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-bar" style="display:flex; justify-content:space-between; align-items:center;">
            
            <h2 style="margin:0; font-size:1.1rem; font-weight:600; color:#333; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width: 60%;">
                <?php echo isset($pageTitle) ? $pageTitle : 'Painel do Professor'; ?>
            </h2>
            
            <div class="user-info">
                
                <a href="../teacher/profile.php" class="user-profile-link" title="Meu Perfil">
                    <span class="header-user-name">
                        Olá, <strong><?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]); ?></strong>
                    </span>
                    <img src="<?php echo $userPic; ?>" alt="Perfil" class="header-avatar">
                </a>

                <a href="../logout.php" class="header-logout" title="Sair">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
        
        <div class="content-wrapper">

            <div class="page-container">
