<?php
// includes/teacher_sidebar.php

// 1. Busca dados da Escola (Nome e Logo)
$schoolName = 'Área do Professor'; // Título Padrão
$schoolLogo = '';

try {
    // Verifica se a conexão $pdo existe
    if (isset($pdo)) {
        $stmtProfile = $pdo->query("SELECT name, profilePicture FROM school_profile WHERE id = 1 LIMIT 1");
        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC);
        
        if ($profileData) {
            $schoolName = $profileData['name'];
            $schoolLogo = $profileData['profilePicture'];
        }
    }
} catch (Exception $e) {
    // Silencia erro e usa padrão
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar">
    <div class="sidebar-header">
        <?php if (!empty($schoolLogo)): ?>
            <img src="<?php echo $schoolLogo; ?>" alt="Logo" class="sidebar-logo-img">
        <?php else: ?>
            <i class="fas fa-chalkboard-teacher"></i> 
        <?php endif; ?>
        
        <span class="school-name-text"><?php echo htmlspecialchars($schoolName); ?></span>
    </div>

    <ul class="sidebar-menu">
        <li>
            <a href="index.php" class="<?php echo $currentPage == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> <span>Início</span>
            </a>
        </li>
        <li>
            <a href="attendance.php" class="<?php echo $currentPage == 'attendance.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-check"></i> <span>Chamada</span>
            </a>
        </li>
        <li>
            <a href="financial.php" class="<?php echo $currentPage == 'financial.php' ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i> <span>Financeiro</span>
            </a>
        </li>
        
        <li class="mobile-logout">
            <a href="../logout.php">
                <i class="fas fa-sign-out-alt"></i> <span>Sair</span>
            </a>
        </li>
    </ul>
</div>

<style>
    /* Estilos da Logo e Texto */
    .sidebar-logo-img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 10px;
        background-color: #fff;
        border: 2px solid rgba(255,255,255,0.2);
    }

    .school-name-text {
        font-size: 1rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 180px;
    }

    .sidebar-header {
        display: flex;
        align-items: center;
        padding: 20px;
        overflow: hidden; 
    }

    /* --- REGRA MOBILE: Esconde o Header no celular --- */
    @media (max-width: 768px) {
        .sidebar-header {
            display: none !important; /* Força o desaparecimento no mobile */
        }
    }
</style>