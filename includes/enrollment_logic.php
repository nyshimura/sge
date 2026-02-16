<?php
// includes/enrollment_logic.php

/**
 * Função para cancelar matrícula e aplicar regras de multa
 * CORREÇÃO: Mantém mês atual e gera multa a partir do próximo mês respeitando o dia de vencimento.
 */
function cancelarMatricula($pdo, $studentId, $courseId, $monthlyFee) {
    // 1. Busca configurações de multa no banco
    $stmt = $pdo->query("SELECT terminationFineMonths, enableTerminationFine FROM system_settings WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $months = isset($config['terminationFineMonths']) ? (int)$config['terminationFineMonths'] : 0;
    $enabled = isset($config['enableTerminationFine']) ? (bool)$config['enableTerminationFine'] : false;

    // 2. Busca o dia de vencimento do aluno para gerar a multa na data certa
    $stmtEnroll = $pdo->prepare("SELECT customDueDay FROM enrollments WHERE studentId = ? AND courseId = ?");
    $stmtEnroll->execute([$studentId, $courseId]);
    $enrollData = $stmtEnroll->fetch();
    $dueDay = !empty($enrollData['customDueDay']) ? (int)$enrollData['customDueDay'] : 10; // Padrão dia 10 se não tiver

    // 3. Define a data de corte: PRIMEIRO DIA DO PRÓXIMO MÊS
    // Isso garante que cobranças do mês corrente (pendentes ou não) sejam preservadas.
    $cutoffDate = new DateTime('first day of next month');
    $cutoffStr = $cutoffDate->format('Y-m-d');

    // 4. Remove cobranças Pendentes APENAS do futuro (Próximo mês em diante)
    $del = $pdo->prepare("DELETE FROM payments WHERE studentId = ? AND courseId = ? AND status = 'Pendente' AND dueDate >= ?");
    $del->execute([$studentId, $courseId, $cutoffStr]);

    // 5. Gera a Multa (Se estiver habilitada)
    if ($enabled && $months > 0 && $monthlyFee > 0) {
        // A multa começa a contar a partir do mês de corte (próximo mês)
        $fineDate = clone $cutoffDate; 
        
        for ($i = 0; $i < $months; $i++) {
            // Calcula o vencimento exato (respeitando fim de mês, ex: dia 31 em fevereiro vira dia 28)
            $year = $fineDate->format('Y');
            $month = $fineDate->format('m');
            $lastDayOfMonth = (int)$fineDate->format('t');
            $dayToUse = min($dueDay, $lastDayOfMonth);
            
            $dueDate = "$year-$month-$dayToUse";
            
            // Cria a cobrança da multa
            $ins = $pdo->prepare("INSERT INTO payments (studentId, courseId, amount, dueDate, status, referenceDate, method, created_at) VALUES (?, ?, ?, ?, 'Pendente', ?, 'Boleto', NOW())");
            
            // ReferenceDate usamos o dia 1 do mês da multa
            $refDate = $fineDate->format('Y-m-01');

            $ins->execute([
                $studentId,
                $courseId,
                $monthlyFee, // Valor da multa = mensalidade
                $dueDate,
                $refDate 
            ]);

            // Avança para o próximo mês
            $fineDate->modify('+1 month');
        }
    }
}

/**
 * Reativa a matrícula e gera parcelas DAQUI PRA FRENTE (Preservando o passado)
 */
function reativarMatricula($pdo, $studentId, $courseId, $finalFee, $dueDay, $billingStartDate = null) {
    // 1. Definições de Data de Corte (Mês Atual)
    $primeiroDiaMesAtual = date('Y-m-01');
    
    // Se a "Data de Início" vinda do banco for VELHA (antes de hoje),
    // forçamos o sistema a começar a gerar a partir deste mês.
    // Se for FUTURA (ex: matrícula agendada para mês que vem), respeitamos ela.
    if (!$billingStartDate || $billingStartDate < $primeiroDiaMesAtual) {
        $dataInicioEfetiva = $primeiroDiaMesAtual;
    } else {
        $dataInicioEfetiva = $billingStartDate;
    }

    // 2. Atualiza Status para 'Aprovada'
    $upd = $pdo->prepare("UPDATE enrollments SET status = 'Aprovada' WHERE studentId = :s AND courseId = :c");
    $upd->execute([':s' => $studentId, ':c' => $courseId]);

    // 3. LIMPEZA INTELIGENTE
    // Apaga apenas o que é Pendente E que vence DAQUI PRA FRENTE.
    $del = $pdo->prepare("DELETE FROM payments WHERE studentId = :s AND courseId = :c AND status = 'Pendente' AND dueDate >= :cutoff");
    $del->execute([':s' => $studentId, ':c' => $courseId, ':cutoff' => $dataInicioEfetiva]);

    // 4. Geração de Mensalidades (Apenas Futuras)
    if ($finalFee > 0.01) {
        $startObj = new DateTime($dataInicioEfetiva);
        $limitDate = new DateTime(date('Y-12-31')); // Até fim do ano

        // Ajuste: Se hoje é dia 20 e o vencimento é dia 10, a cobrança deste mês já "passou".
        if ((int)$startObj->format('d') > $dueDay) {
           $startObj->modify('first day of next month');
        }

        while ($startObj <= $limitDate) {
            $ano = $startObj->format('Y');
            $mes = $startObj->format('m');
            $lastDay = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
            $diaFixo = min($dueDay, $lastDay);
            
            $dueDate = "$ano-$mes-$diaFixo";
            $refDate = "$ano-$mes-01"; 

            // Verifica se JÁ EXISTE pagamento para não duplicar
            $check = $pdo->prepare("SELECT id FROM payments WHERE studentId=:s AND courseId=:c AND referenceDate=:ref");
            $check->execute([':s' => $studentId, ':c' => $courseId, ':ref' => $refDate]);

            if ($check->rowCount() == 0) {
                $ins = $pdo->prepare("INSERT INTO payments (studentId, courseId, amount, referenceDate, dueDate, status, created_at) VALUES (:s, :c, :a, :ref, :due, 'Pendente', NOW())");
                $ins->execute([
                    ':s' => $studentId, 
                    ':c' => $courseId, 
                    ':a' => $finalFee, 
                    ':ref' => $refDate, 
                    ':due' => $dueDate
                ]);
            }

            $startObj->modify('first day of next month');
        }
    }
}
?>
