<?php
// webhook_notificacao.php
// Recebe notificações do Mercado Pago e salva na tabela pagamento_site

date_default_timezone_set('America/Sao_Paulo');

// ==== CONEXÃO DIRETA MYSQL ====
$host = "mysql.sitioranchofundo.com";
$user = "ranchofundo";
$password = "SUA_SENHA_AQUI";   // 👉 coloque a senha real
$database = "ranchofundo";
$port = 3306;

$con = new mysqli($host, $user, $password, $database, $port);
if ($con->connect_error) {
    file_put_contents(__DIR__ . "/webhook_log.txt", "Erro conexão: " . $con->connect_error . "\n", FILE_APPEND);
    http_response_code(500);
    exit("Erro DB");
}
$con->set_charset("utf8mb4");

// ==== LOG SIMPLES ====
$raw = file_get_contents("php://input");
file_put_contents(__DIR__ . "/webhook_log.txt", date("Y-m-d H:i:s") . " - RAW: " . $raw . "\n", FILE_APPEND);

$data = json_decode($raw, true);
if (!$data) {
    http_response_code(400);
    exit("Nenhum dado recebido");
}

// ==== PROCESSA NOTIFICAÇÃO ====
if (isset($data["type"]) && $data["type"] === "payment") {

    $paymentId = $data["data"]["id"];

    // Consulta detalhes do pagamento na API Mercado Pago
    $access_token = "TEST-1718861622321115-092422-391a0efbf673cb5c2f03a93531114bfb-151672516";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments/" . $paymentId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $access_token
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $paymentInfo = json_decode($response, true);

    if ($paymentInfo) {
        $status       = $paymentInfo["status"]; // approved, pending, rejected
        $externalRef  = $paymentInfo["external_reference"];
        $valor        = $paymentInfo["transaction_amount"];
        $metodo       = $paymentInfo["payment_method_id"]; // pix, visa, mastercard etc.
        $lastDigits   = $paymentInfo["card"]["last_four_digits"] ?? null;
        $qr_code      = $paymentInfo["point_of_interaction"]["transaction_data"]["qr_code"] ?? null;
        $qr_code_base64 = $paymentInfo["point_of_interaction"]["transaction_data"]["qr_code_base64"] ?? null;

        // === Verifica se já existe esse pagamento ===
        $stmt = $con->prepare("SELECT id FROM pagamento_site WHERE transaction_id=?");
        $stmt->bind_param("s", $paymentId);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            // Atualiza status se já existir
            $stmtUpd = $con->prepare("UPDATE pagamento_site 
                SET status=?, atualizado_em=NOW() 
                WHERE transaction_id=?");
            $stmtUpd->bind_param("ss", $status, $paymentId);
            $stmtUpd->execute();
        } else {
            // Insere novo registro
            $stmtIns = $con->prepare("INSERT INTO pagamento_site 
                (id_reserva, metodo, status, valor, transaction_id, qr_code, qr_code_base64, card_last_digits, external_reference) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $idReserva = is_numeric($externalRef) ? intval($externalRef) : 0;

            $stmtIns->bind_param(
                "issdsssss",
                $idReserva,
                $metodo,
                $status,
                $valor,
                $paymentId,
                $qr_code,
                $qr_code_base64,
                $lastDigits,
                $externalRef
            );
            $stmtIns->execute();
        }

        // Log
        file_put_contents(__DIR__ . "/webhook_log.txt", "Pagamento $paymentId - Status: $status\n", FILE_APPEND);
    }
}

// Mercado Pago exige 200 OK
http_response_code(200);
echo "OK";
