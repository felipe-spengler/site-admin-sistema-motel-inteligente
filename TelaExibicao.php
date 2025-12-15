<?php
include 'conexao2.php';

function loadBackgroundImage($conexao) {
    $query = "SELECT imagem FROM imagens WHERE nome_da_imagem = 'direcionamento'";

    try {
        // Preparando e executando a consulta
        $stmt = $conexao->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result(); // Obtendo o resultado da consulta

        if ($result->num_rows > 0) {
            // Se a imagem existir no banco de dados, retorne-a
            $row = $result->fetch_assoc();
            $stmt->close(); // Liberando o resultado
            return 'data:image/jpeg;base64,' . base64_encode($row['imagem']);
        } else {
            $stmt->close(); // Liberando o resultado
            echo "Nenhuma imagem encontrada";
        }
    } catch(Exception $e) {
        // Em caso de erro, exiba a mensagem de erro
        echo "Erro: " . $e->getMessage();
    }
}

function getIp($conexao) {
    // Query SQL para obter o IP da tabela configuracoes
    $sql = "SELECT meuip FROM configuracoes";
    $result = $conexao->query($sql);

    // Verifique se algum resultado foi retornado
    if ($result->num_rows > 0) {
        // Obtenha o resultado como um array associativo
        $row = $result->fetch_assoc();
        $meuip = $row["meuip"];
        $result->close(); // Liberando o resultado
        return $meuip;
    } else {
        $result->close(); // Liberando o resultado
        return null; // Ou outra indicação de erro, se preferir
    }
}

function enviaDados($dados, $conexao) {
    $ip = getIp($conexao);
    
    if ($ip !== null) {
        $url = 'http://' . $ip . ':1521/receberNumeroQuarto';
        
        // Inicializa o cURL
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "locar ". $dados); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain'));

        $response = curl_exec($ch);

        if(curl_errno($ch)){
            echo 'Erro ao fazer a solicitação: ' . curl_error($ch);
        } 

        curl_close($ch);
    } else {
        echo "Não foi possível obter o endereço IP.";
    }
}

$conexao = conectarAoBanco();
$backgroundImage = loadBackgroundImage($conexao);
$numeroQuarto = 0;
$tpQuarto = 0;
if (isset($_GET['dados']) && isset($_GET['tipoQuarto'])) {
    $numeroQuarto = ($_GET['dados']);
    $tpQuarto = ($_GET['tipoQuarto']) ;
    enviaDados($numeroQuarto, $conexao);

}else{
    echo "deu else";
}

// Fechando a conexão após o uso
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
            margin-top: 150px; 
        }
        #rodape {
            position: fixed;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            color: white;
            font-size: 36px;
        }
    </style>
</head>
<body>
    <div id="background"></div>
    <div id="content">
        <p style="color: white; font-family: Arial, sans-serif; font-size: 80px;">
            <?php echo $numeroQuarto . " - " . $tpQuarto; ?>
        </p>
    </div>
    <div id="rodape">
        <p style="color: white; font-size: 36px;">
        <?php
            date_default_timezone_set('America/Sao_Paulo');
            echo date('d/m/Y H:i:s');?>   
        </p>
    </div>
    <script>
        // Carrega a imagem ao carregar a página
        window.onload = function() {
            var background = document.getElementById('background');
            background.style.backgroundImage = 'url("<?php echo $backgroundImage; ?>")'; // Substitua $backgroundImage pela URL da imagem
        };
        // Espera 30 segundos antes de redirecionar
        setTimeout(function() {
            window.location.href = 'autoatend.php';
        }, 30000); // 30 segundos
    </script>

</body>
</html>
