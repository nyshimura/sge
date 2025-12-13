Um sistema web completo para gerenciamento de instituições de ensino, focado em  escolas de teatro, idiomas, cursos livres, etc.. O projeto visa centralizar a gestão de alunos, cursos, pagamentos e frequência em uma plataforma única e de fácil utilização.

## 📖 Índice

* [Sobre o Projeto](#-sobre-o-projeto)
* [🚀 Funcionalidades Principais](#-funcionalidades-principais)
* [🛠️ Tecnologias Utilizadas](#-tecnologias-utilizadas)
* [🏁 Como Executar (Instalação)](#-como-executar-instalação)
* [Como Usar](#-como-usar)
* [📜 Licença](#-licença)

---

## 📍 Sobre o Projeto

Este projeto foi desenvolvido com o objetivo de simplificar o fluxo de funcionamento de escolas de cursos abertos. Ele permite que administradores, professores e alunos interajam em um ambiente digital, simplificando processos que vão desde a matrícula inicial até o controle financeiro.

---

## 🚀 Funcionalidades Principais

O sistema é dividido em módulos principais:

* **👨‍🎓 Gestão de Alunos:**
    * [X] Cadastro e edição de alunos
    * [X] Matrícula de alunos em cursos
    * [X] Gestão de contratos e termos de imagem
    * [X] Criação de certificados de conclusão
    * [X] Verificação de Certificado por Hash
* **📚 Gestão de Cursos:**
    * [X] Criação e gerenciamento de cursos
    * [X] Definição de turmas e horários
* **📝 Controle de Frequência:**
    * [X] Lançamento de presença/falta por professores
    * [X] Relatórios de frequência por aluno ou turma
* **💰 Controle Financeiro:**
    * [X] Geração de mensalidades/cobranças
    * [X] Baixa manual de pagamentos
    * [X] Relatórios de inadimplência



---


## 🏁 Como Executar (Instalação)

Siga os passos abaixo para configurar o ambiente de desenvolvimento local (baseado em XAMPP).

1.  **Pré-requisitos:**
    * Ter o [XAMPP](https://www.apachefriends.org/pt_br/index.html) (ou similar) instalado.
    * Ter o [Git](https://git-scm.com/) instalado.

2.  **Clonar o Repositório:**
    Abra seu terminal e navegue até a pasta `htdocs` do XAMPP (geralmente `C:/xampp/htdocs`):
    ```bash
    cd C:/xampp/htdocs
    git clone https://github.com/nyshimura/sge.git
    cd sge
    ```

3.  **Configurar o Banco de Dados:**
    * Inicie os módulos **Apache** e **MySQL** no painel de controle do XAMPP.
    * Acesse `http://localhost/phpmyadmin/`.
    * Crie um novo banco de dados (ex: `gestao_escolar`).
    * Importe o arquivo `.sql` do projeto (ex: `database/schema.sql`) para este banco de dados.

4.  **Configurar Conexão:**
    * `config.php` (ou o nome que você usa).
    * Abra este arquivo e edite as credenciais de conexão com o banco de dados que você criou:
    ```php
    define('DB_HOST', 'localhost');
    define('DB_USER', 'seuuserdoBD');
    define('DB_PASS', 'senhadoBD'); // Senha do seu MySQL 
    define('DB_NAME', 'nomedoBD');
    ```

5.  **Executar:**
    * Acesse `http://localhost/sge` em seu navegador.

## ⏰ Automação e Cron Jobs (Cobrança)

Este sistema possui um módulo de **Automação de Cobrança** que envia e-mails com lembretes de vencimento e QR Code PIX.

### 1. Configuração do Script
O script responsável está em: `public_html/sge/api/cron/send_reminders.php`.
Ele deve ser executado **uma vez ao dia** `0 8 * * *`.

---

## 🏃 Como Usar

Após a instalação, você pode acessar o sistema com as credenciais padrão:

* **URL de Login:** `http://localhost/sge`

* **Perfil Administrador:**
    * **Usuário:** `admin@admin`
    * **Senha:** `admin`

* **Configurações:**
  * **Configure sua api do gemini**
  * **Configure seus dados de smtp**
  * **Personalize suas mensagens de matricula aprovada e redefinição de senha**
  * **Personalize seus Contratos e termos de imagem**

**Verificar Certificado**
  * Acesse `http://localhost/sge/verificar` em seu navegador.


---

## 📜 Licença

Este projeto está licenciado sob a Licença MIT. Veja o arquivo `LICENSE` para mais detalhes.

---
