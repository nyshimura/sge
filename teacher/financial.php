<?php
// teacher/financial.php
$pageTitle = "Minhas Comissões (Mês Atual)";
include '../includes/teacher_header.php';

$teacherId = $_SESSION['user_id'];

// --- CONFIGURAÇÃO DE DATA (Automático: Mês Atual) ---
$currentMonth = date('m');
$currentYear = date('Y');

// Nome do mês para exibição
$monthName = [
    1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 
    7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'
][(int)$currentMonth];

// --- BUSCAR PAGAMENTOS ---
// Mantemos o filtro > 0 para garantir que só apareça o que gera comissão
$sqlPay = "SELECT 
                p.paymentDate, 
                p.amount,
                s.firstName, 
                s.lastName,
                c.name as courseName,
                ct.commissionRate
           FROM payments p
           JOIN users s ON p.studentId = s.id
           JOIN courses c ON p.courseId = c.id
           JOIN course_teachers ct ON c.id = ct.courseId AND ct.teacherId = :tid
           WHERE p.status = 'Pago'
           AND MONTH(p.paymentDate) = :month
           AND YEAR(p.paymentDate) = :year
           AND ct.commissionRate > 0 
           ORDER BY p.paymentDate DESC";

$stmtPay = $pdo->prepare($sqlPay);
$stmtPay->execute([
    ':tid' => $teacherId, 
    ':month' => $currentMonth, 
    ':year' => $currentYear
]);
$payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

// Calcular Total de Comissões
$totalCommission = 0;

foreach ($payments as $pay) {
    // Cálculo continua igual: Valor Pago * (Taxa / 100)
    $commission = $pay['amount'] * ($pay['commissionRate'] / 100);
    $totalCommission += $commission;
}
?>

<div class="page-container">

    <div class="dashboard-stats" style="grid-template-columns: 1fr; margin-bottom: 30px;">
        <div class="stat-card" style="border-left: 5px solid var(--primary-color);">
            <div class="stat-icon" style="background: #f4ecf7; color: var(--primary-color);">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stat-info">
                <h4>Comissões de <?php echo $monthName; ?></h4>
                <p style="color: var(--primary-color); font-size: 2rem;">R$ <?php echo number_format($totalCommission, 2, ',', '.'); ?></p>
            </div>
        </div>
    </div>

    <div class="section-header">
        <h3 class="section-title">Detalhamento dos Recebimentos</h3>
    </div>

    <?php if (count($payments) == 0): ?>
        <div class="card-box" style="text-align:center; padding:40px; color:#888;">
            <i class="fas fa-receipt fa-3x" style="opacity:0.3; margin-bottom:15px;"></i>
            <p>Nenhuma comissão registrada neste mês.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($payments as $pay): 
                $commission = $pay['amount'] * ($pay['commissionRate'] / 100);
            ?>
                <div class="card-box" style="padding: 15px; margin-bottom: 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    
                    <div style="flex: 1; min-width: 200px;">
                        <div style="font-weight: bold; color: #333; margin-bottom: 4px;">
                            <?php echo htmlspecialchars($pay['firstName'] . ' ' . $pay['lastName']); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #666;">
                            <?php echo htmlspecialchars($pay['courseName']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #999; margin-top: 4px;">
                            <i class="far fa-calendar-check"></i> Data: <?php echo date('d/m/Y', strtotime($pay['paymentDate'])); ?>
                        </div>
                    </div>

                    <div style="text-align: right; min-width: 120px;">
                        <div style="font-size: 1.1rem; font-weight: bold; color: #27ae60; background: #e8f5e9; padding: 5px 10px; border-radius: 6px; display: inline-block;">
                            + R$ <?php echo number_format($commission, 2, ',', '.'); ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php include '../includes/teacher_footer.php'; ?>