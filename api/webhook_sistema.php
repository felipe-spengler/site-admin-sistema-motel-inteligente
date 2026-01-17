<?php
// Define que o conteúdo é JSON
header('Content-Type: application/json');

// 1. RESPONDE IMEDIATAMENTE (Evita timeout)
http_response_code(200);

// Configuração básica
date_default_timezone_set('America/Sao_Paulo');

// Definição do arquivo de log
const LOG_FILE = __DIR__ . '/webhook_log.txt';
// API Key do Asaas
$asaas_api_key = $_SERVER['ASAAS_KEY'] ?? $_ENV['ASAAS_KEY'] ?? getenv('ASAAS_KEY');

// Função de Log
function write_log($message)
{
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

// 2. LOG INICIAL
write_log("--- Webhook Iniciado (Asaas v3) ---");

if (!$asaas_api_key) {
    write_log("ERRO: Variável de ambiente ASAAS_API_KEY não configurada.");
    exit;
}

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// Validação do Payload
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
$external_reference = $payment_data['externalReference'] ?? '';

write_log("Evento: $event | Pagamento ID: $payment_id | Ref: $external_reference");

if (empty($payment_id)) {
    write_log("ERRO: ID do pagamento não encontrado.");
    exit;
}

// 3. DESCOBRIR O BANCO/SISTEMA
// O sistema espera external_reference no formato: "MENSALIDADE-sistema-..."
$nome_banco = '';

// Tenta pegar da URL primeiro (fallback/legado)
if (isset($_GET['banco']) && !empty($_GET['banco'])) {
    $nome_banco = strtolower($_GET['banco']);
}
// Se não veio na URL, tenta extrair da referência
elseif (!empty($external_reference)) {
    $parts = explode('-', $external_reference); // Ex: MENSALIDADE-TOLEDO-65a4b...
    if (count($parts) >= 2) {
        $nome_banco = strtolower($parts[1]); // Pega 'toledo'
    }
}

if (empty($nome_banco)) {
    write_log("ERRO: Não foi possível identificar o banco (nem via URL, nem via externalReference).");
    exit;
}

// 4. CONEXÃO COM BANCO
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
        write_log("ERRO: Banco '$nome_banco' desconhecido/inválido.");
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

    // 5. CONSULTA API ASAAS (Confirmação dupla de segurança)
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
    // external_reference já foi extraído do payload inicial

    write_log("Asaas Resposta: Status=$status_asaas");

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
        write_log("INFO: Status Asaas '$status_asaas' não mapeado para ação final.");
        $conn->close();
        exit;
    }

    // 6. ATUALIZA BANCO
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
        write_log("SUCESSO: Pagamento atualizado para '$status_sistema'.");
    } else {
        write_log("INFO: Nenhuma linha afetada (já atualizado ou ref não encontrada).");
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    write_log("ERRO EXCEPTION: " . $e->getMessage());
}
exit;
