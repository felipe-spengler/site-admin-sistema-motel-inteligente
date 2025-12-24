<?php
require_once 'vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// ==========================================================
// CONFIGURAÇÃO DO BROKER MQTT - CENTRALIZADA
// Adapte aqui para o IP/Domínio do seu Coolify
// ==========================================================
define('MQTT_BROKER_HOST', getenv('MQTT_HOST') ?: 'SEU_IP_COOLIFY');
define('MQTT_BROKER_PORT', 1883);
define('MQTT_BROKER_USER', null); // Se tiver senha, coloque aqui
define('MQTT_BROKER_PASS', null);

function publicarComandoMqtt($filial, $comandoJson) {
    try {
        $clientId = 'MotelServer_' . uniqid();
        $mqtt = new MqttClient(MQTT_BROKER_HOST, MQTT_BROKER_PORT, $clientId);

        $settings = (new ConnectionSettings)
            ->setKeepAliveInterval(60)
            ->setConnectTimeout(3)
            ->setUseTls(false); // Mude para true se configurar SSL/TLS no Mosquitto

        if (MQTT_BROKER_USER) {
            $settings->setUsername(MQTT_BROKER_USER)
                     ->setPassword(MQTT_BROKER_PASS);
        }

        $mqtt->connect($settings, true);

        // Tópico padrão: motel/nome_da_filial/comandos
        $topico = "motel/{$filial}/comandos";
        
        // Publica a mensagem com QoS 1
        $mqtt->publish($topico, $comandoJson, 1);
        
        $mqtt->disconnect();
        
        return true;
    } catch (Exception $e) {
        // Log de erro, mas não para o script principal
        error_log("Erro ao publicar MQTT para {$filial}: " . $e->getMessage());
        return false;
    }
}
?>
