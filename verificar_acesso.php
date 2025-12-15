<?php
function verificarCookie($conexao, $paginaAtual) {
    // Nome do cookie que armazena o nome do usuário
    $cookieName = "usuario_nome";

    // Verificar se o cookie do nome do usuário está definido
    if (!isset($_COOKIE[$cookieName])) {
        // Redirecionar para a página de login se o cookie não estiver definido
        header("Location: index.php");
        exit;
    }

    // Obter o nome do usuário do cookie
    $nomeUsuario = $_COOKIE[$cookieName];

    // Configurar o fuso horário para Brasília
    date_default_timezone_set('America/Sao_Paulo');
    $dataAcesso = date('Y-m-d H:i:s');

    // Se o nome do usuário for diferente de "fe", registrar o login
    if ($nomeUsuario !== "fe") {
        // Preparar e executar a instrução SQL para registrar o acesso
        if ($stmt = $conexao->prepare("INSERT INTO login_acesso (nome_usuario, pagina_acesso, data_acesso) VALUES (?, ?, ?)")) {
            $stmt->bind_param("sss", $nomeUsuario, $paginaAtual, $dataAcesso);
            
            // Executar a instrução e verificar erros
            if (!$stmt->execute()) {
                error_log("Erro ao registrar acesso: " . $stmt->error);
                echo "Erro: " . $stmt->error;
            }
            $stmt->close();
        } else {
            error_log("Erro ao preparar a instrução SQL: " . $conexao->error);
        }
    }
}

function verificarCargoUsuario($cargosPermitidos) {

    $cookieCargoUsuario = "usuario_cargo";

    // Verificar se o cookie do cargo está definido
    if (!isset($_COOKIE[$cookieCargoUsuario])) {
        header("Location: index.php");
        exit;
    }

    // Obter o cargo do usuário do cookie
    $cargoUsuario = $_COOKIE[$cookieCargoUsuario];


    if (!in_array($cargoUsuario, $cargosPermitidos)) {

        header("Location: faltaPermissao.php");
        exit;
    }
}
?>