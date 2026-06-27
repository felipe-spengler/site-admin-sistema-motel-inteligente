<?php
// ===================================================================================
// SCRIPT DE REQUISIÇÃO TOLEDO - ADAPTADO PARA MQTT (EVITA TIMEOUTS E PORT FORWARDING)
// ===================================================================================

ob_start();

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Verifica se o parâmetro "dados" foi enviado
    if(isset($_GET['dados'])) {
        $dados = $_GET['dados'];
        
        // 1. Inclusão da conexão dedicada PDO do banco de comandos
        include 'conexao_comando.php';
        
        try {
            $pdo = conectarAoBancoComandosPDO();
            
            if ($pdo) {
                // Extrai o ID da Unidade (Ex: "abrir 4" -> 4, "abrir entrada" -> 0)
                $partes = explode(' ', $dados);
                $id_unidade = 0;
                if (count($partes) === 2 && is_numeric($partes[1])) {
                    $id_unidade = (int) $partes[1];
                }
                
                // 2. Insere na tabela comandos_toledo para fins de auditoria/histórico
                $consultaSQL = "INSERT INTO comandos_toledo (id_unidade, comando, executado, criado_em) 
                                VALUES (:id_unidade, :comando, 0, NOW())";
                
                $stmt = $pdo->prepare($consultaSQL);
                
                $parametros = [
                    ':id_unidade' => $id_unidade,
                    ':comando' => $dados
                ];
                
                if ($stmt->execute($parametros)) {
                    $lastId = $pdo->lastInsertId();
                    
                    // 3. Monta o payload JSON e publica no tópico MQTT da filial Toledo
                    $mqttPayload = json_encode([
                        "id" => $lastId,
                        "comando" => $dados
                    ]);
                    
                    // Inclui o helper centralizado e publica no broker mosquitto
                    include_once 'mqtt_helper.php';
                    $publicado = publicarComandoMqtt('toledo', $mqttPayload);
                    
                    if ($publicado) {
                        echo "SUCESSO: Comando publicado via MQTT. ID: " . $lastId;
                    } else {
                        echo "PARCIAL: Gravado no DB, mas falha ao publicar MQTT.";
                    }
                } else {
                    echo "ERRO: Falha ao executar inserção no DB.";
                }
            } else {
                echo "ERRO: Falha na conexão PDO com o banco de dados de comandos.";
            }
        } catch (Exception $e) {
            error_log("Erro em requisicaoToledo.php: " . $e->getMessage());
            echo "ERRO EXCEÇÃO: " . $e->getMessage();
        }
        
    } else {
        echo 'Parâmetro "dados" não encontrado na requisição GET.';
    }
} else {
    echo 'Acesso inválido. Esta página espera uma requisição GET.';
}

ob_end_flush();
?>
