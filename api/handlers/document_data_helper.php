<?php
// api/handlers/document_data_helper.php

require_once __DIR__ . '/pdf_helpers.php'; // Para valorPorExtenso

/**
 * Busca dados para contratos/termos.
 */
function get_document_details(PDO $conn, $studentId, $courseId) {
    error_log("Buscando detalhes do documento para S:$studentId, C:$courseId");
    
    $sql = " SELECT 
                u.id as studentId, u.firstName, u.lastName, u.email, u.rg as aluno_rg, 
                u.cpf as aluno_cpf, u.address as aluno_endereco, u.age, 
                u.guardianName, u.guardianRG, u.guardianCPF, u.guardianEmail, u.guardianPhone, 
                c.id as courseId, c.name as courseName, c.monthlyFee as courseBaseFee, 
                s.name as schoolName, s.cnpj as schoolCnpj, s.address as schoolAddress, 
                s.profilePicture, s.signatureImage, s.schoolCity, s.state,
                st.enrollmentContractText, st.imageTermsText, st.defaultDueDay, 
                e.contractAcceptedAt, e.termsAcceptedAt,
                e.customMonthlyFee, e.scholarshipPercentage 
             FROM users u 
             LEFT JOIN enrollments e ON u.id = e.studentId AND e.courseId = :courseId_e 
             JOIN courses c ON c.id = :courseId_c 
             JOIN system_settings st ON st.id = 1 
             JOIN school_profile s ON s.id = 1 
             WHERE u.id = :studentId 
             LIMIT 1 ";

    try {
        $stmt = $conn->prepare($sql);
        $courseIdParam = filter_var($courseId, FILTER_VALIDATE_INT) ? $courseId : null;
        if ($courseIdParam === null) throw new Exception("ID do curso inválido na query.");
        $stmt->execute([':studentId' => $studentId, ':courseId_e' => $courseId, ':courseId_c' => $courseIdParam]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$details) error_log("Detalhes não encontrados para S:$studentId, C:$courseId.");
        return $details;
    } catch (PDOException $e) { error_log("!!! PDO Error em get_document_details (S:$studentId, C:$courseId): " . $e->getMessage()); return false;
    } catch (Exception $e) { error_log("!!! Error em get_document_details (S:$studentId, C:$courseId): " . $e->getMessage()); return false; }
}

/**
 * Monta array de placeholders.
 */
function get_placeholders($details) {
    if (!$details) return [];
    $isMinor = ($details['age'] !== null && (int)$details['age'] < 18);
    $meses = [1=>"Janeiro",2=>"Fevereiro",3=>"Março",4=>"Abril",5=>"Maio",6=>"Junho",7=>"Julho",8=>"Agosto",9=>"Setembro",10=>"Outubro",11=>"Novembro",12=>"Dezembro"];
    
    $schoolCity = $details['schoolCity'] ?? 'Guarulhos';
    $data_atual_extenso = $schoolCity . ', ' . date('d') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');

    $aluno_nome = trim(($details['firstName'] ?? '') . ' ' . ($details['lastName'] ?? ''));
    $contr_nome = $isMinor && !empty($details['guardianName']) ? $details['guardianName'] : $aluno_nome;
    $contr_rg = $isMinor && !empty($details['guardianRG']) ? $details['guardianRG'] : ($details['aluno_rg'] ?? '');
    $contr_cpf = $isMinor && !empty($details['guardianCPF']) ? $details['guardianCPF'] : ($details['aluno_cpf'] ?? '');
    $contr_email = $isMinor && !empty($details['guardianEmail']) ? $details['guardianEmail'] : ($details['email'] ?? '');
    $contr_end = $details['aluno_endereco'] ?? '';

    // --- CÁLCULO DO VALOR ---
    $baseFee = (float)($details['courseBaseFee'] ?? 0);
    $customFee = isset($details['customMonthlyFee']) ? (float)$details['customMonthlyFee'] : null;
    $scholarship = isset($details['scholarshipPercentage']) ? (float)$details['scholarshipPercentage'] : 0;

    $finalFee = $baseFee;
    if ($customFee !== null) {
        $finalFee = $customFee;
    } elseif ($scholarship > 0) {
        $discountValue = $baseFee * ($scholarship / 100);
        $finalFee = max(0, $baseFee - $discountValue);
    }

    $vencimentoDia = $details['defaultDueDay'] ?? '10';

    // === A MÁGICA ACONTECE AQUI (CLÁUSULA DINÂMICA) ===
    $clausulaFinanceira = "";
    $mensalidadeFormatada = "";
    $mensalidadeExtenso = "";

    if ($finalFee <= 0.01) {
        // CENÁRIO: BOLSA 100% / GRATUITO
        $mensalidadeFormatada = "ISENTO";
        $mensalidadeExtenso = "com bolsa integral";
        
        // Frase completa para substituir o parágrafo de pagamento
        $clausulaFinanceira = "sendo concedida bolsa integral de 100% (cem por cento), isentando o ALUNO e seus responsáveis de quaisquer mensalidades referentes ao curso, não havendo obrigatoriedade de pagamentos mensais";
    
    } else {
        // CENÁRIO: PAGANTE
        $mensalidadeFormatada = number_format($finalFee, 2, ',', '.');
        $mensalidadeExtenso = valorPorExtenso($finalFee);

        // Frase padrão de pagamento
        $clausulaFinanceira = "restando o compromisso dos responsáveis sobre a mensalidade estabelecida no valor de R$ {$mensalidadeFormatada} ({$mensalidadeExtenso}) a ser paga de maneira antecipada sobre o mês a ser cursado, com vencimento para todo dia {$vencimentoDia} de cada mês";
    }
    // =================================================

    return [
        '{{aluno_nome}}' => $aluno_nome,
        '{{aluno_email}}' => $details['email'] ?? '',
        '{{aluno_rg}}' => $details['aluno_rg'] ?? '',
        '{{aluno_cpf}}' => $details['aluno_cpf'] ?? '',
        '{{aluno_endereco}}' => $details['aluno_endereco'] ?? '',
        '{{responsavel_nome}}' => $details['guardianName'] ?? '',
        '{{responsavel_rg}}' => $details['guardianRG'] ?? '',
        '{{responsavel_cpf}}' => $details['guardianCPF'] ?? '',
        '{{responsavel_email}}' => $details['guardianEmail'] ?? '',
        '{{responsavel_telefone}}' => $details['guardianPhone'] ?? '',
        '{{contratante_nome}}' => $contr_nome,
        '{{contratante_rg}}' => $contr_rg,
        '{{contratante_cpf}}' => $contr_cpf,
        '{{contratante_email}}' => $contr_email,
        '{{contratante_endereco}}' => $contr_end,
        '{{curso_nome}}' => $details['courseName'] ?? '',
        
        // Mantemos esses para compatibilidade, caso usem em outros lugares
        '{{curso_mensalidade}}' => $mensalidadeFormatada,
        '{{curso_mensalidade_extenso}}' => $mensalidadeExtenso,
        '{{vencimento_dia}}' => $vencimentoDia,
        
        // <<< NOVA VARIÁVEL PODEROSA >>>
        '{{clausula_financeira}}' => $clausulaFinanceira,

        '{{escola_nome}}' => $details['schoolName'] ?? '',
        '{{escola_cnpj}}' => $details['schoolCnpj'] ?? '',
        '{{escola_endereco}}' => $details['schoolAddress'] ?? '',
        '{{cidade_escola}}' => $details['schoolCity'] ?? '',
        '{{estado_escola}}' => $details['state'] ?? '',
        '{{data_atual_extenso}}' => $data_atual_extenso
    ];
}
?>