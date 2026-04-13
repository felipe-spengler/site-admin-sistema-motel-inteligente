<?php
header('Content-Type: application/json');
require_once '../conexao2.php';

$filial = isset($_POST['filial']) ? strtolower($_POST['filial']) : '';
$quarto = isset($_POST['quarto']) ? intval($_POST['quarto']) : 0;
$itens = isset($_POST['itens']) ? $_POST['itens'] : '';
$total = isset($_POST['total']) ? floatval($_POST['total']) : 0;

if (empty($filial) || $quarto <= 0 || empty($itens)) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
    exit;
}

// Inclusão da conexão correta
switch ($filial) {
    case "abelardo":
        include_once '../conexaoAbelardo.php';
        break;
    case "toledo":
        include_once '../conexao2.php';
        break;
    case "xanxere":
        include_once '../conexaoXanxere.php';
        break;
}

$mysqli = conectarAoBanco();
if ($mysqli->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco.']);
    exit;
}

// 1. Salva no Banco de Dados (Backup e fonte da verdade)
$stmt = $mysqli->prepare("INSERT INTO pedidos_online (numeroquarto, itens, valor_total, status) VALUES (?, ?, ?, 'pendente')");
$stmt->bind_param("isd", $quarto, $itens, $total);
$salvou = $stmt->execute();
$stmt->close();

if ($salvou) {
    // 2. Tenta notificar o Java via PUSH (Real-time)
    // Busca o IP do Motel
    $resIp = $mysqli->query("SELECT meuip FROM configuracoes LIMIT 1");
    if ($resIp && $row = $resIp->fetch_assoc()) {
        $ip = $row['meuip'];
        if (!empty($ip)) {
            $url = "http://$ip:1521/receberPedido";
            $data = json_encode(['quarto' => $quarto, 'itens' => $itens]);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Timeout curto para não travar o usuário
            curl_exec($ch);
            curl_close($ch);
        }
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => $mysqli->error]);
}

$mysqli->close();
