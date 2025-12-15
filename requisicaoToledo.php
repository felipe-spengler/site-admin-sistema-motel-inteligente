<?php

   include 'conexao2.php';
       

function getIp($conexao) {
    // Query SQL para obter o IP da tabela configuracoes
    $sql = "SELECT meuip FROM configuracoes";
    $result = $conexao->query($sql);

    // Verifique se algum resultado foi retornado
    if ($result->num_rows > 0) {
        // Obtenha o resultado como um array associativo
        $row = $result->fetch_assoc();
        // Extrai o valor do campo 'meuip'
        $meuip = $row["meuip"];
        return $meuip;
    } else {
        return null; // Ou outra indicação de erro, se preferir
    }
}

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Verifica se o parâmetro "dados" foi enviado
    if(isset($_GET['dados'])) {
        $conexao = conectarAoBanco();
        $dados = $_GET['dados'];
        $ip = getIp($conexao);
        
        if ($ip !== null) {
            // Agora você pode usar $ip onde precisar, por exemplo:
            $url = 'http://' . $ip . ':1521/receberNumeroQuarto';
            echo "URL: " . $url;
        } else {
            echo "Não foi possível obter o endereço IP.";
        }
        
        // Feche a conexão com o banco de dados
        $conexao->close();  
        
        // Inicializa o cURL
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dados); // Passa diretamente "abrir+4"
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Define o cabeçalho Content-Type como text/plain
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain'));

        // Executa a requisição
        $response = curl_exec($ch);
        
        // Fecha o manipulador cURL
        curl_close($ch);
        if ($dados === "reproduzir 1") {
            header("Location: principal.php");
        } else {
            header("Location: quartos.php");
        }

    } else {
        echo 'Parâmetro "dados" não encontrado na requisição GET.';
    }
} else {
    echo 'Acesso inválido. Esta página espera uma requisição GET.';
}
?>
