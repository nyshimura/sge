<?php
// dashboard.php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Obriga login
checkLogin();

$nome = $_SESSION['user_name'];
$tipo = $_SESSION['user_type'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SGE</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background: #f4f6f9; color: #333; }
        header { background: #343a40; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .container { padding: 2rem; max-width: 1200px; margin: 0 auto; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .logout-btn { color: #ff6b6b; text-decoration: none; font-weight: bold; border: 1px solid #ff6b6b; padding: 5px 15px; border-radius: 4px; transition: all 0.3s; }
        .logout-btn:hover { background: #ff6b6b; color: white; }
        
        .btn-action { 
            display: inline-block; 
            padding: 10px 20px; 
            background-color: #007bff; 
            color: white; 
            text-decoration: none; 
            border-radius: 5px; 
            font-weight: 500;
            margin-top: 10px;
        }
        .btn-action:hover { background-color: #0056b3; }
    </style>
</head>
<body>

<header>
    <div style="font-weight: bold; font-size: 1.2rem;">SGE - <?php echo ucfirst($tipo); ?></div>
    <div>
        Olá, <?php echo htmlspecialchars($nome); ?> &nbsp;
        <a href="logout.php" class="logout-btn">Sair</a>
    </div>
</header>

<div class="container">
    <div class="card">
        <h3>Bem-vindo ao Painel</h3>
        <p>Você está logado como: <strong><?php echo $tipo; ?></strong></p>
    </div>

    <?php if ($tipo === 'admin' || $tipo === 'superadmin'): ?>
        <div class="card" style="border-left: 5px solid #007bff;">
            <h4>Administração do Sistema</h4>
            <p>Acesse o painel completo para gerenciar usuários, cursos e financeiro.</p>
            <a href="admin/index.php" class="btn-action">Acessar Painel Admin</a>
        </div>

    <?php elseif ($tipo === 'student'): ?>
        <div class="card" style="border-left: 5px solid #28a745;">
            <h4>Área do Aluno</h4>
            <p>Acesse seus cursos, certificados e realize matrículas.</p>
            <a href="student/index.php" class="btn-action">Acessar Meus Cursos</a>
        </div>

    <?php elseif ($tipo === 'teacher'): ?>
        <div class="card" style="border-left: 5px solid #ffc107;">
            <h4>Área do Professor</h4>
            <p>Gerencie suas turmas e realize chamadas.</p>
            <a href="teacher/index.php" class="btn-action">Acessar Minhas Turmas</a>
        </div>
    <?php endif; ?>

</div>

</body>
</html>