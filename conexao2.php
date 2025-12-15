<?php

function conectarAoBanco()
{
    // Configurações do Banco de Dados via Variáveis de Ambiente
    // O fallback está vazio por segurança (não commitar senhas reais)
    $db_host = getenv('MOTEL_DB_HOST') ?: "";
    $db_usuario = getenv('MOTEL_DB_USER') ?: "";
    $db_senha = getenv('MOTEL_DB_PASS') ?: "";
    $db_banco = getenv('MOTEL_DB_NAME') ?: "";

    $mysqli = new mysqli($db_host, $db_usuario, $db_senha, $db_banco);

    // Verifica se ocorreu algum erro na conexão
    if ($mysqli->connect_errno) {
        echo "Falha ao conectar ao MySQL: " . $mysqli->connect_error;
        exit();
    }

    // Incrementa o campo 'conexoes' na tabela 'configuracoes'
    $increment_query = "UPDATE configuracoes SET conexoes = conexoes + 1";
    if (!$mysqli->query($increment_query)) {
        echo "Erro ao atualizar o número de conexões: " . $mysqli->error;
    }

    return $mysqli;
}

?>
