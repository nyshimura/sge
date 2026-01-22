<?php
$hash_param = isset($_GET['hash']) ? htmlspecialchars($_GET['hash']) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Certificado SGE</title>
    <link rel="stylesheet" href="../assets/css/index.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: var(--background-color, #f4f7f6);
            padding: 20px;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .verify-container {
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-width: 700px;
            width: 100%;
            text-align: center;
        }
        h1 { color: #2c3e50; margin-bottom: 25px; }
        .verify-form {
            margin-bottom: 30px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .form-input {
            flex-grow: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }
        .action-button {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            transition: 0.3s;
        }
        .primary { background-color: #3498db; color: white; }
        .primary:hover { background-color: #2980b9; }

        .status-box {
            margin-top: 20px;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid;
            text-align: left;
        }
        .status-box p { margin: 10px 0; border-bottom: 1px solid rgba(0,0,0,0.05); padding-bottom: 5px; }
        .status-box strong { display: inline-block; min-width: 160px; color: #333; }
        
        .initial { background-color: #f8f9fa; border-color: #e0e0e0; color: #6c757d; text-align: center; }
        .loading { background-color: #eaf2f8; border-color: #aed6f1; color: #1b4f72; text-align: center; }
        .error { background-color: #fef2f2; border-color: #dc3545; color: #dc3545; text-align: center; }
        .success { background-color: #f0fdf4; border-color: #a9dfbf; color: #166534; }
        .success h2 { margin-top: 0; color: #166534; text-align: center; }

        @media (max-width: 600px) {
            .verify-form { flex-direction: column; }
            .status-box strong { display: block; margin-bottom: 2px; }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <h1>Verificação de Autenticidade</h1>

        <form id="verify-form" class="verify-form">
            <input type="text" id="hash-input" name="hash" class="form-input" placeholder="Cole o código de verificação aqui" required pattern="[a-fA-F0-9]{64}" title="O código deve ter 64 caracteres hexadecimais." value="<?php echo $hash_param; ?>">
            <button type="submit" class="action-button primary">Verificar</button>
        </form>

        <div id="result">
            <p class="status-box initial">Insira o código de verificação acima ou acesse através do link/QR Code do certificado.</p>
        </div>
    </div>

    <script>
        const resultDiv = document.getElementById('result');
        const form = document.getElementById('verify-form');
        const hashInput = document.getElementById('hash-input');

        async function verifyHash(hash) {
            if (!hash || !/^[a-f0-9]{64}$/i.test(hash)) {
                resultDiv.innerHTML = '<p class="status-box error">Erro: Código de verificação inválido.</p>';
                return;
            }
            
            resultDiv.innerHTML = '<p class="status-box loading">Verificando nos registros oficiais...</p>';
            
            // ATUALIZADO: Caminho correto para assets/api
            const apiUrl = `../assets/api/verify_certificate.php?hash=${encodeURIComponent(hash)}`;
            
            try {
                const response = await fetch(apiUrl);
                const data = await response.json();
                
                if (data.success && data.data && data.data.certificate) {
                    const cert = data.data.certificate;
                    resultDiv.innerHTML = `
                        <div class="status-box success">
                            <h2>✅ Certificado Autêntico</h2>
                            <p><strong>Aluno:</strong> ${cert.studentFirstName} ${cert.studentLastName}</p>
                            <p><strong>Documento:</strong> ${cert.studentCpf_masked}</p>
                            <p><strong>Curso:</strong> ${cert.courseName}</p>
                            <p><strong>Conclusão:</strong> ${cert.completion_date_formatted}</p>
                            <p><strong>Data de Emissão:</strong> ${new Date(cert.generated_at).toLocaleDateString('pt-BR')}</p>
                        </div>
                    `;
                    // Atualiza a URL sem recarregar a página para facilitar o compartilhamento
                    window.history.pushState(null, '', `?hash=${hash}`);
                } else {
                    resultDiv.innerHTML = `<p class="status-box error">❌ Atenção: Este código de certificado não foi encontrado em nossa base de dados oficial.</p>`;
                }
            } catch (error) {
                resultDiv.innerHTML = '<p class="status-box error">⚠️ Erro ao conectar com o servidor de verificação.</p>';
            }
        }

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            verifyHash(hashInput.value.trim());
        });

        document.addEventListener('DOMContentLoaded', () => {
            if (hashInput.value.trim().length === 64) {
                verifyHash(hashInput.value.trim());
            }
        });
    </script>
</body>
</html>