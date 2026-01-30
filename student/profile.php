<?php
// profile/index.php (Deve ficar dentro da pasta "profile" na raiz)
session_start();

// 1. Configuração de Caminhos e Banco
// Como estamos em /profile/, subimos um nível para acessar config
require_once '../config/database.php';

// Redireciona se não logado
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$pageTitle = "Meu Perfil";
$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'student';
$msg = '';

// --- 2. Processamento do Formulário ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Sanitização
    $firstName = trim($_POST['firstName']);
    $lastName = trim($_POST['lastName']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $rg = trim($_POST['rg']);
    $cpf = trim($_POST['cpf']);
    $address = trim($_POST['address']);
    $birthDate = $_POST['birthDate'];
    
    // Calcula Idade
    $age = 0;
    if (!empty($birthDate)) {
        $dob = new DateTime($birthDate);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
    }

    // Campos do Guardião (Só se for menor)
    $guardianName = ($age < 18) ? trim($_POST['guardianName']) : null;
    $guardianPhone = ($age < 18) ? trim($_POST['guardianPhone']) : null;
    $guardianEmail = ($age < 18) ? trim($_POST['guardianEmail']) : null;
    $guardianRG = ($age < 18) ? trim($_POST['guardianRG']) : null;
    $guardianCPF = ($age < 18) ? trim($_POST['guardianCPF']) : null;

    // Processamento de Imagem
    $profilePicture = null;
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $data = file_get_contents($_FILES['profile_pic']['tmp_name']);
            $profilePicture = 'data:image/' . $ext . ';base64,' . base64_encode($data);
        }
    }

    try {
        // Query Dinâmica
        $sql = "UPDATE users SET 
                firstName = :fn, lastName = :ln, email = :em, phone = :ph, 
                rg = :rg, cpf = :cpf, address = :addr, 
                birthDate = :bd, age = :age,
                guardianName = :gn, guardianPhone = :gp, guardianEmail = :ge, guardianRG = :grg, guardianCPF = :gcpf";
        
        $params = [
            ':fn' => $firstName, ':ln' => $lastName, ':em' => $email, ':ph' => $phone,
            ':rg' => $rg, ':cpf' => $cpf, ':addr' => $address,
            ':bd' => $birthDate, ':age' => $age,
            ':gn' => $guardianName, ':gp' => $guardianPhone, ':ge' => $guardianEmail, ':grg' => $guardianRG, ':gcpf' => $guardianCPF,
            ':uid' => $userId
        ];

        if ($profilePicture) {
            $sql .= ", profilePicture = :pic";
            $params[':pic'] = $profilePicture;
        }

        $sql .= " WHERE id = :uid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Atualiza Sessão
        $_SESSION['user_name'] = $firstName . ' ' . $lastName;
        if ($profilePicture) $_SESSION['user_pic'] = $profilePicture;

        $msg = '<div class="alert alert-success">Perfil atualizado com sucesso!</div>';

    } catch (PDOException $e) {
        $msg = '<div class="alert alert-danger">Erro: ' . $e->getMessage() . '</div>';
    }
}

// --- 3. Buscar Dados do Usuário ---
$user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch(PDO::FETCH_ASSOC);

// --- 4. Incluir o Header Correto (Baseado na Role) ---
if ($role == 'admin' || $role == 'superadmin') {
    include '../includes/admin_header.php';
} elseif ($role == 'teacher') {
    include '../includes/teacher_header.php';
} else {
    include '../includes/student_header.php';
}
?>

<div class="page-container">
    
    <style>
        /* Estilo do Card Principal */
        .card-box-profile {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
        }

        /* Upload de Imagem Centralizado */
        .profile-upload-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 35px;
            padding-bottom: 25px;
            border-bottom: 1px solid #eee;
        }
        .profile-img-preview {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #fff;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            background-color: #eee;
        }
        .btn-upload-label {
            background: #3498db; /* Cor padrão, pode ajustar */
            color: white;
            padding: 8px 25px;
            border-radius: 30px;
            font-size: 0.95rem;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 5px rgba(52, 152, 219, 0.3);
        }
        .btn-upload-label:hover { background: #2980b9; transform: translateY(-2px); }

        /* Formulários e Grid */
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        .form-control-profile {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            color: #333;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .form-control-profile:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        .form-control-profile[readonly] { background-color: #f9f9f9; color: #888; cursor: not-allowed; }

        /* Grid Responsivo */
        .form-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        @media (min-width: 768px) {
            .form-grid { grid-template-columns: 1fr 1fr; }
            .span-2 { grid-column: span 2; }
        }

        /* Seção do Responsável */
        #guardian-section {
            display: none; 
            background: #fff8e1;
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #ffe082;
            margin-top: 25px;
        }

        /* Botão Salvar */
        .btn-save-profile {
            background-color: #27ae60;
            color: white;
            padding: 12px 35px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-save-profile:hover { background-color: #219150; }
        
        /* Alertas */
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>

    <div style="margin-bottom: 25px;">
        <h2 style="margin:0; color:#2c3e50; font-size: 1.5rem;">Editar Meu Perfil</h2>
    </div>

    <?php echo $msg; ?>

    <div class="card-box-profile">
        <form method="POST" action="" enctype="multipart/form-data">
            
            <div class="profile-upload-wrapper">
                <?php 
                    $displayPic = !empty($user['profilePicture']) ? $user['profilePicture'] : '../assets/img/default-user.png';
                    // Fallback inteligente
                    if(empty($user['profilePicture']) || (strpos($displayPic, 'data:image') === false && !file_exists($displayPic))) {
                         $displayPic = "https://ui-avatars.com/api/?name=".urlencode($user['firstName'])."&background=random&size=140&color=fff";
                    }
                ?>
                <img src="<?php echo $displayPic; ?>" id="preview" class="profile-img-preview">
                <label for="profile_pic" class="btn-upload-label"><i class="fas fa-camera"></i> Alterar Foto</label>
                <input type="file" id="profile_pic" name="profile_pic" accept="image/*" style="display: none;" onchange="previewImage(this)">
            </div>

            <h4 style="border-bottom: 2px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 25px; color: #34495e; font-size: 1.2rem;">Dados Pessoais</h4>
            
            <div class="form-grid">
                <div class="form-group"><label>Nome</label><input type="text" name="firstName" class="form-control-profile" value="<?php echo htmlspecialchars($user['firstName']); ?>" required></div>
                <div class="form-group"><label>Sobrenome</label><input type="text" name="lastName" class="form-control-profile" value="<?php echo htmlspecialchars($user['lastName']); ?>" required></div>
                <div class="form-group"><label>E-mail</label><input type="email" name="email" class="form-control-profile" value="<?php echo htmlspecialchars($user['email']); ?>" required></div>
                <div class="form-group"><label>Telefone / WhatsApp</label><input type="text" name="phone" class="form-control-profile" value="<?php echo htmlspecialchars($user['phone']); ?>"></div>
                <div class="form-group"><label>CPF</label><input type="text" name="cpf" class="form-control-profile" value="<?php echo htmlspecialchars($user['cpf']); ?>"></div>
                <div class="form-group"><label>RG</label><input type="text" name="rg" class="form-control-profile" value="<?php echo htmlspecialchars($user['rg']); ?>"></div>
                <div class="form-group span-2"><label>Endereço Completo</label><input type="text" name="address" class="form-control-profile" value="<?php echo htmlspecialchars($user['address']); ?>"></div>
                <div class="form-group"><label>Data de Nascimento</label><input type="date" id="birthDate" name="birthDate" class="form-control-profile" value="<?php echo htmlspecialchars($user['birthDate']); ?>" required></div>
                <div class="form-group"><label>Idade Atual</label><input type="text" id="ageDisplay" class="form-control-profile" value="<?php echo htmlspecialchars($user['age']); ?>" readonly></div>
            </div>

            <div id="guardian-section">
                <h4 style="margin-top: 0; color: #d35400; display: flex; align-items: center; gap: 10px; font-size: 1.2rem;">
                    <i class="fas fa-user-shield"></i> Dados do Responsável
                </h4>
                <p style="color: #c0392b; margin-bottom: 20px;">Obrigatório para menores de 18 anos.</p>
                <div class="form-grid">
                    <div class="form-group span-2"><label>Nome Completo do Responsável</label><input type="text" name="guardianName" class="form-control-profile" value="<?php echo htmlspecialchars($user['guardianName']); ?>"></div>
                    <div class="form-group"><label>Telefone</label><input type="text" name="guardianPhone" class="form-control-profile" value="<?php echo htmlspecialchars($user['guardianPhone']); ?>"></div>
                    <div class="form-group"><label>E-mail</label><input type="email" name="guardianEmail" class="form-control-profile" value="<?php echo htmlspecialchars($user['guardianEmail']); ?>"></div>
                    <div class="form-group"><label>CPF</label><input type="text" name="guardianCPF" class="form-control-profile" value="<?php echo htmlspecialchars($user['guardianCPF']); ?>"></div>
                    <div class="form-group"><label>RG</label><input type="text" name="guardianRG" class="form-control-profile" value="<?php echo htmlspecialchars($user['guardianRG']); ?>"></div>
                </div>
            </div>

            <div style="margin-top: 35px; text-align: right;">
                <button type="submit" class="btn-save-profile"><i class="fas fa-save"></i> Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
    function previewImage(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) { document.getElementById('preview').src = e.target.result; }
            reader.readAsDataURL(input.files[0]);
        }
    }

    const birthInput = document.getElementById('birthDate');
    const ageInput = document.getElementById('ageDisplay');
    const guardianSection = document.getElementById('guardian-section');

    function calculateAge(dateString) {
        if(!dateString) return 0;
        const today = new Date();
        const birthDate = new Date(dateString);
        let age = today.getFullYear() - birthDate.getFullYear();
        const m = today.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
        return age;
    }

    function updateGuardianVisibility() {
        const age = calculateAge(birthInput.value);
        ageInput.value = age + (age === 1 ? ' ano' : ' anos');
        guardianSection.style.display = (age < 18) ? 'block' : 'none';
    }

    birthInput.addEventListener('change', updateGuardianVisibility);
    birthInput.addEventListener('keyup', updateGuardianVisibility);
    document.addEventListener('DOMContentLoaded', updateGuardianVisibility);
</script>

<?php 
// Footer
include '../includes/admin_footer.php'; 
?>
