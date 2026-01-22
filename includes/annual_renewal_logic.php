<?php
// includes/annual_renewal_logic.php

/**
 * Gera cobranças para todo o ano letivo especificado para alunos ativos.
 * Evita duplicidade verificando se a cobrança já existe.
 * * @param PDO $pdo Conexão com o banco
 * @param int $targetYear O ano para gerar (ex: 2027)
 * @return array Resultado com 'type' (success/error) e 'msg'
 */
function generateAnnualInvoices($pdo, $targetYear) {
    if ($targetYear < 2024 || $targetYear > 2050) {
        return ['type' => 'error', 'msg' => 'Ano inválido. Escolha um ano entre 2024 e 2050.'];
    }

    try {
        $pdo->beginTransaction();

        // 1. Busca todos os alunos com Matrícula Aprovada (Ativos)
        $sql = "SELECT e.*, c.monthlyFee as baseFee, u.firstName, u.lastName 
                FROM enrollments e 
                JOIN courses c ON e.courseId = c.id 
                JOIN users u ON e.studentId = u.id
                WHERE e.status = 'Aprovada'";
        $stmt = $pdo->query($sql);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countPayments = 0;
        $countStudents = 0;

        foreach ($students as $enroll) {
            // 2. Define o valor da mensalidade
            $finalFee = 0;
            
            // Prioridade: Valor Fixo > Valor do Curso com Bolsa
            if (!empty($enroll['customMonthlyFee']) && $enroll['customMonthlyFee'] > 0) {
                $finalFee = (float)$enroll['customMonthlyFee'];
            } else {
                $baseFee = (float)$enroll['baseFee'];
                $scholarship = (float)$enroll['scholarshipPercentage'];
                $finalFee = max(0, $baseFee - ($baseFee * ($scholarship / 100)));
            }

            // Se for bolsa 100% (valor 0), pula
            if ($finalFee <= 0.01) continue;

            $dueDay = !empty($enroll['customDueDay']) ? (int)$enroll['customDueDay'] : 10;
            $generatedForThisStudent = false;

            // 3. Loop pelos 12 meses do ano alvo
            for ($mes = 1; $mes <= 12; $mes++) {
                // Lógica de dia de vencimento (cuidado com fevereiro)
                $lastDayOfMonth = cal_days_in_month(CAL_GREGORIAN, $mes, $targetYear);
                $day = min($dueDay, $lastDayOfMonth);
                
                $dueDate = sprintf('%04d-%02d-%02d', $targetYear, $mes, $day);
                $refDate = sprintf('%04d-%02d-01', $targetYear, $mes); // Referência sempre dia 01

                // 4. VERIFICAÇÃO ANTI-DUPLICIDADE
                // Verifica se já existe QUALQUER fatura (Pendente, Pago, Cancelado) para este aluno/curso nesta referência
                $check = $pdo->prepare("SELECT id FROM payments WHERE studentId=:s AND courseId=:c AND referenceDate=:ref");
                $check->execute([
                    ':s' => $enroll['studentId'],
                    ':c' => $enroll['courseId'],
                    ':ref' => $refDate
                ]);

                // Só insere se não existir nada
                if ($check->rowCount() == 0) {
                    $ins = $pdo->prepare("INSERT INTO payments (studentId, courseId, amount, referenceDate, dueDate, status, created_at) VALUES (:s, :c, :a, :ref, :due, 'Pendente', NOW())");
                    $ins->execute([
                        ':s' => $enroll['studentId'],
                        ':c' => $enroll['courseId'],
                        ':a' => $finalFee,
                        ':ref' => $refDate,
                        ':due' => $dueDate
                    ]);
                    $countPayments++;
                    $generatedForThisStudent = true;
                }
            }

            if ($generatedForThisStudent) $countStudents++;
        }

        $pdo->commit();
        
        return [
            'type' => 'success', 
            'msg' => "<b>Sucesso!</b> O Ano Letivo de <b>$targetYear</b> foi processado.<br>Foram geradas <b>$countPayments</b> novas faturas para <b>$countStudents</b> alunos ativos."
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['type' => 'error', 'msg' => 'Erro ao processar renovação: ' . $e->getMessage()];
    }
}
?>