<?php
// edit_profile.php
// Localizado na RAIZ do projeto

session_start();

// 1. CORREÇÃO DE CAMINHOS (Define a raiz)
// __DIR__ é a pasta onde este arquivo está.
define('BASE_PATH', __DIR__);

// Tenta carregar o banco de dados (ajuste o caminho se sua pasta config estiver em outro lugar)
// Se 'config' está na raiz:
require_once BASE_PATH . '/config/database.php';

// Redireciona se não logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$pageTitle = "Meu Perfil";
$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'student';
$msg = '';

// --- 2. INCLUIR HEADER CORRETO (Sem quebrar caminhos internos) ---
// O truque é definir uma variável para os headers saberem que estão sendo chamados da raiz
$isRoot = true; 

if ($role == 'admin' || $role == 'superadmin') {
    include BASE_PATH . '/includes/admin_header.php';
} elseif ($role == 'teacher') {
    include BASE_PATH . '/includes/teacher_header.php';
} else {
    include BASE_PATH . '/includes/student_header.php';
}

// --- 3. PROCESSAMENTO DO FORMULÁRIO ---
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
    
    // Cálculo da Idade
    $age = 0;
    if (!empty($birthDate)) {
        $dob = new DateTime($birthDate);
        $now = new DateTime();
        $diff = $now->diff($dob);
        $age = $diff->y;
    }

    // Campos do Guardião
    $guardianName = ($age < 18) ? trim($_POST['guardianName']) : null;
    $guardianPhone = ($age < 18) ? trim($_POST['guardianPhone']) : null;
    $guardianEmail = ($age < 18) ? trim($_POST['guardianEmail']) : null;
    $guardianRG = ($age < 18) ? trim($_POST['guardianRG']) : null;
    $guardianCPF = ($age < 18) ? trim($_POST['guardianCPF']) : null;

    // Imagem Base64
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
        if ($profilePicture) {
            $_SESSION['user_pic'] = $profilePicture;
        }

        $msg = '<div class="alert alert-success">Perfil atualizado com sucesso!</div>';

    } catch (PDOException $e) {
        $msg = '<div class="alert alert-danger">Erro: ' . $e->getMessage() . '</div>';
    }
}

// --- 4. BUSCAR DADOS ATUAIS ---
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Erro crítico ao carregar perfil.");
}
?>

<style>
    .profile-upload-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 30px;
    }
    .profile-img-preview {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid #f1f2f6;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        margin-bottom: 15px;
        background-color: #eee;
    }
    .btn-upload-label {
        background: #3498db;
        color: white;
        padding: 8px 20px;
        border-radius: 20px;
        font-size: 0.9rem;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-upload-label:hover { background: #2980b9; }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
    }
    @media (min-width: 768px) {
        .form-grid { grid-template-columns: 1fr 1fr; }
        .span-2 { grid-column: span 2; }
    }

    #guardian-section {
        display: none; 
        background: #fff8e1;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #ffe082;
        margin-top: 20px;
    }
</style>

<div class="content-wrapper">
    <div style="margin-bottom: 20px;">
        <h2 style="margin:0; color:#2c3e50;">Editar Perfil</h2>
    </div>

    <?php echo $msg; ?>

    <div class="card-box">
        <form method="POST" action="" enctype="multipart/form-data">
            
            <div class="profile-upload-wrapper">
                <?php 
                    $displayPic = !empty($user['profilePicture']) ? $user['profilePicture'] : 'assets/img/default-user.png';
                    if(empty($user['profilePicture']) || strpos($displayPic, 'data:image') === false && !file_exists($displayPic)) {
                         $displayPic = "https://ui-avatars.com/api/?name=".urlencode($user['firstName'])."&background=random&size=128";
                    }
                ?>
                <img src="<?php echo $displayPic; ?>" id="preview" class="profile-img-preview">
                <label for="profile_pic" class="btn-upload-label"><i class="fas fa-camera"></i> Alterar Foto</label>
                <input type="file" id="profile_pic" name="profile_pic" accept="image/*" style="display: none;" onchange="previewImage(this)">
            </div>

            <h4 style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px; color: #7f8c8d;">Dados Pessoais</h4>
            
            <div class="form-grid">
                <div class="form-group"><label>Nome</label><input type="text" name="firstName" class="form-control" value="<?php echo htmlspecialchars($user['firstName']); ?>" required></div>
                <div class="form-group"><label>Sobrenome</label><input type="text" name="lastName" class="form-control" value="<?php echo htmlspecialchars($user['lastName']); ?>" required></div>
                <div class="form-group"><label>E-mail</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required></div>
                <div class="form-group"><label>Telefone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone']); ?>"></div>
                <div class="form-group"><label>CPF</label><input type="text" name="cpf" class="form-control" value="<?php echo htmlspecialchars($user['cpf']); ?>"></div>
                <div class="form-group"><label>RG</label><input type="text" name="rg" class="form-control" value="<?php echo htmlspecialchars($user['rg']); ?>"></div>
                <div class="form-group span-2"><label>Endereço</label><input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($user['address']); ?>"></div>
                <div class="form-group"><label>Data de Nascimento</label><input type="date" id="birthDate" name="birthDate" class="form-control" value="<?php echo htmlspecialchars($user['birthDate']); ?>" required></div>
                <div class="form-group"><label>Idade</label><input type="text" id="ageDisplay" class="form-control" value="<?php echo htmlspecialchars($user['age']); ?>" readonly style="background: #f9f9f9;"></div>
            </div>

            <div id="guardian-section">
                <h4 style="margin-top: 0; color: #d35400;"><i class="fas fa-user-shield"></i> Dados do Responsável</h4>
                <div class="form-grid">
                    <div class="form-group span-2"><label>Nome</label><input type="text" name="guardianName" class="form-control" value="<?php echo htmlspecialchars($user['guardianName']); ?>"></div>
                    <div class="form-group"><label>Telefone</label><input type="text" name="guardianPhone" class="form-control" value="<?php echo htmlspecialchars($user['guardianPhone']); ?>"></div>
                    <div class="form-group"><label>E-mail</label><input type="email" name="guardianEmail" class="form-control" value="<?php echo htmlspecialchars($user['guardianEmail']); ?>"></div>
                    <div class="form-group"><label>CPF</label><input type="text" name="guardianCPF" class="form-control" value="<?php echo htmlspecialchars($user['guardianCPF']); ?>"></div>
                    <div class="form-group"><label>RG</label><input type="text" name="guardianRG" class="form-control" value="<?php echo htmlspecialchars($user['guardianRG']); ?>"></div>
                </div>
            </div>

            <div style="margin-top: 30px; text-align: right;">
                <button type="submit" class="btn-save btn-primary"><i class="fas fa-save"></i> Salvar Alterações</button>
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
// Inclui footer corretamente
if ($role == 'admin' || $role == 'superadmin') {
    include BASE_PATH . '/includes/admin_footer.php';
} else {
    include BASE_PATH . '/includes/admin_footer.php'; 
}
?>