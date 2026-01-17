<?php
// Define o valor base da mensalidade e a data de vencimento

ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
const VENCIMENTO_DIA = 10;
const MULTA_PERCENTUAL = 0.05; // 5%
const JUROS_DIARIO_PERCENTUAL = 0.002; // 0.2%

// 1. Pega e normaliza o parâmetro 'sistema'
$sistema = isset($_GET['sistema']) ? strtolower($_GET['sistema']) : '';
$nome_motel = '';

// 2. Inclui o arquivo de conexão correto (volta uma pasta)
$conexao_path = '';
switch ($sistema) {
    case 'abelardo':
        $conexao_path = '../conexaoAbelardo.php';
        $nome_motel = 'Motel Abelardo'; // NOVO: Define o nome amigável
        break;
    case 'toledo':
        $conexao_path = '../conexao2.php';
        $nome_motel = 'Motel Toledo'; // NOVO: Define o nome amigável
        break;
    case 'xanxere':
        $conexao_path = '../conexaoXanxere.php';
        $nome_motel = 'Motel Xanxerê'; // NOVO: Define o nome amigável
        break;
    default:
        // Se o sistema não for reconhecido, exibe erro e para
        // NOVO: Registra a tentativa de acesso inválida
        registrarAcessoLog('Acesso Inválido', $sistema);
        die('<div style="padding: 20px; color: red;">Sistema não reconhecido. Parâmetro inválido.</div>');
}
registrarAcessoLog($nome_motel, $sistema);
// Verifica se o arquivo existe antes de incluir (segurança)
if (!file_exists($conexao_path)) {
    die('<div style="padding: 20px; color: red;">Arquivo de conexão não encontrado: ' . htmlspecialchars($conexao_path) . '</div>');
}

// Inclui o arquivo que deve definir a variável $pdo (conexão PDO)
include $conexao_path;

// Se a conexão falhar dentro da função, a execução será interrompida (die).
$conn = conectarAoBanco();

// Variáveis de inicialização e totalização
$hoje = new DateTime();
$valor_base_mensalidade = 200.00; // Valor de fallback
$valor_total_mensalidades = 0.0;
$valor_total_multa = 0.0;
$valor_total_juros = 0.0;
$valor_total_a_pagar = 0.0;
$meses_em_aberto = 0;
$cobranca_detalhada = []; // Array para armazenar o cálculo de cada mês
$data_vencimento_mais_antigo = null; // Para definir o atraso geral

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("A conexão MySQLi não foi inicializada corretamente.");
    }

    // *************************************************************
    // ALTERAÇÃO CRÍTICA (3.1): Busca o valor do pagamento MAIS ANTIGO APROVADO
    // *************************************************************
    // Alterado: ORDER BY referente ASC (ordem crescente) e LIMIT 1
    $sql_first_paid = "SELECT valor, referente FROM mensalidade WHERE status = 'approved' ORDER BY referente ASC LIMIT 1";
    $result_first_paid = $conn->query($sql_first_paid);

    $first_payment = null;
    if ($result_first_paid && $result_first_paid->num_rows > 0) {
        $first_payment = $result_first_paid->fetch_assoc();
        $result_first_paid->free();
    }

    // Define o valor base usando o PRIMEIRO pagamento.
    // Se não encontrar, usa o valor padrão (200.00, por exemplo).
    $valor_base_mensalidade = $first_payment ? (float) $first_payment['valor'] : 200.00;

    // *************************************************************
    // FIM DA ALTERAÇÃO
    // *************************************************************

    $sql_last_paid = "SELECT referente FROM mensalidade WHERE status = 'approved' ORDER BY referente DESC LIMIT 1";
    $result_last_paid = $conn->query($sql_last_paid);

    $last_payment = null;
    if ($result_last_paid && $result_last_paid->num_rows > 0) {
        $last_payment = $result_last_paid->fetch_assoc();
        $result_last_paid->free();
    }

    if ($last_payment) {
        // Começa a cobrar o mês seguinte ao último pago
        // (Usa a data do ÚLTIMO pagamento para definir o MÊS DE INÍCIO DA COBRANÇA)
        $mes_iterator = new DateTime($last_payment['referente']);
        $mes_iterator->modify('first day of next month'); // Ex: Se pago Setembro, começa em Outubro
    } else {
        // Se nunca pagou, começa a cobrar no primeiro dia do mês atual
        $mes_iterator = new DateTime("first day of this month");
    }

    $referente_inicio = clone $mes_iterator;

    // PONTO DE PARADA: Limita ao final do mês atual. (JÁ ESTAVA CORRETO)
    $mes_parada = clone $hoje;

    // 3.3. LOOP para calcular a cobrança de CADA MÊS em aberto
    while ($mes_iterator < $mes_parada) {
        // ... (O restante da lógica de cálculo e totalização permanece o mesmo) ...

        $meses_em_aberto++;

        $vencimento = clone $mes_iterator;
        $vencimento->setDate($vencimento->format('Y'), $vencimento->format('m'), VENCIMENTO_DIA);

        // NOVO: Define o dia em que o atraso começa a ser cobrado (Dia 11)
        $inicio_cobranca_atraso = clone $vencimento;
        $inicio_cobranca_atraso->modify('+1 day'); // O dia seguinte ao vencimento.

        // ** Importante: $valor_mensalidade agora usa o valor_base_mensalidade
        // obtido do PRIMEIRO pagamento.
        $valor_mensalidade = $valor_base_mensalidade;
        $multa_mensal = 0.0;
        $juros_mensal = 0.0;
        $dias_atraso_mensal = 0;

        // Se a cobrança está atrasada: 
        // Compara se HOJE ($hoje) é maior ou igual ao dia em que a cobrança de atraso inicia ($inicio_cobranca_atraso).
        if ($hoje >= $inicio_cobranca_atraso) {

            // ... (A lógica de cálculo de Multa e Juros dentro do IF permanece a mesma) ...

            if ($data_vencimento_mais_antigo === null) {
                $data_vencimento_mais_antigo = $vencimento;
            }
            $interval = $hoje->diff($vencimento);
            $dias_atraso_mensal = $interval->days; // Se hoje for 11/10 e vencimento 10/10, o diff é 1 dia.
            $multa_mensal = $valor_mensalidade * MULTA_PERCENTUAL;
            $juros_mensal = $valor_mensalidade * JUROS_DIARIO_PERCENTUAL * $dias_atraso_mensal;
        }

        // 4. Armazena o detalhe
        $cobranca_detalhada[] = [
            'referente' => $mes_iterator->format('m/Y'),
            'valor_base' => $valor_mensalidade,
            'multa' => $multa_mensal,
            'juros' => $juros_mensal,
            'total' => $valor_mensalidade + $multa_mensal + $juros_mensal,
            'vencimento' => $vencimento,
            'atraso_dias' => $dias_atraso_mensal
        ];

        // 5. Totaliza os valores
        $valor_total_mensalidades += $valor_mensalidade;
        $valor_total_multa += $multa_mensal;
        $valor_total_juros += $juros_mensal;

        // Avança para o próximo mês
        $referente_final = clone $mes_iterator;
        $mes_iterator->modify('+1 month');
    }

    // ... (O restante do código, cálculo do total, formatação da data, etc., permanece o mesmo) ...

    if ($meses_em_aberto == 0) {
        $conn->close();
        die('<div class="alert alert-success" style="max-width: 500px; margin: 50px auto; text-align: center;">
             <strong>Mensalidade em Dia!</strong> Não há débitos em aberto no momento.
             </div>');
    }

    $valor_total_a_pagar = $valor_total_mensalidades + $valor_total_multa + $valor_total_juros;

    if ($meses_em_aberto > 0) {
        $primeiro_mes_cobrado = $cobranca_detalhada[0];

        // Se há mais de um mês, pega o último do array (que foi o último iterado e validado).
        if ($meses_em_aberto > 1) {
            $ultimo_mes_cobrado = end($cobranca_detalhada);
            // Formata o período
            $referente_mes_pt = "De " . $primeiro_mes_cobrado['referente'] . " até " . $ultimo_mes_cobrado['referente'];
        } else {
            // Apenas um mês em aberto
            $referente_mes_pt = $primeiro_mes_cobrado['referente'];
        }
    } else {
        // Caso de fallback (não deve ocorrer devido ao die() anterior, mas é seguro ter)
        $referente_mes_pt = "N/A";
    }

    $data_vencimento_exibicao = $data_vencimento_mais_antigo
        ? $data_vencimento_mais_antigo
        : (isset($cobranca_detalhada[0]) ? $cobranca_detalhada[0]['vencimento'] : null);

    $dias_atraso_exibicao = $data_vencimento_mais_antigo ? $data_vencimento_mais_antigo->diff($hoje)->days : 0;

    $url_pix = "motelinteligente.com/api/pagamento.php?sistema=" . urlencode($sistema) . "&valor=" . number_format($valor_total_a_pagar, 2, '.', '');

    $conn->close();

} catch (Exception $e) {
    // Em caso de erro na consulta, exibe a mensagem de erro (para debug)
    die('<div style="padding: 20px; color: red;">Erro na consulta ao banco de dados: ' . $e->getMessage() . '</div>');
}
function registrarAcessoLog(string $motel_name, string $system_param)
{
    // Garante que o fuso horário está correto, se necessário
    // date_default_timezone_set('America/Sao_Paulo'); 

    $timestamp = date('Y-m-d H:i:s');
    $log_message = sprintf(
        "[%s] ACESSO: %s (sistema=%s)\n",
        $timestamp,
        $motel_name,
        $system_param
    );

    // FILE_APPEND: Adiciona ao final do arquivo
    // LOCK_EX: Trava o arquivo durante a escrita para evitar problemas de concorrência
    $log_file = 'log_mensalidade.txt';
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensalidade - Sistema <?= ucfirst(htmlspecialchars($sistema)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }

        .card-mensalidade {
            max-width: 500px;
            margin: 50px auto;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .header-title {
            background-color: #007bff;
            color: white;
            padding: 15px;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            text-align: center;
        }

        .valor-total {
            font-size: 2.5rem;
            font-weight: bold;
            color: #28a745;
            /* Verde para destaque */
        }

        .atraso-alert {
            animation: fadeInOut 1.5s infinite alternate;
        }

        @keyframes fadeInOut {
            0% {
                opacity: 0.7;
            }

            100% {
                opacity: 1;
            }
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="card card-mensalidade">

            <div class="header-title">
                <h3>Cobrança de Mensalidade</h3>
                <p class="mb-0">Sistema: <?= ucfirst(htmlspecialchars($sistema)) ?></p>
            </div>

            <div class="card-body">

                <h5 class="text-center mb-4 text-primary">Período de Referência: <span
                        class="badge bg-primary"><?= htmlspecialchars($referente_mes_pt) ?></span></h5>

                <?php if ($meses_em_aberto > 1): ?>
                    <div class="alert alert-warning text-center" role="alert">
                        <strong>Aviso:</strong> Este pagamento consolida **<?= $meses_em_aberto ?> mensalidade(s)**
                        (incluindo as atrasadas).
                    </div>
                <?php endif; ?>

                <?php if ($dias_atraso_exibicao > 0): ?>
                    <div class="alert alert-danger text-center atraso-alert" role="alert">
                        <strong>ATENÇÃO:</strong> A mensalidade mais antiga está com **<?= $dias_atraso_exibicao ?> dias de
                        atraso**.
                    </div>
                <?php endif; ?>

                <h6 class="mt-4 mb-2 text-muted text-center">Resumo da Cobrança</h6>
                <ul class="list-group list-group-flush mb-4 border rounded">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Mensalidades (<?= $meses_em_aberto ?> x R$
                        <?= number_format($valor_base_mensalidade, 2, ',', '.') ?>)
                        <span class="fw-bold">R$ <?= number_format($valor_total_mensalidades, 2, ',', '.') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Total de Multas (2% de cada parcela em atraso)
                        <span class="<?= $valor_total_multa > 0 ? 'text-danger fw-bold' : '' ?>">R$
                            <?= number_format($valor_total_multa, 2, ',', '.') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Total de Juros (0,2% ao dia por parcela em atraso)
                        <span class="<?= $valor_total_juros > 0 ? 'text-danger fw-bold' : '' ?>">R$
                            <?= number_format($valor_total_juros, 2, ',', '.') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center bg-light">
                        <strong>Vencimento (Referência de Atraso)</strong>
                        <strong><?= $data_vencimento_exibicao ? $data_vencimento_exibicao->format('d/m/Y') : 'N/A' ?></strong>
                    </li>
                </ul>

                <?php if (count($cobranca_detalhada) > 1): ?>
                    <div class="accordion mb-4" id="accordionDetalhe">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
                                    Ver Detalhamento Mês a Mês (<?= count($cobranca_detalhada) ?> Parcelas)
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne"
                                data-bs-parent="#accordionDetalhe">
                                <div class="accordion-body p-0">
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($cobranca_detalhada as $detalhe): ?>
                                            <li class="list-group-item small">
                                                <span class="fw-bold"><?= $detalhe['referente'] ?>:</span>
                                                R$ <?= number_format($detalhe['valor_base'], 2, ',', '.') ?>
                                                <?php if ($detalhe['atraso_dias'] > 0): ?>
                                                    (+ Multa: <?= number_format($detalhe['multa'], 2, ',', '.') ?> + Juros:
                                                    <?= number_format($detalhe['juros'], 2, ',', '.') ?>)
                                                    <span class="badge bg-danger float-end">Atrasado (<?= $detalhe['atraso_dias'] ?>
                                                        dias)</span>
                                                <?php endif; ?>
                                                <span class="float-end fw-bold text-primary">R$
                                                    <?= number_format($detalhe['total'], 2, ',', '.') ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>


                <div class="text-center mb-4 p-3 rounded" style="background-color: #fdf3f5; border: 1px solid #f5c6cb;">
                    <p class="mb-1 text-muted">VALOR TOTAL CONSOLIDADO A PAGAR</p>
                    <div class="valor-total">R$ <?= number_format($valor_total_a_pagar, 2, ',', '.') ?></div>
                </div>

                <button type="button" class="btn btn-success btn-lg w-100 py-3" data-bs-toggle="modal"
                    data-bs-target="#cpfModal">
                    PAGAR COM PIX
                </button>

                <p class="text-center mt-3 text-muted">Você será redirecionado para a página de pagamento.</p>

            </div>
        </div>
    </div>

    <!-- Modal CPF -->
    <div class="modal fade" id="cpfModal" tabindex="-1" aria-labelledby="cpfModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cpfModalLabel">Informe seu CPF/CNPJ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Para emitir o Pix, o banco exige o CPF ou CNPJ do pagador.</p>
                    <div class="mb-3">
                        <label for="cpfInput" class="form-label">CPF ou CNPJ</label>
                        <input type="text" class="form-control form-control-lg" id="cpfInput"
                            placeholder="000.000.000-00" maxlength="18">
                        <div id="cpfError" class="invalid-feedback">
                            CPF/CNPJ inválido.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="irParaPagamento()">Continuar para
                        Pagamento</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const cpfInput = document.getElementById('cpfInput');
        const urlBasePix = "https://<?= $url_pix ?>"; // URL base gerada pelo PHP sem o CPF

        // Máscara simples para CPF e CNPJ
        cpfInput.addEventListener('input', function (e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 14) value = value.slice(0, 14);

            if (value.length <= 11) {
                // CPF
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                // CNPJ
                value = value.replace(/^(\d{2})(\d)/, '$1.$2');
                value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            }
            e.target.value = value;
        });

        function validarCpfCnpj(val) {
            val = val.replace(/\D/g, '');
            // Validação básica de tamanho (pode melhorar se quiser validar digitos verificadores)
            if (val.length === 11 || val.length === 14) return true;
            return false;
        }

        function irParaPagamento() {
            const cpfRaw = cpfInput.value.replace(/\D/g, '');

            if (!validarCpfCnpj(cpfRaw)) {
                cpfInput.classList.add('is-invalid');
                return;
            }

            cpfInput.classList.remove('is-invalid');

            // Redireciona adicionando o parâmetro cpf
            window.location.href = urlBasePix + "&cpf=" + cpfRaw;
        }
    </script>
</body>

</html>