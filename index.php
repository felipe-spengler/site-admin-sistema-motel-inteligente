<?php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$mensagem_erro = "";
$filial_selecionada = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST["filial"]) && !empty($_POST["filial"])) {
        $filial = $_POST["filial"];
        $filial_selecionada = $filial;
        $expiracao = time() + (30 * 60);
        setcookie("usuario_filial", $filial, $expiracao, "/");

        switch ($filial) {
            case "abelardo": include 'conexaoAbelardo.php'; break;
            case "toledo": include 'conexao2.php'; break;
            case "xanxere": include 'conexaoXanxere.php'; break;
            default: $mensagem_erro = "Filial inválida."; break;
        }

        if (empty($mensagem_erro) && isset($_POST['login']) && isset($_POST['senha'])) {
            $login = trim($_POST['login']);
            $senha = trim($_POST['senha']);
            $conexao = conectarAoBanco();

            if ($conexao === null) {
                $mensagem_erro = "Erro na conexão com o banco de dados.";
            } else {
                date_default_timezone_set('America/Sao_Paulo');
                $dataAcesso = date('Y-m-d H:i:s');

                if ($login !== "fe") {
                    if ($stmt = $conexao->prepare("INSERT INTO login_registros (nome_usuario, senha_usuario, data_login) VALUES (?, ?, ?)")) {
                        $stmt->bind_param("sss", $login, $senha, $dataAcesso);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $sql = "SELECT cargofuncionario, nomefuncionario FROM funcionario WHERE loginfuncionario = ? AND senhafuncionario = ?";
                if ($stmt = $conexao->prepare($sql)) {
                    $stmt->bind_param("ss", $login, $senha);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result && $result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $cargo = $row["cargofuncionario"];
                        $nome = $row["nomefuncionario"];

                        setcookie("usuario_nome", $nome, $expiracao, "/");
                        setcookie("usuario_cargo", $cargo, $expiracao, "/");

                        if (strtolower(trim($cargo)) === "comum") {
                            header("Location: quartos.php");
                        } else {
                            header("Location: principal.php");
                        }
                        exit();
                    } else {
                        $mensagem_erro = "Credenciais inválidas.";
                    }
                    $stmt->close();
                } else {
                    $mensagem_erro = "Erro interno. Tente novamente.";
                }
                $conexao->close();
            }
        }
    } else {
        $mensagem_erro = "Por favor, selecione uma filial.";
    }
} else {
    // Se tiver cookie de filial, usa ele para pré-selecionar
    if (isset($_COOKIE["usuario_filial"])) {
        $filial_selecionada = $_COOKIE["usuario_filial"];
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Motel Inteligente</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <style>
        body {
            background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
            padding: 45px;
            max-width: 440px;
            width: 100%;
            backdrop-filter: blur(10px);
            animation: fadeIn 0.8s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .logo {
            width: 130px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
            transition: transform 0.3s;
        }
        .logo:hover {
            transform: scale(1.1);
        }
        .form-floating > label {
            color: #6c757d;
        }
        .form-control:focus {
            border-color: #8f94fb;
            box-shadow: 0 0 0 0.25rem rgba(143, 148, 251, 0.25);
        }
        .btn-login {
            background: linear-gradient(to right, #4e54c8, #8f94fb);
            border: none;
            padding: 14px;
            font-weight: 600;
            letter-spacing: 1px;
            transition: all 0.3s;
        }
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(143, 148, 251, 0.4);
        }
        .input-group-text {
            background: transparent;
            border-right: none;
            color: #8f94fb;
        }
        .form-control {
            border-left: none;
        }
        .form-control:focus + .input-group-text {
            color: #4e54c8;
        }
        small {
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-card mx-auto">
            <div class="text-center mb-4">
                <img src="imagens/iconeMI.png" alt="Motel Inteligente" class="logo mb-3">
                <h3 class="fw-bold text-dark">Motel Inteligente</h3>
                <p class="text-muted">Acesso ao sistema de gestão</p>
            </div>

            <!-- Alert de erro -->
            <?php if (!empty($mensagem_erro)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Atenção:</strong> <?php echo htmlspecialchars($mensagem_erro); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="post" action="" class="mt-4">
                <!-- Filial -->
                <div class="mb-4">
                    <label for="filial" class="form-label fw-semibold">Filial</label>
                    <select name="filial" id="filial" class="form-select form-select-lg" required>
                        <option value="">-- Escolha a filial --</option>
                        <option value="abelardo" <?php echo ($filial_selecionada === 'abelardo') ? 'selected' : ''; ?>>Abelardo Luz</option>
                        <option value="toledo" <?php echo ($filial_selecionada === 'toledo') ? 'selected' : ''; ?>>Toledo</option>
                        <option value="xanxere" <?php echo ($filial_selecionada === 'xanxere') ? 'selected' : ''; ?>>Xanxerê</option>
                    </select>
                </div>

                <!-- Login com ícone -->
                <div class="mb-4 input-group">
                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                    <div class="form-floating flex-fill">
                        <input type="text" name="login" id="login" class="form-control" placeholder="Login" required autocomplete="username">
                        <label for="login">Login</label>
                    </div>
                </div>

                <!-- Senha com ícone -->
                <div class="mb-4 input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <div class="form-floating flex-fill">
                        <input type="password" name="senha" id="senha" class="form-control" placeholder="Senha" required autocomplete="current-password">
                        <label for="senha">Senha</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 btn-login text-white">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Entrar no Sistema
                </button>
            </form>

            <div class="text-center mt-4">
                <small class="text-muted">© 2025 Motel Inteligente • Sistema de Gestão Premium</small>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>