<?php
// admin/school_profile.php
$pageTitle = "Configurações da Unidade";
include '../includes/admin_header.php';

checkRole(['admin', 'superadmin']);

// --- PROCESSAMENTO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = cleanInput($_POST['name']);
        $cnpj = cleanInput($_POST['cnpj']);
        $phone = cleanInput($_POST['phone']);
        $address = cleanInput($_POST['address']);
        $city = cleanInput($_POST['schoolCity']);
        $state = cleanInput($_POST['state']);
        $pixType = cleanInput($_POST['pixKeyType']);
        $pixKey = cleanInput($_POST['pixKey']);

        // Upload Logo
        $logoBase64 = $_POST['existingLogo']; 
        if (!empty($_FILES['profilePicture']['tmp_name'])) {
            $data = file_get_contents($_FILES['profilePicture']['tmp_name']);
            $mime = mime_content_type($_FILES['profilePicture']['tmp_name']);
            $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($data);
        }

        // Upload Assinatura
        $sigBase64 = $_POST['existingSig']; 
        if (!empty($_FILES['signatureImage']['tmp_name'])) {
            $data = file_get_contents($_FILES['signatureImage']['tmp_name']);
            $mime = mime_content_type($_FILES['signatureImage']['tmp_name']);
            $sigBase64 = 'data:' . $mime . ';base64,' . base64_encode($data);
        }

        $pdo->beginTransaction();

        $check = $pdo->query("SELECT id FROM school_profile WHERE id = 1");
        
        if ($check->rowCount() > 0) {
            $sql = "UPDATE school_profile SET name=:name, cnpj=:cnpj, phone=:phone, address=:addr, schoolCity=:city, state=:state, pixKeyType=:pType, pixKey=:pKey, profilePicture=:logo, signatureImage=:sig WHERE id = 1";
        } else {
            $sql = "INSERT INTO school_profile (id, name, cnpj, phone, address, schoolCity, state, pixKeyType, pixKey, profilePicture, signatureImage) VALUES (1, :name, :cnpj, :phone, :addr, :city, :state, :pType, :pKey, :logo, :sig)";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':name'=>$name, ':cnpj'=>$cnpj, ':phone'=>$phone, ':addr'=>$address, ':city'=>$city, ':state'=>$state, ':pType'=>$pixType, ':pKey'=>$pixKey, ':logo'=>$logoBase64, ':sig'=>$sigBase64]);

        $pdo->commit();
        $msgType = "success";
        $msgContent = "Dados da unidade atualizados com sucesso!";

    } catch (Exception $e) {
        $pdo->rollBack();
        $msgType = "danger";
        $msgContent = "Erro ao salvar: " . $e->getMessage();
    }
}

// --- BUSCAR DADOS ---
$school = $pdo->query("SELECT * FROM school_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$school) {
    $school = ['name'=>'', 'cnpj'=>'', 'phone'=>'', 'address'=>'', 'schoolCity'=>'', 'state'=>'', 'pixKeyType'=>'', 'pixKey'=>'', 'profilePicture'=>'', 'signatureImage'=>''];
}
?>

<div class="content-wrapper">
    <div style="max-width: 1000px; margin: 0 auto; padding-top: 20px; padding-bottom: 50px;">
        
        <div class="profile-header">
            <div class="page-title">
                <h2>Configurações da Unidade</h2>
                <p>Gerencie a identidade, endereço e dados financeiros da escola.</p>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </div>
        </div>

        <?php if (isset($msgType)): ?>
            <div class="alert-float alert-<?php echo $msgType; ?>">
                <i class="fas fa-<?php echo $msgType == 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo $msgContent; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            
            <div class="ux-card">
                <div class="card-heading"><i class="fas fa-id-card"></i> Identidade Visual</div>
                
                <div class="form-grid">
                    <div class="span-4">
                        <label>Logotipo da Escola</label>
                        <div class="upload-area" id="logoUploadBox">
                            <?php 
                                $logoSrc = !empty($school['profilePicture']) ? $school['profilePicture'] : 'https://via.placeholder.com/150?text=Logo'; 
                            ?>
                            <img src="<?php echo $logoSrc; ?>" id="logoPreview" class="preview-img">
                            <span class="upload-label"><i class="fas fa-cloud-upload-alt"></i> Clique para alterar</span>
                            <input type="file" name="profilePicture" class="file-input" accept="image/*" onchange="previewImage(this, 'logoPreview')">
                            <input type="hidden" name="existingLogo" value="<?php echo $school['profilePicture']; ?>">
                        </div>
                    </div>

                    <div class="span-8">
                        <div class="form-grid">
                            <div class="span-12 form-group">
                                <label>Nome da Instituição</label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($school['name']); ?>" required>
                            </div>
                            <div class="span-6 form-group">
                                <label>CNPJ</label>
                                <input type="text" name="cnpj" class="form-control" value="<?php echo htmlspecialchars($school['cnpj']); ?>" placeholder="00.000.000/0000-00">
                            </div>
                            <div class="span-6 form-group">
                                <label>Telefone / WhatsApp</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($school['phone']); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ux-card">
                <div class="card-heading"><i class="fas fa-map-marker-alt"></i> Localização</div>
                <div class="form-grid">
                    <div class="span-12 form-group">
                        <label>Endereço Completo</label>
                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($school['address']); ?>">
                    </div>
                    <div class="span-8 form-group">
                        <label>Cidade</label>
                        <input type="text" name="schoolCity" class="form-control" value="<?php echo htmlspecialchars($school['schoolCity']); ?>">
                    </div>
                    <div class="span-4 form-group">
                        <label>Estado</label>
                        <select name="state" class="form-control">
                            <option value="">UF</option>
                            <?php 
                            $estados = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                            foreach($estados as $uf) {
                                $sel = ($school['state'] == $uf) ? 'selected' : '';
                                echo "<option value='$uf' $sel>$uf</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-grid">
                <div class="span-6">
                    <div class="ux-card" style="height: 100%;">
                        <div class="card-heading"><i class="fas fa-wallet"></i> Dados Bancários (Pix)</div>
                        <div class="form-group">
                            <label>Tipo de Chave</label>
                            <select name="pixKeyType" class="form-control">
                                <option value="">Selecione...</option>
                                <option value="CNPJ" <?php echo ($school['pixKeyType'] == 'CNPJ') ? 'selected' : ''; ?>>CNPJ</option>
                                <option value="CPF" <?php echo ($school['pixKeyType'] == 'CPF') ? 'selected' : ''; ?>>CPF</option>
                                <option value="Email" <?php echo ($school['pixKeyType'] == 'Email') ? 'selected' : ''; ?>>E-mail</option>
                                <option value="Telefone" <?php echo ($school['pixKeyType'] == 'Telefone') ? 'selected' : ''; ?>>Telefone</option>
                                <option value="Aleatoria" <?php echo ($school['pixKeyType'] == 'Aleatoria') ? 'selected' : ''; ?>>Chave Aleatória</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Chave Pix</label>
                            <input type="text" name="pixKey" class="form-control" value="<?php echo htmlspecialchars($school['pixKey']); ?>" placeholder="Chave que aparecerá nos boletos/recibos">
                        </div>
                    </div>
                </div>

                <div class="span-6">
                    <div class="ux-card" style="height: 100%;">
                        <div class="card-heading"><i class="fas fa-file-signature"></i> Assinatura Digital</div>
                        <label style="margin-bottom: 10px; display:block; font-size:0.85rem; color:#666;">Usada para assinar certificados automaticamente.</label>
                        
                        <div class="upload-area" style="height: 160px;">
                            <?php 
                                $sigSrc = !empty($school['signatureImage']) ? $school['signatureImage'] : 'https://via.placeholder.com/200x80?text=Assinatura'; 
                            ?>
                            <img src="<?php echo $sigSrc; ?>" id="sigPreview" class="preview-img" style="max-height: 80px;">
                            <span class="upload-label"><i class="fas fa-pen-nib"></i> Clique para subir assinatura</span>
                            <input type="file" name="signatureImage" class="file-input" accept="image/*" onchange="previewImage(this, 'sigPreview')">
                            <input type="hidden" name="existingSig" value="<?php echo $school['signatureImage']; ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 30px; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn-save-fab">
                    <i class="fas fa-save"></i> Salvar Alterações
                </button>
            </div>

        </form>
    </div>
</div>

<script>
    function previewImage(input, previewId) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById(previewId).src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>

<?php include '../includes/admin_footer.php'; ?>