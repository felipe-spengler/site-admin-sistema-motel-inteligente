<?php
// Define que o conteúdo é JSON
header('Content-Type: application/json');

// 1. RESPONDE IMEDIATAMENTE (Obrigatório para evitar timeout)
http_response_code(200);

// Configuração básica
date_default_timezone_set('America/Sao_Paulo');

// Definição do arquivo de log
const LOG_FILE = __DIR__ . '/webhook_log.txt';
// API Key do Asaas via Variável de Ambiente
$asaas_api_key = getenv('ASAAS_API_KEY');

// Função de Log
function write_log($message)
{
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

// 2. LOG INICIAL
write_log("--- Webhook Iniciado (Asaas) ---");

if (!$asaas_api_key) {
    write_log("ERRO: Variável de ambiente ASAAS_API_KEY não configurada.");
    exit;
}

$nome_banco = isset($_GET['banco']) ? strtolower($_GET['banco']) : '';
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// write_log("Banco: " . $nome_banco);
// write_log("Payload: " . $raw_input);

if (empty($nome_banco)) {
    write_log("ERRO: Parâmetro 'banco' não informado na URL (ex: ?banco=toledo).");
    exit;
}

// Validação do Payload do Asaas
// Asaas envia: { "event": "PAYMENT_RECEIVED", "payment": { "id": "...", ... } }
if (empty($data['event']) || empty($data['payment'])) {
    if (!empty($data)) {
        write_log("Ignorado: Payload sem 'event' ou 'payment'.");
    } else {
        write_log("ERRO: Payload vazio.");
    }
    exit;
}

$event = $data['event'];
$payment_data = $data['payment'];
$payment_id = $payment_data['id'] ?? '';

write_log("Evento: $event | Pagamento ID: $payment_id");

if (empty($payment_id)) {
    write_log("ERRO: ID do pagamento não encontrado.");
    exit;
}

// 3. CONEXÃO COM BANCO
$conexao_path = '';
switch ($nome_banco) {
    case 'abelardo':
        $conexao_path = '../conexaoAbelardo.php';
        break;
    case 'toledo':
        $conexao_path = '../conexao2.php';
        break;
    case 'xanxere':
        $conexao_path = '../conexaoXanxere.php';
        break;
    default:
        write_log("ERRO: Banco '$nome_banco' desconhecido.");
        exit;
}

if (!file_exists($conexao_path)) {
    write_log("ERRO: Arquivo de conexão não encontrado: $conexao_path");
    exit;
}

include $conexao_path;

try {
    write_log("Conectando ao banco '$nome_banco' via $conexao_path...");
    $conn = conectarAoBanco();

    // 4. CONSULTA API ASAAS VIA CURL
    // Melhor consultar a API para garantir o status fiel (embora o payload já tenha)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.asaas.com/v3/payments/" . $payment_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "access_token: " . $asaas_api_key,
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code != 200 || !$response) {
        write_log("ERRO Asaas API: HTTP $http_code. Curl Error: $curl_error");
        $conn->close();
        exit;
    }

    $payment_info = json_decode($response, true);

    // Pegar dados do retorno
    $status_asaas = $payment_info['status'] ?? '';
    // external_reference é onde guardamos o "MENSALIDADE-cidade-id"
    $external_reference = $payment_info['externalReference'] ?? '';

    write_log("Asaas Resposta: Status=$status_asaas | Ref=$external_reference");

    // Mapeamento de Status Asaas -> Sistema (aproveitando 'approved' do MP)
    $status_sistema = '';

    // Status de Sucesso no Asaas
    if (in_array($status_asaas, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'])) {
        $status_sistema = 'approved';
    }
    // Status de Falha/Cancelamento
    elseif (in_array($status_asaas, ['REFUNDED'])) {
        $status_sistema = 'refunded';
    } elseif (in_array($status_asaas, ['OVERDUE', 'REFUND_REQUESTED', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE'])) { // Opcional: tratar overdue como rejected/cancelled?
        $status_sistema = 'cancelled';
    }

    // Se não mapeou para algo "final" que nos interessa, encerra
    if (empty($status_sistema)) {
        write_log("INFO: Status Asaas '$status_asaas' mapeado para vazio (não é final ou não tratado).");
        $conn->close();
        exit;
    }

    if (empty($external_reference)) {
        write_log("ERRO: externalReference vazio no pagamento Asaas.");
        $conn->close();
        exit;
    }

    // 5. ATUALIZA BANCO
    $sql = "UPDATE mensalidade 
            SET status = ?, 
                transaction_id = ?, 
                atualizado_em = NOW() 
            WHERE external_reference = ? AND status = 'pending'"; // Só atualiza se ainda estava pendente (ou pending)

    $stmt = $conn->prepare($sql);

    // sss = string, string, string
    $stmt->bind_param("sss", $status_sistema, $payment_id, $external_reference);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        write_log("SUCESSO: Pagamento atualizado no banco para '$status_sistema'.");
    } else {
        write_log("INFO: Nenhuma linha afetada (provavelmente já estava atualizado ou ref não encontrada).");
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    write_log("ERRO EXCEPTION: " . $e->getMessage());
}
exit;
