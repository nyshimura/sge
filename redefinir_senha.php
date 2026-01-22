<?php
// redefinir_senha.php
require_once 'config/database.php';

$msg = "";
$token = isset($_GET['token']) ? $_GET['token'] : '';
$validToken = false;
$userId = null;

// 1. Verifica se o token é válido e não expirou
if (!empty($token)) {
    // Usando as colunas vistas na imagem do banco: reset_token e reset_token_expires_at
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_token_expires_at > NOW() LIMIT 1");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $validToken = true;
        $userId = $user['id'];
    } else {
        $msg = '<div class="alert alert-danger">Este link é inválido ou expirou. Solicite um novo.</div>';
    }
} else {
    $msg = '<div class="alert alert-danger">Link inválido (Token não fornecido).</div>';
}

// 2. Processa a troca de senha
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $validToken) {
    $pass1 = $_POST['pass1'];
    $pass2 = $_POST['pass2'];

    if (strlen($pass1) < 6) {
        $msg = '<div class="alert alert-danger">A senha deve ter pelo menos 6 caracteres.</div>';
    } elseif ($pass1 !== $pass2) {
        $msg = '<div class="alert alert-danger">As senhas não conferem.</div>';
    } else {
        // Hash da nova senha
        $newHash = password_hash($pass1, PASSWORD_DEFAULT);

        // Atualiza a senha e LIMPA o token
        // Atualiza a coluna password_hash conforme sua estrutura
        $update = $pdo->prepare("UPDATE users SET password_hash = :hash, reset_token = NULL, reset_token_expires_at = NULL WHERE id = :id");
        
        if ($update->execute([':hash' => $newHash, ':id' => $userId])) {
            $msg = '<div class="alert alert-success">Senha alterada com sucesso! <a href="login.php">Clique aqui para entrar</a>.</div>';
            $validToken = false; // Esconde o formulário para obrigar o login
        } else {
            $msg = '<div class="alert alert-danger">Erro ao salvar nova senha no banco de dados.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Redefinir Senha</title>
    <style>
        body { background: #f1f2f6; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
        .form-control { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn { background: #27ae60; color: white; border: none; padding: 12px; width: 100%; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        .btn:hover { background: #219150; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; font-size: 0.9rem; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        a { color: #1e3c72; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Nova Senha</h2>
        
        <?php echo $msg; ?>

        <?php if ($validToken): ?>
            <form method="POST">
                <input type="password" name="pass1" class="form-control" placeholder="Nova Senha (min 6 chars)" required>
                <input type="password" name="pass2" class="form-control" placeholder="Confirme a Nova Senha" required>
                <button type="submit" class="btn">Salvar Nova Senha</button>
            </form>
        <?php endif; ?>
        
        <?php if (!$validToken && strpos($msg, 'sucesso') === false): ?>
            <div style="margin-top: 20px;">
                <a href="recuperar_senha.php">Solicitar novo link</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>