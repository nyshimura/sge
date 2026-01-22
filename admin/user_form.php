<?php
// admin/user_form.php
include '../includes/admin_header.php';

// Garante que apenas admins/superadmins acessem
checkRole(['admin', 'superadmin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $id ? "Editar Usuário" : "Novo Usuário";
$pageTitle = $action;

$firstName = '';
$lastName = '';
$email = '';
$role = 'student'; // Padrão
$phone = '';
$cpf = '';
$msg = '';

// Se for edição, busca dados
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    if (!$user) {
        echo "<div class='content-wrapper'>Usuário não encontrado.</div>";
        include '../includes/admin_footer.php';
        exit;
    }
    $firstName = $user['firstName'];
    $lastName = $user['lastName'];
    $email = $user['email'];
    $role = $user['role'];
    $phone = $user['phone'];
    $cpf = $user['cpf'];
}

// Processar Post
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firstName = cleanInput($_POST['firstName']);
    $lastName = cleanInput($_POST['lastName']);
    $email = cleanInput($_POST['email']);
    $role = cleanInput($_POST['role']);
    $phone = cleanInput($_POST['phone']);
    $cpf = cleanInput($_POST['cpf']);
    $password = $_POST['password']; 

    // 2. Gera Imagem (DiceBear) - Lógica adicionada conforme solicitado
    $seed = urlencode($firstName . ' ' . $lastName);
    $urlAvatar = "https://api.dicebear.com/9.x/adventurer/svg?seed=" . $seed;
    $svgData = @file_get_contents($urlAvatar);
    $base64Image = null;
    
    if ($svgData !== false) {
        $base64Image = 'data:image/svg+xml;base64,' . base64_encode($svgData);
    }

    if (empty($firstName) || empty($email)) {
        $msg = '<div style="color:red">Nome e Email são obrigatórios.</div>';
    } else {
        try {
            if ($id) {
                // UPDATE
                // Nota: Não atualizamos a imagem aqui para não sobrescrever uma foto personalizada que o usuário já tenha.
                $sql = "UPDATE users SET firstName = :fn, lastName = :ln, email = :em, role = :rl, phone = :ph, cpf = :cpf WHERE id = :id";
                $params = [
                    ':fn' => $firstName, ':ln' => $lastName, ':em' => $email, 
                    ':rl' => $role, ':ph' => $phone, ':cpf' => $cpf, ':id' => $id
                ];
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // Se digitou senha nova, atualiza
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtPass = $pdo->prepare("UPDATE users SET password_hash = :pass WHERE id = :id");
                    $stmtPass->execute([':pass' => $hash, ':id' => $id]);
                }
                
                $msg = '<div style="color:green; margin-bottom:15px; padding: 10px; background: #dff0d8; border-radius: 4px;">Usuário atualizado com sucesso!</div>';

            } else {
                // INSERT (Novo)
                if (empty($password)) {
                    $msg = '<div style="color:red">Senha é obrigatória para novos usuários.</div>';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Adicionado campo 'profilePicture' na query
                    $sql = "INSERT INTO users (firstName, lastName, email, password_hash, role, phone, cpf, profilePicture, created_at) 
                            VALUES (:fn, :ln, :em, :pass, :rl, :ph, :cpf, :pic, NOW())";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':fn' => $firstName, 
                        ':ln' => $lastName, 
                        ':em' => $email, 
                        ':pass' => $hash, 
                        ':rl' => $role, 
                        ':ph' => $phone, 
                        ':cpf' => $cpf,
                        ':pic' => $base64Image // Salva na coluna profilePicture
                    ]);
                    
                    $msg = '<div style="color:green; margin-bottom:15px; padding: 10px; background: #dff0d8; border-radius: 4px;">Usuário criado com sucesso! <a href="users.php">Voltar para lista</a></div>';
                    $id = $pdo->lastInsertId(); 
                }
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { 
                $msg = '<div style="color:red">Este e-mail já está cadastrado.</div>';
            } else {
                $msg = '<div style="color:red">Erro: ' . $e->getMessage() . '</div>';
            }
        }
    }
}
?>

<div class="card-box">
    <?php echo $msg; ?>
    
    <form method="POST" action="">
        <div style="display: flex; gap: 20px;">
            <div class="form-group" style="flex: 1;">
                <label>Nome *</label>
                <input type="text" name="firstName" class="form-control" value="<?php echo htmlspecialchars($firstName); ?>" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label>Sobrenome</label>
                <input type="text" name="lastName" class="form-control" value="<?php echo htmlspecialchars($lastName); ?>">
            </div>
        </div>

        <div style="display: flex; gap: 20px;">
            <div class="form-group" style="flex: 2;">
                <label>E-mail *</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label>Perfil de Acesso</label>
                <select name="role" class="form-control">
                    <option value="student" <?php echo $role == 'student' ? 'selected' : ''; ?>>Aluno</option>
                    <option value="teacher" <?php echo $role == 'teacher' ? 'selected' : ''; ?>>Professor</option>
                    <option value="admin" <?php echo $role == 'admin' ? 'selected' : ''; ?>>Administrador</option>
                    <option value="superadmin" <?php echo $role == 'superadmin' ? 'selected' : ''; ?>>Super Administrador</option>
                </select>
            </div>
        </div>

        <div style="display: flex; gap: 20px;">
            <div class="form-group" style="flex: 1;">
                <label>CPF</label>
                <input type="text" name="cpf" class="form-control" value="<?php echo htmlspecialchars($cpf); ?>">
            </div>
            <div class="form-group" style="flex: 1;">
                <label>Telefone</label>
                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($phone); ?>">
            </div>
        </div>

        <div class="form-group" style="background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #eee;">
            <label>Senha <?php echo $id ? '(Deixe em branco para manter a atual)' : '*'; ?></label>
            <input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="Digite apenas se quiser alterar ou criar">
        </div>

        <div style="margin-top: 20px;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Salvar Usuário</button>
            <a href="users.php" style="margin-left: 15px; color: #666; text-decoration: none;">Cancelar</a>
        </div>
    </form>
</div>

<?php include '../includes/admin_footer.php'; ?>
