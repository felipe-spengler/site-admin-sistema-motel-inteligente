<?php
// ATENÇÃO: Use estas linhas APENAS em ambiente de TESTE/DESENVOLVIMENTO.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurações básicas
date_default_timezone_set('America/Sao_Paulo');

// API Key do Asaas
$asaas_api_key = $_SERVER['ASAAS_KEY'] ?? $_ENV['ASAAS_KEY'] ?? getenv('ASAAS_KEY');

// 1. Pega parâmetros
$sistema = isset($_GET['sistema']) ? strtolower($_GET['sistema']) : '';
$valor = isset($_GET['valor']) ? (float) $_GET['valor'] : 0.0;

if (empty($sistema) || $valor <= 0) {
    die('<div style="color: red; padding: 20px;">Parâmetros de sistema ou valor inválidos.</div>');
}

// 2. Determina o caminho da conexão e o nome do banco para o Webhook
$conexao_path = '';
$nome_banco = '';

switch ($sistema) {
    case 'abelardo':
        $conexao_path = '../conexaoAbelardo.php';
        $nome_banco = 'abelardo';
        break;
    case 'toledo':
        $conexao_path = '../conexao2.php';
        $nome_banco = 'toledo';
        break;
    case 'xanxere':
        $conexao_path = '../conexaoXanxere.php';
        $nome_banco = 'xanxere';
        break;
    default:
        die('<div style="color: red; padding: 20px;">Sistema não reconhecido.</div>');
}

// Verifica se o arquivo de conexão existe
if (!file_exists($conexao_path)) {
    die('<div style="color: red; padding: 20px;">Arquivo de conexão não encontrado.</div>');
}

// Inclui e conecta ao banco
include $conexao_path;
$conn = conectarAoBanco();

// Gera um ID de referência único para este pagamento
// Formato esperado pelo Webhook: MENSALIDADE-SISTEMA-ID
$external_reference = "MENSALIDADE-{$sistema}-" . uniqid();

// Data de hoje (referente a Outubro/2025 no seu contexto)
$referente_data = date('Y-m-01');

// Variáveis para a tela
$qr_code_base64 = '';
$pix_copia_cola = '';
$payment_id_asaas = ''; // Agora é string (ID do Asaas, ex: "pay_...")

/**
 * Função auxiliar para chamar a API do Asaas via cURL
 */
function asaas_request($method, $endpoint, $data = null)
{
    global $asaas_api_key;
    $url = "https://api.asaas.com/v3" . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = [
        "access_token: " . $asaas_api_key,
        "Content-Type: application/json"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code >= 400 || !$response) {
        throw new Exception("Erro Asaas ($http_code): " . ($response ?: $curl_error));
    }

    return json_decode($response, true);
}

try {
    if (!$asaas_api_key) {
        throw new Exception("Chave de API do Asaas não configurada.");
    }

    // 3. Obter ou Criar Cliente no Asaas
    // Como não temos dados do usuário, cria um cliente genérico para o Sistema
    // Tenta criar (se já existir com esse email, o Asaas pode retornar erro 400 ou sucesso duplicado, 
    // mas o ideal é buscar. Para simplificar e evitar falha se o email já existe, vamos usar um email único ou fixo?)
    // Melhor abordagem simples: Criar um consumidor específico para esse pagamento ou sistema.
    $cliente_nome = "Cliente Sistema " . ucfirst($sistema);
    $cliente_email = "sistema.{$sistema}@motelinteligente.com"; // Email dummy fixo para o sistema

    // Buscar cliente pelo email para não ficar criando mil duplicados
    $clientes_busca = asaas_request('GET', "/customers?email=" . urlencode($cliente_email));
    $customer_id = '';

    if (!empty($clientes_busca['data'])) {
        $customer_id = $clientes_busca['data'][0]['id'];
    } else {
        // Cria novo
        $novo_cliente = asaas_request('POST', '/customers', [
            'name' => $cliente_nome,
            'email' => $cliente_email
        ]);
        $customer_id = $novo_cliente['id'];
    }

    // 4. Criar Cobrança (Payment)
    $payment_data = [
        'customer' => $customer_id,
        'billingType' => 'PIX',
        'value' => round($valor, 2),
        'dueDate' => date('Y-m-d'), // Vence hoje
        'description' => "Mensalidade Sistema " . ucfirst($sistema),
        'externalReference' => $external_reference
    ];

    $cobranca = asaas_request('POST', '/payments', $payment_data);
    $payment_id_asaas = $cobranca['id'];

    if (empty($payment_id_asaas)) {
        throw new Exception("Falha ao criar cobrança no Asaas.");
    }

    // 5. Obter QR Code e Copia e Cola
    $qr_data = asaas_request('GET', "/payments/{$payment_id_asaas}/pixQrCode");

    $qr_code_base64 = $qr_data['encodedImage']; // Base64 da imagem
    $pix_copia_cola = $qr_data['payload'];      // String copia e cola

    // 6. Salva o registro no Banco de Dados (Status: pending)
    $sql = "INSERT INTO mensalidade (
        referente, 
        valor, 
        metodo, 
        status, 
        external_reference, 
        transaction_id
    ) VALUES (
        ?, ?, 'pix', 'pending', ?, ?
    )";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sdss", $referente_data, $valor, $external_reference, $payment_id_asaas);
    $stmt->execute();
    $stmt->close();

    $conn->close();

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    die('<div style="color: red; padding: 20px;">Erro ao gerar pagamento (Asaas): ' . $e->getMessage() . '</div>');
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento PIX - <?= ucfirst(htmlspecialchars($sistema)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .container {
            max-width: 500px;
            margin-top: 50px;
        }

        .qr-code-box {
            max-width: 250px;
            margin: 0 auto 20px auto;
            border: 2px solid #000;
            padding: 5px;
        }

        .qr-code-img {
            max-width: 100%;
            height: auto;
        }

        .copia-cola {
            cursor: pointer;
            word-break: break-all;
        }
    </style>
</head>

<body>

    <div class="container text-center">
        <h3 class="mb-4">Pagamento PIX (Asaas) - Sistema: <?= ucfirst(htmlspecialchars($sistema)) ?></h3>
        <div id="status-box" class="alert alert-info" role="alert">
            Aguardando pagamento... Não feche esta tela.
        </div>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                Total a Pagar: R$ <?= number_format($valor, 2, ',', '.') ?>
            </div>
            <div class="card-body">
                <p>Escaneie o QR Code ou use o Pix Copia e Cola:</p>

                <?php if ($qr_code_base64): ?>
                    <div class="qr-code-box">
                        <img src="data:image/jpeg;base64,<?= htmlspecialchars($qr_code_base64) ?>" class="qr-code-img"
                            alt="QR Code PIX">
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">Não foi possível gerar o QR Code.</div>
                <?php endif; ?>

                <p class="text-muted small mb-3">
                    <strong>Transaction ID (Asaas):</strong> <span
                        id="paymentIdDisplay"><?= htmlspecialchars($payment_id_asaas) ?></span>
                </p>

                <div class="form-floating mb-3">
                    <textarea class="form-control copia-cola" id="pixCopiaCola" readonly
                        style="height: 100px;"><?= htmlspecialchars($pix_copia_cola) ?></textarea>
                    <label for="pixCopiaCola">Pix Copia e Cola</label>
                </div>
                <button class="btn btn-outline-primary w-100" onclick="copiarPix()">Copiar Código</button>
            </div>
        </div>

        <p class="text-muted small">ID de Referência Externa: <?= htmlspecialchars($external_reference) ?></p>

    </div>

    <script>
        const PAYMENT_ID = '<?= $payment_id_asaas ?>'; // Agora é String (ID do Asaas)
        const SISTEMA = '<?= $sistema ?>';

        function copiarPix() {
            const pixArea = document.getElementById('pixCopiaCola');
            pixArea.select();
            document.execCommand('copy');
            alert('Código PIX copiado!');
        }

        function verificarStatus() {
            $.ajax({
                url: 'verificastatus.php',
                type: 'GET',
                data: {
                    sistema: SISTEMA,
                    payment_id: PAYMENT_ID // Envia o ID string do Asaas
                },
                success: function (response) {
                    const status = response.status;
                    const statusBox = $('#status-box');

                    if (status === 'approved') {
                        statusBox.removeClass('alert-info alert-warning').addClass('alert-success').html('<strong>Pagamento APROVADO!</strong> Você será redirecionado em instantes.');
                        clearInterval(intervaloVerificacao);
                        setTimeout(() => {
                            window.location.href = 'sucesso.php?sistema=' + SISTEMA; // Redireciona para tela de sucesso
                        }, 3000);
                    } else if (status === 'rejected' || status === 'cancelled') {
                        statusBox.removeClass('alert-info').addClass('alert-danger').html('<strong>Pagamento REJEITADO/CANCELADO.</strong> Por favor, tente novamente.');
                        clearInterval(intervaloVerificacao);
                    } else if (status === 'pending') {
                        statusBox.removeClass('alert-success alert-danger').addClass('alert-info').html('Aguardando pagamento... Verificando novamente.');
                    } else if (status === 'not_found' || status === 'error') {
                        // Caso o AJAX tenha retornado erro do PHP. 
                        statusBox.removeClass('alert-info').addClass('alert-warning').html('Aguardando pagamento... Erro ao buscar status: ' + (response.message || 'Desconhecido'));
                        console.error("Erro no status check:", response);
                    } else {
                        statusBox.removeClass('alert-success alert-danger').addClass('alert-warning').html('Status desconhecido: ' + status);
                    }
                },
                error: function (xhr, status, error) {
                    // Captura falhas de rede ou erro HTTP 500 no PHP
                    console.error("Erro ao verificar status. Falha AJAX:", status, error, xhr.responseText);
                    $('#status-box').removeClass('alert-info').addClass('alert-danger').html('<strong>ERRO DE COMUNICAÇÃO:</strong> Verifique o console para detalhes.');
                }
            });
        }

        // Inicia a verificação a cada 10 segundos (um pouco mais rápido)
        const intervaloVerificacao = setInterval(verificarStatus, 10000);

        // Verifica uma vez ao carregar
        $(document).ready(verificarStatus);
    </script>
</body>

</html>