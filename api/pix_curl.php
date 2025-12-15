<?php
// ATENÇÃO: Verifique se o cURL está habilitado no seu XAMPP/Hostinger.

// --- CONFIGURAÇÃO: COLOQUE SEU ACCESS TOKEN REAL ---
// Seu Access Token que começa com APP_USR-...
define('ACCESS_TOKEN', getenv('MP_ACCESS_TOKEN') ?: '');

// URL para onde o Mercado Pago irá notificar a aprovação (WEBHOOK)
const NOTIFICATION_URL = 'https://motelinteligente.com/api/webhook_notificacao.php';

// URL da API para criar um pagamento
const API_URL = 'https://api.mercadopago.com/v1/payments';

// --- FIM CONFIGURAÇÃO ---

// 1. Receber o valor via GET
$valor_bruto = isset($_GET['valor']) ? floatval($_GET['valor']) : 0;

if (!$valor_bruto || $valor_bruto <= 0) {
    die("Erro: Valor inválido ou não fornecido na URL. Use ?valor=XX.XX");
}

// 2. Montar o payload (corpo da requisição JSON)
$payload = [
    "transaction_amount" => $valor_bruto,
    "payment_method_id" => "pix",
    "description" => "Pagamento de Serviço/Produto",
    "external_reference" => "PEDIDO-" . time(), // Seu ID de pedido
    "notification_url" => NOTIFICATION_URL,
    "payer" => [
        "email" => "pagador_teste_" . time() . "@teste.com",
        "first_name" => "Nome Teste",
        "last_name" => "Sobrenome Teste",
        "identification" => ["type" => "CPF", "number" => "11111111111"],
    ],
    // É recomendado usar o X-Idempotency-Key para evitar cobranças duplicadas
    "metadata" => [
        "idempotency_key" => uniqid(),
    ]
];

// 3. Inicializar e configurar o cURL
$ch = curl_init();

// Define a URL da API
curl_setopt($ch, CURLOPT_URL, API_URL);

// Define que é uma requisição POST
curl_setopt($ch, CURLOPT_POST, 1);

// Define que o resultado deve ser retornado como string
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Define o corpo da requisição (JSON)
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

// Define os headers (Cabeçalhos)
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . ACCESS_TOKEN,
    'Content-Type: application/json',
    'X-Idempotency-Key: ' . uniqid(), // Enviamos o cabeçalho de idempotência
));

// 4. Executar a requisição e capturar a resposta
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 5. Tratar a resposta
$payment_data = json_decode($response);

// Verifica se a requisição foi bem sucedida (código HTTP 201 Created)
if ($http_code == 201) {
    // 6. Exibir o QR Code e o Copia e Cola
    $qr_code = $payment_data->point_of_interaction->transaction_data->qr_code;
    $qr_code_base64 = $payment_data->point_of_interaction->transaction_data->qr_code_base64;

    // --- OUTPUT HTML/CSS BÁSICO (O mesmo que você já tinha) ---
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">

    <head>
        <title>Pagamento Pix - Mercado Pago</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            /* Seu CSS aqui */
            body {
                font-family: sans-serif;
                text-align: center;
                margin: 20px;
            }

            .pix-container {
                margin: 20px auto;
                padding: 20px;
                border: 1px solid #ccc;
                max-width: 400px;
                border-radius: 8px;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            }

            .qr-code img {
                max-width: 100%;
                height: auto;
                border: 1px solid #000;
                padding: 5px;
            }

            .copy-paste-code {
                background: #f0f0f0;
                padding: 10px;
                border-radius: 5px;
                word-break: break-all;
                margin-top: 15px;
                text-align: left;
                font-size: 14px;
            }

            .button {
                background: #009ee3;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                display: block;
                width: 100%;
                margin: 15px 0 5px 0;
                text-decoration: none;
                font-size: 16px;
            }

            .button:hover {
                background: #007bb6;
            }
        </style>
    </head>

    <body>
        <div class="pix-container">
            <h1>Pagamento PIX</h1>
            <h3>Valor a Pagar: R$ <?php echo number_format($valor_bruto, 2, ',', '.'); ?></h3>

            <div class="qr-code">
                <p>Escaneie o QR Code:</p>
                <img src="data:image/png;base64,<?php echo $qr_code_base64; ?>" alt="QR Code Pix">
            </div>

            <p>Ou use o Pix Copia e Cola:</p>
            <div class="copy-paste-code" id="pix-copy-paste-code"><?php echo htmlspecialchars($qr_code); ?></div>
            <button class="button" onclick="copyPixCode()">Copiar Código</button>

            <p><small>Aguardando confirmação de pagamento...</small></p>
        </div>

        <script>
            function copyPixCode() {
                const pixCode = document.getElementById('pix-copy-paste-code').innerText;
                navigator.clipboard.writeText(pixCode).then(function () {
                    alert('Código Pix Copia e Cola copiado com sucesso!');
                }, function (err) {
                    console.error('Erro ao copiar: ', err);
                    alert('Erro ao copiar. Tente selecionar e copiar o código acima manualmente.');
                });
            }
        </script>
    </body>

    </html>
    <?php
} else {
    // Exibir erro caso o HTTP Code não seja 201
    $error_message = isset($payment_data->message) ? $payment_data->message : "Erro desconhecido.";
    $error_details = isset($payment_data->error) ? $payment_data->error : "Sem detalhes.";

    echo "<h1>Erro ao criar o PIX (HTTP Code: {$http_code})</h1>";
    echo "<p>Mensagem: " . htmlspecialchars($error_message) . "</p>";
    echo "<p>Detalhe: " . htmlspecialchars($error_details) . "</p>";
    echo "<p>Verifique se o seu Access Token está correto.</p>";
}
?>