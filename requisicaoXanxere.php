<?php
// Define a página de destino padrão
$pagina_destino = "quartos.php";

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    // Lê o parâmetro 'dados' da URL. Exemplo: 'disponibilizar 2'
    $acaoCompleta = filter_input(INPUT_GET, 'dados', FILTER_SANITIZE_SPECIAL_CHARS);

    if ($acaoCompleta) {
        
        // Determina a página de destino com base no comando
        if ($acaoCompleta === "reproduzir 1") {
            $pagina_destino = "principal.php";
        }
        // Se não for "reproduzir 1", $pagina_destino permanece "quartos.php"

        // Inclui a conexão com o banco de dados da filial
        include 'conexaoXanxere.php'; 
        
        $conexao = null;
        $stmt = null;    

        try {
            $conexao = conectarAoBanco();
            
            if (!$conexao || $conexao->connect_error) {
                 // Em caso de erro de conexão, faz o log e redireciona (sem mensagem de erro no navegador)
                 error_log("Erro de conexão com o banco de dados.");
                 // O redirecionamento ocorrerá no bloco finally
                 // Para este caso, apenas prosseguimos para o finally
            } else {
                
                // Prepara a inserção na tabela comandos_pendentes (comando, executado, criado_em)
                $consultaSQL = "INSERT INTO comandos_pendentes (comando, executado, criado_em) VALUES (?, 0, NOW())";
                $stmt = $conexao->prepare($consultaSQL);
                
                if ($stmt === false) {
                    throw new Exception("Erro ao preparar a consulta.");
                }

                // Liga o único parâmetro (o comando completo) como string (s)
                $stmt->bind_param("s", $acaoCompleta);
                
                if ($stmt->execute()) {
                    // SUCESSO. A página de destino já está definida em $pagina_destino.
                } else {
                    throw new Exception("Erro ao executar o comando.");
                }
            }

        } catch (Exception $e) {
            // ERRO. Faz o log do erro no servidor.
            error_log("Erro na inserção de comando: " . $e->getMessage());
            // O redirecionamento ocorrerá no bloco finally, escondendo o erro do usuário.
            
        } finally {
            // Fecha a conexão e o statement
            if ($stmt) {
                $stmt->close();
            }
            if ($conexao) {
                $conexao->close();
            }
            
            // REDIRECIONA PARA A PÁGINA ESPECÍFICA (PRINCIPAL OU QUARTOS)
            header("Location: " . $pagina_destino);
            exit();
        }

    } else {
        // Se 'dados' estiver ausente, redireciona para a página padrão (quartos.php)
        header("Location: " . $pagina_destino);
        exit();
    }
    
} else {
    // Se o método não for GET, redireciona para a página padrão (quartos.php)
    header("Location: " . $pagina_destino);
    exit();
}
?>