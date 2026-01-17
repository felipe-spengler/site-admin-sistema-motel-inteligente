<?php
// Define que o conteúdo é JSON
header('Content-Type: application/json');

// 1. RESPONDE IMEDIATAMENTE (Obrigatório para MP - evita timeout)
http_response_code(200);

// Configuração básica
date_default_timezone_set('America/Sao_Paulo');

// Definição do arquivo de log
const LOG_FILE = __DIR__ . '/webhook_log.txt';
// Token de Produção
const MP_ACCESS_TOKEN = 'APP_USR-1718861622321115-092422-dbec0bf923560b558e784f323fcf069b-151672516';

// Função de Log
function write_log($message)
{
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

// 2. LOG INICIAL
write_log("--- Webhook Iniciado (v2 - cURL) ---");

$nome_banco = isset($_GET['banco']) ? strtolower($_GET['banco']) : '';
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// write_log("Banco: " . $nome_banco);
// write_log("Payload: " . $raw_input);

if (empty($nome_banco)) {
    write_log("ERRO: Parâmetro 'banco' não informado na URL (ex: ?banco=toledo).");
    exit;
}

if (empty($data['type']) || $data['type'] !== 'payment') {
    // Ignorar logs excessivos de tópicos irrelevantes, logar apenas se for erro óbvio ou teste
    if (!empty($data) && ($data['type'] ?? '') !== 'payment') {
        // write_log("Ignorado: Tipo não é payment.");
    } else {
        write_log("ERRO: Payload inválido ou não é 'payment'.");
    }
    exit;
}

$payment_id = $data['data']['id'] ?? 0;
write_log("Processando pagamento ID: " . $payment_id);

if ($payment_id == 0) {
    write_log("ERRO: ID não encontrado.");
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

    // 4. CONSULTA API MERCADO PAGO VIA CURL (Sem SDK)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments/" . $payment_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . MP_ACCESS_TOKEN,
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code != 200 || !$response) {
        write_log("ERRO MP API: HTTP $http_code. Curl Error: $curl_error");
        $conn->close();
        exit;
    }

    $payment_info = json_decode($response, true);

    // Pegar dados do retorno
    $status_mp = $payment_info['status'] ?? '';
    // external_reference é onde guardamos o "MENSALIDADE-cidade-id"
    $external_reference = $payment_info['external_reference'] ?? '';

    write_log("MP Resposta: Status=$status_mp | Ref=$external_reference");

    // Valida se status é final
    $status_finais = ['approved', 'rejected', 'cancelled', 'refunded'];
    if (!in_array($status_mp, $status_finais)) {
        write_log("INFO: Status '$status_mp' não é final. Aguardando atualização.");
        $conn->close();
        exit;
    }

    if (empty($external_reference)) {
        write_log("ERRO: external_reference vazio no pagamento MP.");
        $conn->close();
        exit;
    }

    // 5. ATUALIZA BANCO
    $sql = "UPDATE mensalidade 
            SET status = ?, 
                transaction_id = ?, 
                atualizado_em = NOW() 
            WHERE external_reference = ? AND status = 'pending'"; // Só atualiza se ainda estava pendente

    $stmt = $conn->prepare($sql);

    // sss = string, string, string
    $stmt->bind_param("sss", $status_mp, $payment_id, $external_reference);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        write_log("SUCESSO: Pagamento atualizado no banco.");
    } else {
        write_log("INFO: Nenhuma linha afetada (provavelmente já estava atualizado ou ref não encontrada).");
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    write_log("ERRO EXCEPTION: " . $e->getMessage());
}
exit;
