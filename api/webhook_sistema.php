<?php
// Define que o conteúdo é JSON, embora a resposta seja 200 OK
header('Content-Type: application/json');

// 1. RESPONDE IMEDIATAMENTE (Obrigatório para MP)
http_response_code(200); 

// Configurações e includes
require __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('America/Sao_Paulo');

// Definição do arquivo de log
const LOG_FILE = __DIR__ . '/webhook_log.txt';
// Constante do Mercado Pago
const MP_ACCESS_TOKEN = 'APP_USR-1718861622321115-092422-dbec0bf923560b558e784f323fcf069b-151672516';

// Função auxiliar para registrar eventos
function write_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

write_log("--- Webhook Recebido ---");

// 2. PEGA OS DADOS DA NOTIFICAÇÃO
$data = json_decode(file_get_contents('php://input'), true);
write_log("Payload MP: " . json_encode($data));

// Verifica se é uma notificação de pagamento
if (empty($data['type']) || $data['type'] !== 'payment') {
    write_log("INFO: Notificação recebida não é do tipo 'payment' ou está vazia.");
    exit;
}

$payment_id = $data['data']['id'] ?? 0;
write_log("ID de Pagamento MP: " . $payment_id);

if ($payment_id == 0) {
    write_log("ERRO: payment_id não encontrado.");
    exit;
}

try {
    // 3. CONSULTA O MERCADO PAGO PARA IDENTIFICAR O MOTEL
    \MercadoPago\SDK::setAccessToken(MP_ACCESS_TOKEN);
    $payment_info = \MercadoPago\Payment::find_by_id($payment_id);

    if (!$payment_info) {
        write_log("ERRO: Falha ao consultar o payment_id no Mercado Pago.");
        exit;
    }

    $mensalidade_id_sistema = $payment_info->external_reference; // ex: MENSALIDADE-abelardo-hash
    $status_mp = $payment_info->status;
    
    write_log("External Reference: " . $mensalidade_id_sistema);
    write_log("Status MP: " . $status_mp);

    // 4. DETERMINA O BANCO DE DADOS PELO EXTERNAL_REFERENCE
    $conexao_path = '';
    
    // Identificação por string contida no reference (Compatível com PHP 7 e 8)
    if (strpos($mensalidade_id_sistema, 'abelardo') !== false) {
        $conexao_path = '../conexaoAbelardo.php';
        $motel_nome = "Abelardo";
    } elseif (strpos($mensalidade_id_sistema, 'toledo') !== false) {
        $conexao_path = '../conexao2.php';
        $motel_nome = "Toledo";
    } elseif (strpos($mensalidade_id_sistema, 'xanxere') !== false) {
        $conexao_path = '../conexaoXanxere.php';
        $motel_nome = "Xanxere";
    } else {
        write_log("ERRO: Não foi possível identificar o banco pelo reference: " . $mensalidade_id_sistema);
        exit;
    }

    if (!file_exists($conexao_path)) {
        write_log("ERRO: Arquivo de conexão ($conexao_path) não encontrado.");
        exit;
    }

    // 5. CONECTA AO BANCO IDENTIFICADO E ATUALIZA
    include $conexao_path;
    $conn = conectarAoBanco();
    write_log("Conectado ao banco do Motel: $motel_nome");

    $new_status = null;
    if (in_array($status_mp, ['approved', 'rejected', 'cancelled', 'refunded'])) {
        $new_status = $status_mp;
    }

    if (!$new_status || empty($mensalidade_id_sistema)) {
        write_log("INFO: Status '$status_mp' não requer atualização ou reference vazio.");
        $conn->close();
        exit;
    }

    $sql = "UPDATE mensalidade 
            SET status = ?, 
                transaction_id = ?, 
                atualizado_em = NOW() 
            WHERE external_reference = ? AND status = 'pending'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $new_status, $payment_id, $mensalidade_id_sistema);
    
    if ($stmt->execute()) {
        write_log("RESULTADO: Linhas afetadas: " . $conn->affected_rows);
    } else {
        write_log("ERRO NA QUERY: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();

} catch (\Exception $e) {
    write_log("ERRO GERAL: " . $e->getMessage());
    if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
}

write_log("--- Webhook Finalizado ---\n");
exit;
