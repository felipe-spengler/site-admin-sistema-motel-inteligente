<?php
// ATENÇÃO: Use estas linhas APENAS em ambiente de TESTE/DESENVOLVIMENTO.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurações e includes
require __DIR__ . '/vendor/autoload.php'; // Caminho para o autoload do Mercado Pago (CORRIGIDO)
date_default_timezone_set('America/Sao_Paulo');

// Constantes do Mercado Pago (TESTE)
const MP_ACCESS_TOKEN = getenv('MP_ACCESS_TOKEN') ?: '';
const MP_PUBLIC_KEY = getenv('MP_PUBLIC_KEY') ?: '';
const URL_BASE_WEBHOOK = 'https://motelinteligente.com/api/webhook_sistema.php';

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

// Inclui e conecta ao banco (função deve ser 'conectarAoBanco()')
include $conexao_path;
$conn = conectarAoBanco();

// Gera um ID de referência único para este pagamento
$external_reference = "MENSALIDADE-{$sistema}-" . uniqid();

// Data de hoje (referente a Outubro/2025 no seu contexto)
$referente_data = date('Y-m-01');

// Variáveis para a tela
$qr_code_base64 = '';
$pix_copia_cola = '';
$payment_id_mp = 0; // Armazenará o ID da transação (transaction_id no seu BD)

try {
    // 3. Configura e Cria o Pagamento no Mercado Pago
    \MercadoPago\SDK::setAccessToken(MP_ACCESS_TOKEN);

    $payment = new \MercadoPago\Payment();
    $payment->transaction_amount = round($valor, 2);
    $payment->description = "Mensalidade Sistema " . ucfirst($sistema);
    $payment->payment_method_id = "pix";
    $payment->notification_url = URL_BASE_WEBHOOK . "?banco={$nome_banco}"; // Envia o nome do banco
    $payment->external_reference = $external_reference;

    // Dados do pagador (exemplo)
    $payment->payer = array(
        "email" => "pagador@teste.com",
        "first_name" => "Cliente",
        "last_name" => "Teste"
    );

    $payment->save(); // Gera o pagamento

    if ($payment->status == 'pending') {
        $payment_id_mp = $payment->id; // ID da transação no MP

        // Dados do PIX (CORREÇÃO DE ACESSO AO OBJETO)
        $qr_code_base64 = $payment->point_of_interaction->transaction_data->qr_code_base64;
        $pix_copia_cola = $payment->point_of_interaction->transaction_data->qr_code;

        // 4. Salva o registro no Banco de Dados (Status: pending)
        $sql = "INSERT INTO mensalidade (
            referente, 
            valor, 
            metodo, 
            status, 
            external_reference, 
            transaction_id /* <-- CORRIGIDO AQUI */
        ) VALUES (
            ?, ?, 'pix', 'pending', ?, ?
        )";

        $stmt = $conn->prepare($sql);
        // O $payment_id_mp é o ID da transação que será salvo em transaction_id
        $stmt->bind_param("sdsi", $referente_data, $valor, $external_reference, $payment_id_mp);
        $stmt->execute();
        $stmt->close();

    } else {
        // Se o MP não retornar 'pending' imediatamente
        throw new Exception("Erro ao gerar o PIX no Mercado Pago. Status retornado: " . $payment->status);
    }

    $conn->close();

} catch (Exception $e) {
    // Em caso de erro, exibe a mensagem de erro
    if (isset($conn) && is_object($conn)) {
        $conn->close();
    }
    die('<div style="color: red; padding: 20px;">Erro ao gerar pagamento: ' . $e->getMessage() . '</div>');
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

        /* MODIFICAÇÃO DE ESTILO: QR Code menor e centralizado */
        .qr-code-box {
            max-width: 250px;
            /* Define largura máxima */
            margin: 0 auto 20px auto;
            /* Centraliza e adiciona margem inferior */
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
        <h3 class="mb-4">Pagamento PIX - Sistema: <?= ucfirst(htmlspecialchars($sistema)) ?></h3>
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
                    <strong>Transaction ID (MP):</strong> <span
                        id="paymentIdDisplay"><?= htmlspecialchars($payment_id_mp) ?></span>
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
        const PAYMENT_ID = <?= $payment_id_mp ?>;
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
                    payment_id: PAYMENT_ID // O script verificastatus.php usa 'payment_id' para buscar em 'transaction_id'
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

        // Inicia a verificação a cada 15 segundos
        const intervaloVerificacao = setInterval(verificarStatus, 15000);

        // Verifica uma vez ao carregar
        $(document).ready(verificarStatus);
    </script>
</body>

</html>