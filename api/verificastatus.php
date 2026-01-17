<?php
// ATENÇÃO: Habilite a exibição de erros APENAS em ambiente de teste
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

$sistema = isset($_GET['sistema']) ? strtolower($_GET['sistema']) : '';
$payment_id = isset($_GET['payment_id']) ? $_GET['payment_id'] : '';

// Log de recebimento de parâmetros
if (empty($sistema) || empty($payment_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Parâmetros inválidos ou ausentes.', 'sistema_recebido' => $sistema, 'payment_id_recebido' => $payment_id]);
    exit;
}

// 1. Determina o caminho da conexão
$conexao_path = '';
switch ($sistema) {
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
        echo json_encode(['status' => 'error', 'message' => 'Sistema não reconhecido: ' . $sistema]);
        exit;
}

if (!file_exists($conexao_path)) {
    echo json_encode(['status' => 'error', 'message' => 'Arquivo de conexão não encontrado: ' . $conexao_path]);
    exit;
}

include $conexao_path;

try {
    $conn = conectarAoBanco();

    // Usamos transaction_id, conforme corrigimos anteriormente
    $sql = "SELECT status FROM mensalidade WHERE transaction_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $status = 'not_found';
    if ($row = $result->fetch_assoc()) {
        $status = $row['status'];
    }

    $stmt->close();
    $conn->close();

    // Log de sucesso: Retorna o status encontrado
    echo json_encode(['status' => $status, 'debug_sql' => $sql, 'debug_sistema' => $sistema]);

} catch (Exception $e) {
    if (isset($conn))
        $conn->close();
    // Log de erro de banco de dados
    echo json_encode(['status' => 'exception', 'message' => $e->getMessage(), 'sql_error' => $sql]);
}
?>