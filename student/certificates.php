<?php
// student/certificates.php
$pageTitle = "Meus Certificados";
include '../includes/student_header.php';

$studentId = $_SESSION['user_id'];

// --- CONSULTA DE CERTIFICADOS ---
// Relacionamos com a tabela courses para pegar o nome e a thumb do curso
$sql = "SELECT cert.*, c.name as courseName, c.thumbnail 
        FROM certificates cert
        JOIN courses c ON cert.course_id = c.id
        WHERE cert.student_id = :sid
        ORDER BY cert.completion_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([':sid' => $studentId]);
$certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-container">
    <div style="max-width: 1000px; margin: 0 auto; padding-top: 20px;">
        
        <div class="profile-header" style="margin-bottom: 30px;">
            <div class="page-title">
                <h2><i class="fas fa-award"></i> Minhas Conquistas</h2>
                <p>Aqui você encontra todos os seus certificados de conclusão.</p>
            </div>
        </div>

        <?php if (count($certificates) > 0): ?>
            <div class="cert-grid">
                <?php foreach ($certificates as $cert): ?>
                    <div class="ux-card cert-card">
                        <div class="cert-thumb">
                            <?php if ($cert['thumbnail']): ?>
                                <img src="<?php echo $cert['thumbnail']; ?>" alt="Curso">
                            <?php else: ?>
                                <div class="no-thumb-cert"><i class="fas fa-graduation-cap"></i></div>
                            <?php endif; ?>
                            <div class="cert-overlay">
                                <a href="../includes/generate_certificate_pdf.php?hash=<?php echo $cert['verification_hash']; ?>" target="_blank" class="btn-view-cert">
                                    <i class="fas fa-file-pdf"></i> Visualizar PDF
                                </a>
                            </div>
                        </div>
                        
                        <div class="cert-body">
                            <h4><?php echo htmlspecialchars($cert['courseName']); ?></h4>
                            <div class="cert-meta">
                                <span><i class="far fa-calendar-check"></i> Concluído em: <?php echo date('d/m/Y', strtotime($cert['completion_date'])); ?></span>
                                <small class="text-muted">Cod. Verificação: <?php echo substr($cert['verification_hash'], 0, 15); ?>...</small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card-box" style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-medal" style="font-size: 4rem; color: #eee; margin-bottom: 20px; display: block;"></i>
                <h3 style="color: #999;">Você ainda não possui certificados.</h3>
                <p style="color: #bbb;">Continue estudando para desbloquear suas certificações!</p>
                <a href="index.php" class="btn-primary" style="display: inline-block; margin-top: 20px; text-decoration: none;">Ir para Meus Cursos</a>
            </div>
        <?php endif; ?>

    </div>
</div>

<style>
    /* Grid de Certificados */
    .cert-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 25px;
    }

    .cert-card {
        padding: 0 !important;
        overflow: hidden;
        transition: transform 0.3s, box-shadow 0.3s;
        border: 1px solid #eee;
    }

    .cert-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
    }

    /* Thumbnail e Overlay */
    .cert-thumb {
        height: 180px;
        position: relative;
        background: #f8f9fa;
        overflow: hidden;
    }

    .cert-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .no-thumb-cert {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 4rem;
        color: #ddd;
    }

    .cert-overlay {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(44, 62, 80, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s;
    }

    .cert-card:hover .cert-overlay {
        opacity: 1;
    }

    .btn-view-cert {
        background: #fff;
        color: #2c3e50;
        padding: 10px 20px;
        border-radius: 50px;
        text-decoration: none;
        font-weight: bold;
        font-size: 0.9rem;
        transition: 0.2s;
    }

    .btn-view-cert:hover {
        background: var(--primary-color);
        color: #fff;
    }

    /* Corpo do Card */
    .cert-body {
        padding: 20px;
    }

    .cert-body h4 {
        margin: 0 0 10px 0;
        color: #2c3e50;
        font-size: 1.1rem;
    }

    .cert-meta {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .cert-meta span {
        font-size: 0.85rem;
        color: #666;
    }

    .cert-meta small {
        font-size: 0.7rem;
        font-family: monospace;
        background: #f1f1f1;
        padding: 2px 5px;
        border-radius: 3px;
        align-self: flex-start;
    }
</style>

<?php include '../includes/student_footer.php'; ?>