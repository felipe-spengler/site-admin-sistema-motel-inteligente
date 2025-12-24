<?php
include 'conexao2.php';

function loadBackgroundImage($conexao)
{
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
    } catch (Exception $e) {
        // Em caso de erro, exiba a mensagem de erro
        echo "Erro: " . $e->getMessage();
    }
}

// Função segura que insere no banco de comandos (Toledo) e dispara MQTT
function enviaDados($numeroQuarto, $conexaoLocalNaoUsada)
{ // Mantendo assinatura para compatibilidade, mas a conexão local não é usada para comando

    $filial = "toledo";
    $tabela = "comandos_toledo";
    $comando = "locar " . $numeroQuarto;

    // 1. Incluir arquivos necessários (caminho relativo)
    include_once 'conexao_comando.php';
    include_once 'mqtt_helper.php';

    try {
        // 2. Conexão dedicada para comandos
        $pdo = conectarAoBancoComandosPDO();

        if (!$pdo) {
            error_log("Autoatendimento: Falha na conexão com banco de comandos.");
            return;
        }

        // 3. Insere no banco para histórico e redundância
        // Nota: id_unidade usamos 0 ou um ID fixo para 'autoatendimento' se quiser identificar a origem
        $sql = "INSERT INTO {$tabela} (id_unidade, comando, executado, criado_em) VALUES (999, :comando, 0, NOW())";
        $stmt = $pdo->prepare($sql);

        if ($stmt->execute([':comando' => $comando])) {
            $lastId = $pdo->lastInsertId();

            // 4. Dispara MQTT (Coração do sistema em tempo real)
            $payload = json_encode([
                "id" => $lastId,
                "comando" => $comando
            ]);

            publicarComandoMqtt($filial, $payload);
            error_log("Autoatendimento: Comando '{$comando}' enviado com sucesso (ID: {$lastId}).");

        } else {
            error_log("Autoatendimento: Erro ao inserir comando no banco.");
        }

    } catch (Exception $e) {
        error_log("Autoatendimento: Exceção crítica - " . $e->getMessage());
    }
}

$conexao = conectarAoBanco();
$backgroundImage = loadBackgroundImage($conexao);
$numeroQuarto = 0;
$tpQuarto = 0;
if (isset($_GET['dados']) && isset($_GET['tipoQuarto'])) {
    $numeroQuarto = ($_GET['dados']);
    $tpQuarto = ($_GET['tipoQuarto']);
    enviaDados($numeroQuarto, $conexao);

} else {
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
            background-color: #f0f0f0;
            /* Cor de fundo padrão */
        }

        #background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            /* Largura ocupando 100% da largura da tela */
            height: 100vh;
            /* Altura ocupando 100% da altura da tela */
            background-size: 100% 100%;
            /* Estica a imagem para cobrir toda a largura e altura do contêiner */
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
            echo date('d/m/Y H:i:s'); ?>
        </p>
    </div>
    <script>
        // Carrega a imagem ao carregar a página
        window.onload = function () {
            var background = document.getElementById('background');
            background.style.backgroundImage = 'url("<?php echo $backgroundImage; ?>")'; // Substitua $backgroundImage pela URL da imagem
        };
        // Espera 30 segundos antes de redirecionar
        setTimeout(function () {
            window.location.href = 'autoatend.php';
        }, 30000); // 30 segundos
    </script>

</body>

</html>