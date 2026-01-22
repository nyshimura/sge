<?php
// student/my_courses.php

// Configurações de erro
ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../includes/student_header.php';

// Verifica login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$studentId = $_SESSION['user_id'];

// Busca os cursos
try {
    $sql = "SELECT e.status, e.enrollmentDate, 
                   c.id as courseId, c.name, c.thumbnail, c.carga_horaria
            FROM enrollments e
            JOIN courses c ON e.courseId = c.id
            WHERE e.studentId = :sid
            ORDER BY e.enrollmentDate DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sid' => $studentId]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div style='padding:20px; color:red; background:#fff;'>Erro: " . $e->getMessage() . "</div>";
    exit;
}
?>

<style>
    /* --- Layout Grid --- */
    .courses-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 30px;
        padding-bottom: 40px;
    }

    /* --- Card do Curso --- */
    .course-card {
        background: #fff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        border: 1px solid #f0f0f0;
        display: flex;
        flex-direction: column;
        height: 100%;
        position: relative;
    }

    .course-card:hover {
        transform: translateY(-7px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        border-color: var(--primary-color-light, #a0ddd0);
    }

    /* --- Imagem --- */
    .course-thumb {
        height: 160px;
        background-color: #f8f9fa;
        position: relative;
        overflow: hidden;
    }
    .course-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }
    .course-card:hover .course-thumb img {
        transform: scale(1.05);
    }
    .course-thumb-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #dce0e3;
        font-size: 3.5rem;
    }

    /* --- Badge de Status --- */
    .status-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #fff;
        box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        z-index: 2;
        letter-spacing: 0.5px;
    }
    .bg-cursando { background: linear-gradient(135deg, #27ae60, #2ecc71); }
    .bg-pendente { background: linear-gradient(135deg, #f39c12, #f1c40f); }
    .bg-cancelado { background: linear-gradient(135deg, #c0392b, #e74c3c); }
    .bg-concluido { background: linear-gradient(135deg, #2980b9, #3498db); }

    /* --- Corpo do Card --- */
    .course-body {
        padding: 25px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .course-title {
        margin: 0 0 15px 0;
        font-size: 1.15rem;
        color: #2c3e50;
        font-weight: 700;
        line-height: 1.4;
    }

    .course-meta {
        font-size: 0.85rem;
        color: #7f8c8d;
        margin-bottom: 25px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .course-meta div {
        display: flex;
        align-items: center;
    }

    .course-meta i { 
        color: var(--primary-color); 
        margin-right: 10px; 
        width: 16px; 
        text-align: center; 
        font-size: 0.95rem;
    }

    /* --- Botão Ação (AJUSTADO) --- */
    .btn-access {
        margin-top: auto;
        display: flex;
        align-items: center;
        justify-content: center;
        
        /* Largura reduzida e centralização */
        width: 90%; 
        align-self: center; 
        
        padding: 10px 15px; /* Padding um pouco menor verticalmente */
        background: var(--primary-color); 
        color: #fff;
        border-radius: 50px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        border: none;
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }
    
    .btn-access:hover { 
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.25);
        background-color: #2c3e50;
        color: #fff;
    }

    .btn-access i {
        margin-left: 8px;
        transition: transform 0.3s;
    }

    .btn-access:hover i {
        transform: translateX(4px);
    }
    
    .btn-disabled {
        background: #e0e0e0;
        color: #999;
        cursor: not-allowed;
        pointer-events: none;
        box-shadow: none;
    }
</style>

<div class="page-container">
    <div style="margin-bottom: 35px;">
        <h2 style="color: #2c3e50; margin: 0; font-size: 1.8rem;">Meus Cursos</h2>
        <p style="color: #7f8c8d; margin-top: 8px;">Gerencie seus estudos e acesse o conteúdo das aulas.</p>
    </div>

    <?php if (empty($courses)): ?>
        <div class="card-box" style="text-align: center; padding: 80px 20px;">
            <div style="background: #f8f9fa; width: 100px; height: 100px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;">
                <i class="fas fa-graduation-cap" style="font-size: 3rem; color: #cbd3da;"></i>
            </div>
            <h3 style="color: #7f8c8d; margin-bottom: 10px;">Você ainda não está matriculado.</h3>
            <p style="color: #aaa;">Entre em contato com a secretaria para iniciar seus estudos.</p>
        </div>
    <?php else: ?>
        
        <div class="courses-grid">
            <?php foreach ($courses as $c): 
                // Lógica de Status Neutro
                $statusLabel = $c['status'];
                $statusClass = 'bg-pendente';
                $isAccessible = true;

                switch ($c['status']) {
                    case 'Aprovada':
                        $statusLabel = 'Cursando';
                        $statusClass = 'bg-cursando';
                        break;
                    case 'Concluido':
                        $statusLabel = 'Concluído';
                        $statusClass = 'bg-concluido';
                        break;
                    case 'Pendente':
                        $statusLabel = 'Pendente';
                        $statusClass = 'bg-pendente';
                        break;
                    case 'Cancelado':
                        $statusLabel = 'Cancelado';
                        $statusClass = 'bg-cancelado';
                        $isAccessible = false;
                        break;
                }
            ?>
            
            <div class="course-card">
                <div class="course-thumb">
                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                    <?php if (!empty($c['thumbnail'])): ?>
                        <img src="<?php echo htmlspecialchars($c['thumbnail']); ?>" alt="Capa">
                    <?php else: ?>
                        <div class="course-thumb-placeholder"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                </div>

                <div class="course-body">
                    <h3 class="course-title"><?php echo htmlspecialchars($c['name']); ?></h3>
                    
                    <div class="course-meta">
                        <div>
                            <i class="far fa-clock"></i> 
                            Carga Horária: <strong><?php echo $c['carga_horaria'] ? $c['carga_horaria'] . ' Horas' : '--'; ?></strong>
                        </div>
                        <div>
                            <i class="far fa-calendar-alt"></i> 
                            Início: <?php echo date('d/m/Y', strtotime($c['enrollmentDate'])); ?>
                        </div>
                    </div>

                    <?php if ($isAccessible): ?>
                        <a href="course_panel.php?cid=<?php echo $c['courseId']; ?>" class="btn-access">
                            Acessar Painel <i class="fas fa-arrow-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="btn-access btn-disabled">
                            Acesso Bloqueado
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>

<?php include '../includes/student_footer.php'; ?>