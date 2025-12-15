<?php
// Inclua o arquivo de conexão
include 'conexao2.php';

// Função para carregar a imagem do banco de dados
function loadBackgroundImage($conexao) {
    $query = "SELECT imagem FROM imagens WHERE nome_da_imagem = 'fundoAbertura'";

    try {
        // Preparando e executando a consulta
        $stmt = $conexao->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result(); // Obtendo o resultado da consulta

        if ($result->num_rows > 0) {
            // Se a imagem existir no banco de dados, retorne-a
            $row = $result->fetch_assoc();
            $imagemBase64 = 'data:image/jpeg;base64,' . base64_encode($row['imagem']);
        } else {
            $imagemBase64 = "Nenhuma imagem encontrada";
        }

        $stmt->close(); // Fechar o statement após o uso
        return $imagemBase64;
    } catch(Exception $e) {
        // Em caso de erro, exiba a mensagem de erro
        return "Erro: " . $e->getMessage();
    }
}

// Conectando ao banco de dados
$conexao = conectarAoBanco();
$backgroundImage = loadBackgroundImage($conexao);

// Fechar a conexão
$conexao->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tela de Abertura</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f0f0f0; /* Cor de fundo padrão */
        }
        #background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw; /* Largura ocupando 100% da largura da tela */
            height: 100vh; /* Altura ocupando 100% da altura da tela */
            background-size: 100% 100%; /* Estica a imagem para cobrir toda a largura e altura do contêiner */
            background-repeat: no-repeat;
            background-position: center;
            z-index: -1;
        }
        #content {
            text-align: center;
            color: #333;
        }
    </style>
</head>
<body>
    <div id="background"></div>
    <div id="content">
        
    </div>
    <script>
    // Função para entrar na próxima tela ao clicar em qualquer lugar
    document.body.addEventListener('click', function() {
        window.location.href = 'HomePage.php'; // Substitua 'HomePage.php' pelo caminho para a próxima página
    });

    // Carrega a imagem ao carregar a página
    window.onload = function() {
        // Obtém o token armazenado no localStorage
        //localStorage.setItem('authToken', '121212');
        const storedToken = localStorage.getItem('authToken');
        
        // Verifica o token
        if (storedToken !== '121212') {
            //alert('Acesso negado! Redirecionando...');
            //window.location.href = 'https://motelinteligente.com'; // URL de redirecionamento
            //return; // Interrompe o resto do código
    
    
    
        }

        // Define a imagem de fundo
        var background = document.getElementById('background');
        background.style.backgroundImage = 'url("<?php echo $backgroundImage; ?>")'; // Substitua $backgroundImage pela URL da imagem gerada pelo PHP
    };
</script>
    <script src="scripts.js"></script>
</body>
</html>
