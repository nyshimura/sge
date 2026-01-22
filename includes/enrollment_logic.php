<?php
// includes/enrollment_logic.php

// ... (Mantenha a função cancelarMatricula igual, ela já estava ok) ...

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

    // 3. LIMPEZA INTELIGENTE (A CORREÇÃO PRINCIPAL)
    // Apaga apenas o que é Pendente E que vence DAQUI PRA FRENTE.
    // O que ficou para trás (pendente ou pago) não é tocado.
    $del = $pdo->prepare("DELETE FROM payments WHERE studentId = :s AND courseId = :c AND status = 'Pendente' AND dueDate >= :cutoff");
    $del->execute([':s' => $studentId, ':c' => $courseId, ':cutoff' => $dataInicioEfetiva]);

    // 4. Geração de Mensalidades (Apenas Futuras)
    if ($finalFee > 0.01) {
        $startObj = new DateTime($dataInicioEfetiva);
        $limitDate = new DateTime(date('Y-12-31')); // Até fim do ano

        // Ajuste: Se hoje é dia 20 e o vencimento é dia 10, a cobrança deste mês já "passou".
        // A geração deve começar no mês seguinte para não gerar boleto vencido instantaneamente.
        // (Opcional: depende da regra da sua escola. Mantive a lógica padrão de ajuste simples)
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

            // Verifica se JÁ EXISTE pagamento (Pago ou Pendente antigo que sobreviveu ao delete)
            // Se já existe qualquer registro para essa referência, não duplicamos.
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