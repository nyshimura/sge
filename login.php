<?php
// login.php
session_start();
require_once 'config/database.php';

// --- 1. BUSCAR DADOS DA ESCOLA ---
$schoolName = 'Acesso ao Sistema';
$schoolLogo = '';
try {
    if (isset($pdo)) {
        $stmtProfile = $pdo->query("SELECT name, profilePicture FROM school_profile WHERE id = 1 LIMIT 1");
        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC);
        if ($profileData) {
            $schoolName = $profileData['name'];
            $schoolLogo = $profileData['profilePicture'];
        }
    }
} catch (Exception $e) {}

$msg = "";

if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    redirectUser($_SESSION['user_role']);
}

// --- 2. PROCESSAMENTO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $action = $_POST['action'] ?? 'login';

    // >>>>> AÇÃO DE CADASTRO <<<<<
    if ($action == 'register') {
        $firstName = trim($_POST['firstName']);
        $lastName = trim($_POST['lastName']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        $birthDate = $_POST['birthDate']; // Novo Campo
        
        if (!empty($firstName) && !empty($lastName) && !empty($email) && !empty($password) && !empty($birthDate)) {
            try {
                // 1. Verifica duplicidade
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmtCheck->execute([':email' => $email]);
                
                if ($stmtCheck->rowCount() > 0) {
                    $msg = '<div class="alert-error">Este e-mail já está cadastrado.</div>';
                } else {
                    // 2. Gera Imagem (DiceBear)
                    $seed = urlencode($firstName . ' ' . $lastName);
                    $urlAvatar = "https://api.dicebear.com/9.x/adventurer/svg?seed=" . $seed;
                    $svgData = @file_get_contents($urlAvatar);
                    $base64Image = null;
                    if ($svgData !== false) {
                        $base64Image = 'data:image/svg+xml;base64,' . base64_encode($svgData);
                    }

                    // 3. Calcula Idade (Importante para lógica de menor de idade)
                    $age = 0;
                    if (!empty($birthDate)) {
                        $dob = new DateTime($birthDate);
                        $now = new DateTime();
                        $age = $now->diff($dob)->y;
                    }

                    // 4. Hash e Insert
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    // Adicionado birthDate e age no INSERT
                    $sqlInsert = "INSERT INTO users (firstName, lastName, email, password_hash, birthDate, age, role, profilePicture, created_at) 
                                  VALUES (:fn, :ln, :em, :pass, :bd, :age, 'student', :pic, NOW())";
                    
                    $stmt = $pdo->prepare($sqlInsert);
                    $stmt->execute([
                        ':fn' => $firstName,
                        ':ln' => $lastName,
                        ':em' => $email,
                        ':pass' => $hash,
                        ':bd' => $birthDate,
                        ':age' => $age,
                        ':pic' => $base64Image
                    ]);

                    $msg = '<div class="alert-success">Conta criada com sucesso! Faça login.</div>';
                }
            } catch (PDOException $e) {
                $msg = '<div class="alert-error">Erro ao cadastrar: ' . $e->getMessage() . '</div>';
            }
        } else {
            $msg = '<div class="alert-error">Preencha todos os campos.</div>';
        }
    }
    
    // >>>>> AÇÃO DE LOGIN <<<<<
    elseif ($action == 'login') {
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);

        if (!empty($email) && !empty($password)) {
            try {
                $sql = "SELECT id, firstName, lastName, password_hash, role, profilePicture FROM users WHERE email = :email LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password_hash'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['firstName'] . ' ' . $user['lastName'];
                    $_SESSION['user_role'] = trim($user['role']); 
                    $_SESSION['user_pic']  = $user['profilePicture'];
                    redirectUser($user['role']);
                } else {
                    $msg = '<div class="alert-error">E-mail ou senha incorretos.</div>';
                }
            } catch (PDOException $e) {
                $msg = '<div class="alert-error">Erro no sistema.</div>';
            }
        }
    }
}

function redirectUser($role) {
    $role = trim(strtolower($role));
    switch ($role) {
        case 'admin': case 'superadmin': header("Location: admin/index.php"); break;
        case 'teacher': header("Location: teacher/index.php"); break;
        default: header("Location: student/index.php"); break;
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso - <?php echo htmlspecialchars($schoolName); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0; padding: 0;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            width: 100%; max-width: 400px;
            text-align: center;
            margin: 20px;
            position: relative;
            overflow: hidden;
        }
        .login-logo { margin-bottom: 20px; display: flex; justify-content: center; align-items: center; }
        .login-logo i { font-size: 3rem; color: #1e3c72; }
        .school-logo-img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 4px solid #f0f0f0; }
        
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; margin-bottom: 5px; color: #555; font-weight: 600; font-size: 0.9rem; }
        .form-control {
            width: 100%; padding: 12px; border: 1px solid #ddd;
            border-radius: 6px; box-sizing: border-box; font-size: 1rem;
            transition: 0.3s;
        }
        .form-control:focus { border-color: #1e3c72; outline: none; box-shadow: 0 0 0 3px rgba(30, 60, 114, 0.1); }
        
        .btn-login {
            background: #1e3c72; color: white; border: none;
            width: 100%; padding: 12px; border-radius: 6px;
            font-size: 1.1rem; font-weight: bold; cursor: pointer;
            transition: 0.3s; margin-top: 10px;
        }
        .btn-login:hover { background: #162c55; }
        
        .alert-error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem; border: 1px solid #ffcdd2; }
        .alert-success { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem; border: 1px solid #c8e6c9; }
        
        .toggle-link {
            margin-top: 20px; font-size: 0.9rem; color: #666;
        }
        .toggle-link span {
            color: #1e3c72; cursor: pointer; font-weight: bold; text-decoration: underline;
        }

        .form-container { display: none; animation: fadeIn 0.5s; }
        .form-container.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .row-inputs { display: flex; gap: 10px; }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-logo">
            <?php if (!empty($schoolLogo)): ?>
                <img src="<?php echo $schoolLogo; ?>" alt="Logo" class="school-logo-img">
            <?php else: ?>
                <i class="fas fa-graduation-cap"></i>
            <?php endif; ?>
        </div>
        
        <h2 style="color: #333; margin-bottom: 10px; margin-top: 0; font-size: 1.5rem;">
            <?php echo htmlspecialchars($schoolName); ?>
        </h2>
        
        <?php echo $msg; ?>

        <div id="login-form" class="form-container active">
            <p style="color: #7f8c8d; margin-bottom: 20px; font-size: 0.9rem;">Área Restrita - Faça Login</p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>E-mail</label>
                    <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="password" class="form-control" placeholder="********" required>
                </div>
                <button type="submit" class="btn-login">Entrar</button>
            </form>
            <div class="toggle-link">
                Não tem uma conta? <span onclick="showRegister()">Cadastre-se</span>
            </div>
            <div style="margin-top:10px; font-size:0.85rem;">
                <a href="recuperar_senha.php" style="color:#666; text-decoration:none;">Esqueceu a senha?</a>
            </div>
        </div>

        <div id="register-form" class="form-container">
            <p style="color: #7f8c8d; margin-bottom: 20px; font-size: 0.9rem;">Criar Nova Conta</p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="register">
                
                <div class="row-inputs">
                    <div class="form-group">
                        <label>Nome</label>
                        <input type="text" name="firstName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Sobrenome</label>
                        <input type="text" name="lastName" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Data de Nascimento</label>
                    <input type="date" name="birthDate" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>E-mail</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn-login" style="background-color: #27ae60;">Criar Conta</button>
            </form>
            <div class="toggle-link">
                Já tem conta? <span onclick="showLogin()">Fazer Login</span>
            </div>
        </div>

    </div>

    <script>
        function showRegister() {
            document.getElementById('login-form').classList.remove('active');
            document.getElementById('register-form').classList.add('active');
        }
        function showLogin() {
            document.getElementById('register-form').classList.remove('active');
            document.getElementById('login-form').classList.add('active');
        }
    </script>

</body>
</html>