<?php
// === INÍCIO DAS CORREÇÕES DE SEGURANÇA E SAÍDA ===
// 1. Desativa a exibição de NOTICES e WARNINGS que poderiam poluir a saída JSON
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); 
// 2. Inicia o buffer de saída para controlar o que é enviado ao navegador
ob_start();
// === FIM DAS CORREÇÕES DE SEGURANÇA E SAÍDA ===


// Define um limite de tempo para o script (útil para requisições cURL)
set_time_limit(60);

// --- CONFIGURAÇÃO: COLOQUE SEUS TOKENS REAIS ---
const ACCESS_TOKEN = 'TEST-1718861622321115-092422-391a0efbf673cb5c2f03a93531114bfb-151672516';
const PUBLIC_KEY = 'TEST-910d4ced-025f-4a32-a943-387721db7c6a';
const NOTIFICATION_URL = 'https://motelinteligente.com/api/webhook_notificacao.php'; 
const API_URL = 'https://api.mercadopago.com/v1/payments';

// 1. Receber valor via GET
$valor_bruto = filter_input(INPUT_GET, 'valor', FILTER_VALIDATE_FLOAT);
$valor_final = $valor_bruto > 0 ? $valor_bruto : 10.00;

// 2. Lógica para processar o pagamento (BACKEND)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'])) {
    processPayment();
    exit;
}

// ----------------------------------------------------
// --- FUNÇÃO PARA PROCESSAMENTO DO PAGAMENTO NO SERVIDOR (BACK-END) ---
// ----------------------------------------------------
function processPayment() {
    // Limpa qualquer saída acidental antes de enviar o JSON
    ob_clean(); 
    header('Content-Type: application/json');

    $token = $_POST['token'] ?? null;
    $email = $_POST['cardholderEmail'] ?? null;
    $installments = (int)($_POST['installments'] ?? 1);
    $transaction_amount = (float)($_POST['transactionAmount'] ?? 0.00);
    $payment_method_id = $_POST['paymentMethodId'] ?? null;
    $issuer_id = $_POST['issuerId'] ?? null;

    // A validação agora é mais detalhada para identificar o que falhou
    if (!$token || !$email || $transaction_amount <= 0 || !$payment_method_id) {
        http_response_code(400);
        
        $missing_fields = [];
        if (!$token) $missing_fields[] = 'Token';
        if (!$email) $missing_fields[] = 'Email';
        if ($transaction_amount <= 0) $missing_fields[] = 'Valor';
        if (!$payment_method_id) $missing_fields[] = 'PaymentMethodId (ID do método de pagamento)';

        echo json_encode([
            'status' => 'rejected',
            'status_detail' => 'Dados de pagamento incompletos ou inválidos.',
            'error' => ['message' => 'Faltando campos: ' . implode(', ', $missing_fields)]
        ]);
        return;
    }

    $payload = [
        "transaction_amount" => $transaction_amount,
        "token" => $token,
        "installments" => $installments,
        "payment_method_id" => $payment_method_id,
        "issuer_id" => $issuer_id,
        "description" => "Pagamento de Serviço/Produto via Cartão",
        "external_reference" => "PEDIDO-CARD-" . time(),
        "notification_url" => NOTIFICATION_URL,
        "payer" => [
            "email" => $email,
            "identification" => [
                "type" => $_POST['identificationType'] ?? 'CPF',
                "number" => $_POST['identificationNumber'] ?? '',
            ],
        ],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, API_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . ACCESS_TOKEN,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . uniqid(),
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch); // Captura erro de conexão
    curl_close($ch);

    // --- TRATAMENTO DE ERRO DE CONEXÃO cURL ---
    if ($curl_error) {
        http_response_code(500);
        echo json_encode([
            'status' => 'rejected',
            'status_detail' => 'Erro interno de comunicação cURL.',
            'error' => ['message' => 'Falha de conexão com a API do Mercado Pago: ' . $curl_error]
        ]);
        return;
    }
    
    $response_data = json_decode($response, true);

    // --- TRATAMENTO DE RESPOSTA NÃO JSON (Rara, mas evita o TypeError) ---
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode([
            'status' => 'rejected',
            'status_detail' => 'A API do Mercado Pago retornou um formato inválido.',
            'error' => ['message' => 'A resposta do Mercado Pago não pôde ser lida.']
        ]);
        return;
    }

    if ($http_code == 201) {
        echo json_encode([
            'status' => $response_data['status'] ?? 'pending',
            'status_detail' => $response_data['status_detail'] ?? 'Pagamento em processo.',
            'id' => $response_data['id']
        ]);
    } else {
        http_response_code($http_code);
        echo json_encode([
            'status' => 'rejected',
            'status_detail' => $response_data['message'] ?? 'Erro desconhecido da API. Código: ' . $http_code,
            'error' => $response_data
        ]);
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <title>Pagamento com Cartão - Mercado Pago</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <script src="https://sdk.mercadopago.com/js/v2"></script>

    <style>
        body { font-family: sans-serif; text-align: center; margin: 20px; background-color: #f4f4f4; }
        .card-container { margin: 20px auto; padding: 20px; border: 1px solid #ccc; max-width: 400px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); background-color: #fff; text-align: left; }
        label { display: block; margin-top: 10px; font-weight: bold; font-size: 14px; color: #333; }
        .mp-iframe-container { width: 100%; height: 40px; padding: 8px; margin-top: 5px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; background-color: #fff; }
        
        input, select { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .row { display: flex; gap: 10px; margin-bottom: 5px; }
        .half-width { flex: 1; }
        button { background: #009ee3; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; display: block; width: 100%; margin-top: 20px; font-size: 16px; transition: background 0.3s; }
        button:hover { background: #007bb6; }
    </style>
</head>
<body>
    <div class="card-container">
        <h1>Pagar com Cartão</h1>
        <h3>Valor: R$ <?php echo number_format($valor_final, 2, ',', '.'); ?></h3>

        <form id="payment-form" method="POST" action="card_payment.php"> 
            
            <input type="hidden" name="token" id="token">
            <input type="hidden" name="transactionAmount" value="<?php echo $valor_final; ?>">
            <input type="hidden" name="paymentMethodId" id="paymentMethodId">
            <input type="hidden" name="issuerId" id="issuerId"> 

            <label>Número do Cartão</label>
            <div id="form-checkout__cardNumber" class="mp-iframe-container"></div>

            <div class="row">
                <div class="half-width">
                    <label>Vencimento</label>
                    <div id="form-checkout__expirationDate" class="mp-iframe-container"></div>
                </div>
                <div class="half-width">
                    <label>Código de Segurança</label>
                    <div id="form-checkout__securityCode" class="mp-iframe-container"></div>
                </div>
            </div>

            <label for="form-checkout__cardholderName">Nome do Titular</label>
            <input type="text" id="form-checkout__cardholderName" name="cardholderName" required>

            <label for="form-checkout__cardholderEmail">E-mail</label>
            <input type="email" id="form-checkout__cardholderEmail" name="cardholderEmail" required>

            <input type="hidden" id="form-checkout__identificationType" name="identificationType" value="CPF">
            
            <label for="form-checkout__identificationNumber">CPF do Titular</label>
            <input type="text" id="form-checkout__identificationNumber" name="identificationNumber" required>

            <select id="form-checkout__issuer" name="issuerId" style="display:none;"></select>
            
            <label for="form-checkout__installments">Parcelas</label>
            <select id="form-checkout__installments" name="installments" required></select>

            <button type="submit" id="form-checkout__submit">Pagar</button>

            <div id="loading" style="display:none; text-align:center; margin-top:15px; color:#009ee3;">Processando...</div>
            <div id="payment-status" style="margin-top:15px; padding:10px; border-radius:4px; font-weight:bold;"></div>
        </form>
    </div>

    <script>
        const PUBLIC_KEY = '<?php echo PUBLIC_KEY; ?>';
        const VALOR_FINAL = '<?php echo $valor_final; ?>';
        
        const mp = new MercadoPago(PUBLIC_KEY, { locale: 'pt-BR' });
        const paymentForm = document.getElementById('payment-form');
        const submitButton = document.getElementById('form-checkout__submit');
        const loadingDiv = document.getElementById('loading');
        const statusDiv = document.getElementById('payment-status');
        const paymentMethodIdField = document.getElementById('paymentMethodId');
        const issuerIdField = document.getElementById('issuerId');

        const cardForm = mp.cardForm({
            amount: VALOR_FINAL,
            iframe: true,
            form: {
                id: "payment-form",
                cardNumber: { id: "form-checkout__cardNumber", placeholder: "Número do cartão" },
                expirationDate: { id: "form-checkout__expirationDate", placeholder: "MM/AA" },
                securityCode: { id: "form-checkout__securityCode", placeholder: "CVV" },
                cardholderName: { id: "form-checkout__cardholderName" },
                cardholderEmail: { id: "form-checkout__cardholderEmail" },
                installments: { id: "form-checkout__installments" },
                identificationType: { id: "form-checkout__identificationType" }, 
                identificationNumber: { id: "form-checkout__identificationNumber" },
                issuer: { id: "form-checkout__issuer" },
            },
            callbacks: {
                onFormMounted: error => {
                    if (error) {
                         statusDiv.textContent = '❌ Erro ao carregar o formulário. Verifique seu console (F12).';
                         statusDiv.style.color = 'red';
                         statusDiv.style.backgroundColor = '#ffebe6';
                         console.error("Erro ao montar o formulário:", error);
                         return;
                    }
                    console.log("Formulário montado!");
                },
                
                onCardBinChange: async (bin) => {
                    if (bin.length >= 6) {
                        try {
                            const { payerCosts, paymentMethod } = await mp.getInstallments({
                                amount: VALOR_FINAL,
                                bin: bin,
                            });
                            
                            // *** DEBUG: Garante que os IDs estão sendo preenchidos ***
                            console.log("Payment Method ID:", paymentMethod[0].id);
                            console.log("Issuer ID:", payerCosts[0].issuer.id);
                            // *******************************************************

                            paymentMethodIdField.value = paymentMethod[0].id;
                            issuerIdField.value = payerCosts[0].issuer.id;
                            
                            const installmentSelect = document.getElementById('form-checkout__installments');
                            installmentSelect.innerHTML = '<option value="" disabled selected>Selecione as parcelas</option>';
                            
                            payerCosts[0].installments.forEach(installment => {
                                const option = document.createElement('option');
                                option.value = installment.installments;
                                option.textContent = `${installment.installments}x de R$ ${installment.installment_amount.toFixed(2).replace('.', ',')} (Total: R$ ${installment.total_amount.toFixed(2).replace('.', ',')})`;
                                installmentSelect.appendChild(option);
                            });

                        } catch (error) {
                            console.error("Erro ao buscar parcelas:", error);
                        }
                    } else {
                        paymentMethodIdField.value = '';
                        issuerIdField.value = '';
                    }
                },
                
                onSubmit: event => {
                    event.preventDefault();
                    
                    const formData = cardForm.getCardFormData();

                    // VERIFICAÇÃO FINAL: Garante que os IDs críticos foram preenchidos
                    if (!formData.paymentMethodId) {
                        statusDiv.style.color = 'red';
                        statusDiv.style.backgroundColor = '#ffebe6';
                        statusDiv.textContent = '❌ Erro de validação: Insira o cartão completo e aguarde as parcelas serem carregadas.';
                        console.error("Payment Method ID não preenchido.");
                        return; 
                    }
                    
                    submitButton.disabled = true;
                    loadingDiv.style.display = 'block';
                    statusDiv.textContent = '';
                    statusDiv.style.backgroundColor = 'transparent';

                    cardForm.createCardToken().then(response => {
                        document.getElementById("token").value = response.token;
                        
                        document.getElementById("paymentMethodId").value = formData.paymentMethodId;
                        document.getElementById("issuerId").value = formData.issuerId;
                        
                        const postData = new FormData(paymentForm);

                        fetch(paymentForm.action || window.location.href, {
                            method: 'POST',
                            body: postData
                        })
                        .then(res => {
                            return res.json();
                        })
                        .then(data => {
                            console.log("Resposta final do Servidor:", data);
                            submitButton.disabled = false;
                            loadingDiv.style.display = 'none';

                            statusDiv.style.backgroundColor = (data.status === 'approved') ? '#e6fff2' : '#ffebe6';
                            if (data.status === 'approved') {
                                statusDiv.style.color = 'green';
                                statusDiv.textContent = `✅ Pagamento Aprovado! ID: ${data.id}`;
                            } else if (data.status === 'pending') {
                                statusDiv.style.color = 'orange';
                                statusDiv.textContent = `⏳ Pagamento Pendente: ${data.status_detail}`;
                            } else {
                                // --- CORREÇÃO APLICADA AQUI! ---
                                statusDiv.style.color = 'red';
                                
                                // Tenta ler a mensagem aninhada, se falhar, usa o status_detail
                                const detail = (data.error && data.error.message) ? data.error.message : data.status_detail;
                                
                                statusDiv.textContent = `❌ Pagamento Rejeitado: ${detail || 'Erro desconhecido.'}`;
                                // -------------------------------
                            }
                        })
                        .catch(error => {
                            console.error("Erro fatal na comunicação (Fetch/JSON):", error);
                            submitButton.disabled = false;
                            loadingDiv.style.display = 'none';
                            statusDiv.style.color = 'red';
                            statusDiv.style.backgroundColor = '#ffebe6';
                            statusDiv.textContent = '❌ Erro na comunicação com o servidor. (Verifique o console para detalhes brutos).';
                        });

                    }).catch(error => {
                        console.error("Erro ao tokenizar (createCardToken):", error);
                        submitButton.disabled = false;
                        loadingDiv.style.display = 'none';
                        statusDiv.style.color = 'red';
                        statusDiv.style.backgroundColor = '#ffebe6';
                        statusDiv.textContent = '❌ Erro na tokenização. Verifique os dados do cartão.';
                    });
                }
            }
        });
    </script>
</body>
</html>