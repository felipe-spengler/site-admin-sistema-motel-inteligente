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
// Constante do Mercado Pago (usada para consulta de segurança)
const MP_ACCESS_TOKEN = 'APP_USR-1718861622321115-092422-dbec0bf923560b558e784f323fcf069b-151672516';

// Função auxiliar para registrar eventos no arquivo
function write_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

// 2. PEGA OS DADOS DA NOTIFICAÇÃO E INICIA O LOG
write_log("--- Webhook Recebido ---");

$nome_banco = isset($_GET['banco']) ? strtolower($_GET['banco']) : '';
$data = json_decode(file_get_contents('php://input'), true);

write_log("Banco na URL: " . $nome_banco);
write_log("Payload MP: " . json_encode($data));

// Verifica se a notificação é válida e se é sobre um pagamento
if (empty($nome_banco) || empty($data['type']) || $data['type'] !== 'payment') {
    write_log("ERRO: Parâmetros iniciais inválidos ou tipo não é 'payment'.");
    exit;
}

$payment_id = $data['data']['id'] ?? 0; // ID do Mercado Pago (e.g., 128439664731)
write_log("ID de Pagamento MP (payment_id): " . $payment_id);

if ($payment_id == 0) {
    write_log("ERRO: payment_id não encontrado no payload.");
    exit;
}

// 3. DETERMINA O CAMINHO DA CONEXÃO
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
        write_log("ERRO: Banco desconhecido.");
        exit;
}

if (!file_exists($conexao_path)) {
    write_log("ERRO: Arquivo de conexão ($conexao_path) não encontrado.");
    exit;
}

include $conexao_path;

try {
    // 4. CONECTA E BUSCA O STATUS OFICIAL DO MERCADO PAGO
    $conn = conectarAoBanco(); 
    write_log("Conexão com o banco estabelecida com sucesso.");

    \MercadoPago\SDK::setAccessToken(MP_ACCESS_TOKEN);
    $payment_info = \MercadoPago\Payment::find_by_id($payment_id);
    
    $new_status = null;
    $mensalidade_id_sistema = null;
    
    if ($payment_info) {
        // CORREÇÃO: Recupera o external_reference (ID do seu sistema)
        $mensalidade_id_sistema = $payment_info->external_reference;
        $status_mp = $payment_info->status;
        write_log("Status retornado pelo MP: " . $status_mp);
        write_log("External Reference retornado pelo MP: " . $mensalidade_id_sistema);
        
        // Verifica se o status é final (approved, rejected, cancelled, refunded)
        if (in_array($status_mp, ['approved', 'rejected', 'cancelled', 'refunded'])) {
            $new_status = $status_mp;
        }
    } else {
        write_log("ERRO: Falha ao consultar o payment_id no Mercado Pago.");
    }

    if (!$new_status || empty($mensalidade_id_sistema)) {
        write_log("INFO: Status não é final ('$status_mp') ou external_reference não encontrado. Encerrando.");
        $conn->close();
        exit; 
    }

    // 5. ATUALIZA O STATUS NO BANCO DE DADOS
    // A busca agora é pela coluna 'external_reference', conforme sua tabela.
    $sql = "UPDATE mensalidade 
            SET status = ?, 
                transaction_id = ?, 
                atualizado_em = NOW() 
            WHERE external_reference = ? AND status = 'pending'"; 

    write_log("Query SQL preparada: $sql");
    write_log("Parâmetros: (Status: $new_status, MP ID: $payment_id, System ID: $mensalidade_id_sistema)");

    $stmt = $conn->prepare($sql);
    
    // Bind: 1. Novo Status (string), 2. ID do MP (string), 3. ID do seu sistema (string)
    // Usando 'sss' porque todos os seus valores (status, transaction_id, external_reference) são strings.
    $stmt->bind_param("sss", $new_status, $payment_id, $mensalidade_id_sistema); 
    $stmt->execute();
    
    $rows_affected = $conn->affected_rows; 
    write_log("RESULTADO: Linhas afetadas pelo UPDATE: " . $rows_affected);

    $stmt->close();
    $conn->close();
    
} catch (\Exception $e) {
    $error_message = $e->getMessage();
    write_log("ERRO GERAL (Exception): $error_message");
    if (isset($conn) && is_object($conn)) {
        $conn->close();
    }
}

write_log("--- Webhook Finalizado ---\n");
exit;