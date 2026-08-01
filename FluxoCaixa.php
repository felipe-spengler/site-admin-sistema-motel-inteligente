<?php
date_default_timezone_set('America/Sao_Paulo');

// Inclui funções e auxiliares de banco e MQTT
include 'verificar_acesso.php';
include 'mqtt_helper.php';

// Verifica cookie de filial
if (!isset($_COOKIE["usuario_filial"])) {
    header("Location: index.php");
    exit();
}

$filial = $_COOKIE["usuario_filial"];

// Inclui conexão correta
switch ($filial) {
    case "abelardo":
        include 'conexaoAbelardo.php';
        break;
    case "toledo":
        include 'conexao2.php';
        break;
    case "xanxere":
        include 'conexaoXanxere.php';
        break;
    case "venus":
        include 'conexaoVenus.php';
        break;
    default:
        die("Filial inválida.");
}

$conexao = conectarAoBanco();
$paginaAtual = basename($_SERVER['PHP_SELF']);
verificarCookie($conexao, $paginaAtual);
verificarCargoUsuario(['admin', 'gerente']);

if ($conexao === null) {
    die("Erro na conexão com o banco de dados.");
}

// ------ PROCESSAMENTO DE AÇÕES VIA POST / MQTT ------
$mensagemFeedback = "";
$tipoFeedback = "success";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];
    
    if ($action === "lancar") {
        $descricao = trim($_POST["descricao"] ?? "");
        $categoria = trim($_POST["categoria"] ?? "");
        $valor = (float)str_replace(',', '.', $_POST["valor"] ?? "0");
        $formapagamento = trim($_POST["formapagamento"] ?? "dinheiro");
        $status = trim($_POST["status"] ?? "pago");
        $usuario = $_COOKIE["usuario_login"] ?? "Site";
        
        $horarioInput = trim($_POST["horario"] ?? "");
        $horarioMysql = null;
        if (!empty($horarioInput)) {
            $horarioMysql = str_replace('T', ' ', $horarioInput) . ':00';
        }
        
        if (!empty($descricao) && !empty($categoria) && $valor > 0) {
            $payload = json_encode([
                "descricao" => $descricao,
                "categoria" => $categoria,
                "valor" => $valor,
                "formapagamento" => $formapagamento,
                "status" => $status,
                "usuario" => $usuario,
                "idcaixa" => 0, // Java vai preencher com o ID do caixa aberto local
                "horario" => $horarioMysql
            ]);
            
            if (publicarComandoMqtt($filial, "lancar_despesa " . $payload)) {
                header("Location: FluxoCaixa.php?sucesso=1");
                exit();
            } else {
                $mensagemFeedback = "Falha ao enviar comando de lançamento via rede (MQTT). Verifique o broker.";
                $tipoFeedback = "danger";
            }
        } else {
            $mensagemFeedback = "Preencha todos os campos obrigatórios com valores válidos.";
            $tipoFeedback = "danger";
        }
    }
    
    elseif ($action === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $descricao = trim($_POST["descricao"] ?? "");
        $categoria = trim($_POST["categoria"] ?? "");
        $valor = (float)str_replace(',', '.', $_POST["valor"] ?? "0");
        $formapagamento = trim($_POST["formapagamento"] ?? "dinheiro");
        $status = trim($_POST["status"] ?? "pago");
        
        $horarioInput = trim($_POST["horario"] ?? "");
        $horarioMysql = null;
        if (!empty($horarioInput)) {
            $horarioMysql = str_replace('T', ' ', $horarioInput) . ':00';
        }
        
        if ($id > 0 && !empty($descricao) && !empty($categoria) && $valor > 0) {
            $payload = json_encode([
                "id" => $id,
                "descricao" => $descricao,
                "categoria" => $categoria,
                "valor" => $valor,
                "formapagamento" => $formapagamento,
                "status" => $status,
                "idcaixa" => 0,
                "horario" => $horarioMysql
            ]);
            
            if (publicarComandoMqtt($filial, "editar_despesa " . $payload)) {
                header("Location: FluxoCaixa.php?sucesso=2");
                exit();
            } else {
                $mensagemFeedback = "Falha ao enviar comando de edição via rede (MQTT).";
                $tipoFeedback = "danger";
            }
        } else {
            $mensagemFeedback = "Campos inválidos para edição de despesa.";
            $tipoFeedback = "danger";
        }
    }
    
    elseif ($action === "excluir") {
        $id = (int)($_POST["id"] ?? 0);
        if ($id > 0) {
            $payload = json_encode([
                "id" => $id
            ]);
            
            if (publicarComandoMqtt($filial, "excluir_despesa " . $payload)) {
                header("Location: FluxoCaixa.php?sucesso=3");
                exit();
            } else {
                $mensagemFeedback = "Falha ao enviar comando de exclusão via rede (MQTT).";
                $tipoFeedback = "danger";
            }
        }
    }

    elseif ($action === "salvar_taxas") {
        $taxa_credito = (float)str_replace(',', '.', $_POST["taxa_credito"] ?? "0");
        $taxa_debito = (float)str_replace(',', '.', $_POST["taxa_debito"] ?? "0");
        $taxa_pix = (float)str_replace(',', '.', $_POST["taxa_pix"] ?? "0");

        $stmt = $conexao->prepare("UPDATE configuracoes SET taxa_credito = ?, taxa_debito = ?, taxa_pix = ?");
        if ($stmt) {
            $stmt->bind_param("ddd", $taxa_credito, $taxa_debito, $taxa_pix);
            if ($stmt->execute()) {
                header("Location: FluxoCaixa.php?sucesso=4");
                exit();
            } else {
                $mensagemFeedback = "Erro ao salvar taxas administrativas no banco de dados.";
                $tipoFeedback = "danger";
            }
            $stmt->close();
        } else {
            $mensagemFeedback = "Erro ao preparar query para salvar taxas.";
            $tipoFeedback = "danger";
        }
    }
}

// Mensagens de sucesso vindas do redirect
$sucessoGet = $_GET["sucesso"] ?? "";
if ($sucessoGet === "1") {
    $mensagemFeedback = "Lançamento de despesa enviado para o caixa local com sucesso! Sincronizando...";
} elseif ($sucessoGet === "2") {
    $mensagemFeedback = "Edição de despesa enviada para o caixa local com sucesso! Sincronizando...";
} elseif ($sucessoGet === "3") {
    $mensagemFeedback = "Exclusão de despesa enviada para o caixa local com sucesso! Sincronizando...";
} elseif ($sucessoGet === "4") {
    $mensagemFeedback = "Taxas administrativas salvas com sucesso!";
}

// ------ LÓGICA DE DATAS E FILTROS ------
function formatarDataMysql($data, $horaInicial = true) {
    $partes = explode('/', $data);
    if(count($partes) == 3){
        return sprintf('%04d-%02d-%02d %s', $partes[2], $partes[1], $partes[0], $horaInicial ? '00:00:00' : '23:59:59');
    }
    return date('Y-m-d H:i:s');
}

$selectedPeriod = $_POST["period"] ?? ($_GET["period"] ?? 'this_month');
switch ($selectedPeriod) {
    case "today":
        $dataInicio = date('Y-m-d') . ' 00:00:00';
        $dataFim = date('Y-m-d') . ' 23:59:59';
        break;
    case "yesterday":
        $dataInicio = date('Y-m-d', strtotime('-1 day')) . ' 00:00:00';
        $dataFim = date('Y-m-d', strtotime('-1 day')) . ' 23:59:59';
        break;
    case "7":
        $dataInicio = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
        $dataFim = date('Y-m-d') . ' 23:59:59';
        break;
    case "30":
        $dataInicio = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
        $dataFim = date('Y-m-d') . ' 23:59:59';
        break;
    case "last_month":
        $dataInicio = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
        $dataFim = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
        break;
    case "custom":
        if (isset($_POST["data_inicio"]) && isset($_POST["data_fim"])) {
            $dataInicio = formatarDataMysql($_POST["data_inicio"], true);
            $dataFim = formatarDataMysql($_POST["data_fim"], false);
        } elseif (isset($_GET["data_inicio"]) && isset($_GET["data_fim"])) {
            $dataInicio = formatarDataMysql($_GET["data_inicio"], true);
            $dataFim = formatarDataMysql($_GET["data_fim"], false);
        } else {
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
        }
        break;
    case "this_month":
    default:
        $dataInicio = date('Y-m-01') . ' 00:00:00';
        $dataFim = date('Y-m-d') . ' 23:59:59';
        $selectedPeriod = 'this_month';
        break;
}

// ------ OBTENÇÃO DOS DADOS DO BANCO ------

// 0. Carregar Taxas Administrativas
$taxaCredito = 0.0;
$taxaDebito = 0.0;
$taxaPix = 0.0;
$sqlConfig = "SELECT taxa_credito, taxa_debito, taxa_pix FROM configuracoes LIMIT 1";
$resConfig = $conexao->query($sqlConfig);
if ($resConfig && $rowConfig = $resConfig->fetch_assoc()) {
    $taxaCredito = (float)$rowConfig['taxa_credito'];
    $taxaDebito = (float)$rowConfig['taxa_debito'];
    $taxaPix = (float)$rowConfig['taxa_pix'];
}

// 1. Consultar Locações (Faturamento)
$faturamentoTotal = 0.0;
$faturamentoMeios = [
    "Dinheiro" => 0.0,
    "Pix" => 0.0,
    "Cartão Crédito" => 0.0,
    "Cartão Débito" => 0.0,
    "Outro" => 0.0
];

$sqlLocacoes = "SELECT r.horafim, r.pagodinheiro, r.pagopix, r.pagocartao, r.numquarto, 
                       COALESCE(vc.valorcredito, 0) as valorcredito, COALESCE(vc.valordebito, 0) as valordebito 
                FROM registralocado r 
                LEFT JOIN valorcartao vc ON r.idlocacao = vc.idlocacao 
                WHERE r.horafim >= '$dataInicio' AND r.horafim <= '$dataFim' AND r.valorquarto IS NOT NULL 
                ORDER BY r.horafim";
$resLoc = $conexao->query($sqlLocacoes);
if ($resLoc) {
    while ($row = $resLoc->fetch_assoc()) {
        $pgDinheiro = (float)$row['pagodinheiro'];
        $pgPix = (float)$row['pagopix'];
        $pgCartao = (float)$row['pagocartao'];
        $valCred = (float)$row['valorcredito'];
        $valDeb = (float)$row['valordebito'];

        if ($pgCartao > 0 && $valCred == 0 && $valDeb == 0) {
            $valCred = $pgCartao; // Fallback
        }

        $total = $pgDinheiro + $pgPix + $pgCartao;
        $faturamentoTotal += $total;
        $faturamentoMeios["Dinheiro"] += $pgDinheiro;
        $faturamentoMeios["Pix"] += $pgPix;
        $faturamentoMeios["Cartão Crédito"] += $valCred;
        $faturamentoMeios["Cartão Débito"] += $valDeb;
    }
}

// 2. Consultar Vendas Avulsas (Faturamento)
$sqlVendas = "SELECT valortotal, formapagamento FROM vendas_avulsas 
              WHERE horario >= '$dataInicio' AND horario <= '$dataFim' AND tipo != 'adiantamento'";
$resVendas = $conexao->query($sqlVendas);
if ($resVendas) {
    while ($row = $resVendas->fetch_assoc()) {
        $total = (float)$row['valortotal'];
        $pgto = strtolower($row['formapagamento']);

        $faturamentoTotal += $total;
        if ($pgto === "dinheiro") {
            $faturamentoMeios["Dinheiro"] += $total;
        } elseif ($pgto === "pix") {
            $faturamentoMeios["Pix"] += $total;
        } elseif ($pgto === "credito" || $pgto === "cartao") {
            $faturamentoMeios["Cartão Crédito"] += $total;
        } elseif ($pgto === "debito") {
            $faturamentoMeios["Cartão Débito"] += $total;
        } else {
            $faturamentoMeios["Outro"] += $total;
        }
    }
}

// Calcular Taxas Administrativas como despesa
$totalDespesasVal = 0.0;
$despesasCategorias = [];

$totCred = $faturamentoMeios["Cartão Crédito"];
$totDeb = $faturamentoMeios["Cartão Débito"];
$totPix = $faturamentoMeios["Pix"];

$valorTaxasCalculado = ($totCred * $taxaCredito / 100.0) + ($totDeb * $taxaDebito / 100.0) + ($totPix * $taxaPix / 100.0);
if ($valorTaxasCalculado > 0) {
    $totalDespesasVal += $valorTaxasCalculado;
    $despesasCategorias["Taxas Administrativas (Cartões/Pix)"] = $valorTaxasCalculado;
}

// 3. Consultar Retiradas/Sangrias (Despesa)
$retiradas = [];
$sqlRetiradas = "SELECT id, horario, valor, quem, justificativa FROM retiradas_caixa 
                 WHERE horario >= '$dataInicio' AND horario <= '$dataFim' ORDER BY horario";
$resRet = $conexao->query($sqlRetiradas);
if ($resRet) {
    while ($row = $resRet->fetch_assoc()) {
        $id = (int)$row['id'];
        $total = (float)$row['valor'];
        $totalDespesasVal += $total;

        if (!isset($despesasCategorias["Retiradas/Sangrias"])) {
            $despesasCategorias["Retiradas/Sangrias"] = 0.0;
        }
        $despesasCategorias["Retiradas/Sangrias"] += $total;

        $retiradas[] = [
            'id' => $id,
            'data' => $row['horario'],
            'tipo' => 'Retirada Caixa',
            'descricao' => 'Quem: ' . $row['quem'] . ' - ' . $row['justificativa'],
            'categoria' => 'Retirada/Sangria',
            'valor' => $total,
            'formapagamento' => 'DINHEIRO',
            'status' => 'Pago',
            'bloqueado' => true
        ];
    }
}

// 4. Consultar Despesas (Despesa)
$despesasExibir = [];
$sqlDespesas = "SELECT id, horario, valor, descricao, categoria, formapagamento, status FROM despesas 
                WHERE horario >= '$dataInicio' AND horario <= '$dataFim' ORDER BY horario";
$resDesp = $conexao->query($sqlDespesas);
if ($resDesp) {
    while ($row = $resDesp->fetch_assoc()) {
        $id = (int)$row['id'];
        $total = (float)$row['valor'];
        $cat = $row['categoria'];
        $totalDespesasVal += $total;

        if (!isset($despesasCategorias[$cat])) {
            $despesasCategorias[$cat] = 0.0;
        }
        $despesasCategorias[$cat] += $total;

        $despesasExibir[] = [
            'id' => $id,
            'data' => $row['horario'],
            'tipo' => 'Despesa (' . $cat . ')',
            'descricao' => $row['descricao'],
            'categoria' => $cat,
            'valor' => $total,
            'formapagamento' => strtoupper($row['formapagamento']),
            'status' => ucfirst($row['status']),
            'bloqueado' => false
        ];
    }
}

// Mesclar despesas e retiradas na tabela detalhada (ordenada por data)
$todosLancamentos = array_merge($despesasExibir, $retiradas);
usort($todosLancamentos, function ($a, $b) {
    return strcmp($a['data'], $b['data']);
});

// 5. Consultar Adiantamentos de Funcionários
$adiantamentos = [];
$totalAdiantamentosVal = 0.0;
$sqlAdiantamentos = "SELECT horario, descricao, quantidade, valorunidade, valortotal FROM vendas_avulsas 
                     WHERE tipo = 'adiantamento' AND horario >= '$dataInicio' AND horario <= '$dataFim' ORDER BY horario";
$resAd = $conexao->query($sqlAdiantamentos);
if ($resAd) {
    while ($row = $resAd->fetch_assoc()) {
        $desc = $row['descricao'];
        $qtd = (int)$row['quantidade'];
        $valUnit = (float)$row['valorunidade'];
        $total = (float)$row['valortotal'];
        $totalAdiantamentosVal += $total;

        $func = "Desconhecido";
        if (strpos($desc, "[Funcionário: ") !== false && strpos($desc, "]") !== false) {
            $start = strpos($desc, "[Funcionário: ") + 14;
            $end = strpos($desc, "]", $start);
            $func = substr($desc, $start, $end - $start);
        }

        $adiantamentos[] = [
            'data' => $row['horario'],
            'funcionario' => $func,
            'descricao' => $desc,
            'quantidade' => $qtd,
            'valorunidade' => $valUnit,
            'valortotal' => $total
        ];
    }
}

$saldoLiquido = $faturamentoTotal - $totalDespesasVal;

$conexao->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fluxo de Caixa - <?= ucfirst($filial) ?></title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    
    <!-- Google Fonts - Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>
    
    <style>
        :root {
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --card-border: rgba(0, 0, 0, 0.08);
            --text-color: #1e293b;
            --font-primary: 'Inter', sans-serif;
            --font-title: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: var(--font-primary);
            margin-bottom: 90px;
        }
        
        .main-container {
            max-width: 1200px;
            margin-top: 30px;
        }

        .title-gradient {
            font-family: var(--font-title);
            background: linear-gradient(45deg, #1e3a8a, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
        }

        /* Premium Light Panels */
        .glass-panel {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.08);
        }

        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.08);
        }
        
        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.12);
        }

        .btn-premium {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-premium:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        }

        .badge-receita {
            background-color: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-despesa {
            background-color: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Table custom styling */
        .custom-table {
            background: var(--card-bg);
            color: var(--text-color);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--card-border);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.08);
        }

        .custom-table th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 600;
            border-bottom: 2px solid rgba(0, 0, 0, 0.08);
            padding: 14px;
        }

        .custom-table td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            vertical-align: middle;
        }

        .custom-table tr:hover td {
            background: #f8fafc;
        }

        .form-control, .form-select {
            background-color: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.15);
            color: var(--text-color);
        }

        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: #3b82f6;
            color: var(--text-color);
            box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.25);
        }

        .modal-content {
            background-color: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.1);
            color: var(--text-color);
            border-radius: 16px;
        }

        .modal-header {
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        }

        .modal-footer {
            border-top: 1px solid rgba(0, 0, 0, 0.08);
        }

        /* Navigation Tabs Customization */
        .nav-tabs-premium {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }

        .nav-tabs-premium .nav-link {
            border: none;
            color: #64748b;
            font-weight: 600;
            padding: 12px 20px;
            position: relative;
            background: transparent;
            transition: color 0.2s ease;
        }

        .nav-tabs-premium .nav-link:hover {
            color: #1e3a8a;
        }

        .nav-tabs-premium .nav-link.active {
            color: #2563eb;
            background: transparent;
        }

        .nav-tabs-premium .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background-color: #2563eb;
            border-radius: 3px;
        }

        .dre-header-row {
            background-color: #f1f5f9 !important;
            font-weight: 700;
            color: #1e293b;
        }

        .dre-total-row {
            font-weight: 700;
            background-color: #f8fafc !important;
            border-top: 2px solid #cbd5e1;
        }

        .dre-indent {
            padding-left: 25px !important;
        }

        /* Print formatting */
        @media print {
            body {
                background-color: white !important;
                color: black !important;
                margin-bottom: 0;
            }
            .fixed-bottom, .navbar, .glass-panel, .btn-action, .btn-premium, .modal, .toast, .nav-tabs-premium {
                display: none !important;
            }
            .main-container {
                max-width: 100% !important;
                margin-top: 0 !important;
            }
            .custom-table {
                background: white !important;
                color: black !important;
                border: 1px solid #ccc !important;
            }
            .custom-table th {
                background: #f0f0f0 !important;
                color: black !important;
                border-bottom: 2px solid #ccc !important;
            }
            .custom-table td {
                border-bottom: 1px solid #eee !important;
            }
            .metric-card {
                border: 1px solid #ccc !important;
                background: white !important;
                color: black !important;
            }
            .text-success, .text-danger, .text-warning, .text-info {
                color: black !important;
            }
        }
    </style>
</head>
<body>
    <div class="container main-container">
        
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="title-gradient mb-0">Fluxo de Caixa</h2>
                <p class="text-muted mb-0">Gestão e acompanhamento das finanças de <?= ucfirst($filial) ?></p>
            </div>
            <div>
                <button type="button" class="btn btn-premium d-inline-flex align-items-center gap-2 btn-action" data-bs-toggle="modal" data-bs-target="#modalLancar">
                    <i class="bi bi-plus-circle-fill"></i> Lançar Despesa
                </button>
                <button onclick="window.print()" class="btn btn-outline-secondary ms-2 d-inline-flex align-items-center gap-2 btn-action">
                    <i class="bi bi-printer-fill"></i> Imprimir / PDF
                </button>
            </div>
        </div>

        <!-- Feedback Alert Toast -->
        <?php if (!empty($mensagemFeedback)): ?>
            <div class="alert alert-<?= $tipoFeedback ?> alert-dismissible fade show border-0 shadow-lg mb-4 rounded-3" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i>
                <?= $mensagemFeedback ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Cards de Resumo -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="metric-card d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.8rem; letter-spacing: 1px;">Total Faturamento</span>
                        <h3 class="fw-bold mt-1 text-success">R$ <?= number_format($faturamentoTotal, 2, ',', '.') ?></h3>
                    </div>
                    <i class="bi bi-arrow-up-circle-fill text-success fs-1 opacity-75"></i>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.8rem; letter-spacing: 1px;">Total Despesas</span>
                        <h3 class="fw-bold mt-1 text-danger">R$ <?= number_format($totalDespesasVal, 2, ',', '.') ?></h3>
                    </div>
                    <i class="bi bi-arrow-down-circle-fill text-danger fs-1 opacity-75"></i>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.8rem; letter-spacing: 1px;">Resultado Líquido</span>
                        <h3 class="fw-bold mt-1 <?= $saldoLiquido >= 0 ? 'text-primary' : 'text-danger' ?>">
                            R$ <?= number_format($saldoLiquido, 2, ',', '.') ?>
                        </h3>
                    </div>
                    <i class="bi bi-wallet2 <?= $saldoLiquido >= 0 ? 'text-primary' : 'text-danger' ?> fs-1 opacity-75"></i>
                </div>
            </div>
        </div>

        <!-- Filtro Panel -->
        <div class="glass-panel mb-4">
            <form method="post" action="FluxoCaixa.php" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="period" class="form-label fw-bold text-muted">Período</label>
                    <select name="period" id="period" class="form-select">
                        <option value="today" <?= ($selectedPeriod == 'today' ? 'selected' : ''); ?>>Hoje</option>
                        <option value="yesterday" <?= ($selectedPeriod == 'yesterday' ? 'selected' : ''); ?>>Ontem</option>
                        <option value="7" <?= ($selectedPeriod == '7' ? 'selected' : ''); ?>>Últimos 7 dias</option>
                        <option value="30" <?= ($selectedPeriod == '30' ? 'selected' : ''); ?>>Últimos 30 dias</option>
                        <option value="this_month" <?= ($selectedPeriod == 'this_month' ? 'selected' : ''); ?>>Este Mês</option>
                        <option value="last_month" <?= ($selectedPeriod == 'last_month' ? 'selected' : ''); ?>>Mês Passado</option>
                        <option value="custom" <?= ($selectedPeriod == 'custom' ? 'selected' : ''); ?>>Período Personalizado</option>
                    </select>
                </div>

                <div id="custom_period" class="col-md-6 row g-2" style="display: <?= ($selectedPeriod == 'custom' ? 'flex' : 'none'); ?>;">
                    <div class="col-sm-6">
                        <label for="data_inicio" class="form-label fw-bold text-muted">Início</label>
                        <input type="text" id="data_inicio" name="data_inicio" class="form-control"
                            value="<?= ($selectedPeriod == 'custom' && isset($_POST['data_inicio']) ? htmlspecialchars($_POST['data_inicio']) : (isset($_GET['data_inicio']) ? htmlspecialchars($_GET['data_inicio']) : '')); ?>"
                            placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-sm-6">
                        <label for="data_fim" class="form-label fw-bold text-muted">Fim</label>
                        <input type="text" id="data_fim" name="data_fim" class="form-control"
                            value="<?= ($selectedPeriod == 'custom' && isset($_POST['data_fim']) ? htmlspecialchars($_POST['data_fim']) : (isset($_GET['data_fim']) ? htmlspecialchars($_GET['data_fim']) : '')); ?>"
                            placeholder="dd/mm/aaaa">
                    </div>
                </div>

                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary fw-semibold py-2">Filtrar</button>
                </div>
            </form>
        </div>

        <!-- Abas do Fluxo de Caixa (Igual ao Java Dialog) -->
        <ul class="nav nav-tabs nav-tabs-premium" id="fluxoTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="resumo-tab" data-bs-toggle="tab" data-bs-target="#resumo-pane" type="button" role="tab" aria-controls="resumo-pane" aria-selected="true">
                    Resumo Gerencial (DRE)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="lancamentos-tab" data-bs-toggle="tab" data-bs-target="#lancamentos-pane" type="button" role="tab" aria-controls="lancamentos-pane" aria-selected="false">
                    Lançamentos de Despesas
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="adiantamentos-tab" data-bs-toggle="tab" data-bs-target="#adiantamentos-pane" type="button" role="tab" aria-controls="adiantamentos-pane" aria-selected="false">
                    Adiantamentos de Funcionários
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="taxas-tab" data-bs-toggle="tab" data-bs-target="#taxas-pane" type="button" role="tab" aria-controls="taxas-pane" aria-selected="false">
                    Taxas Administrativas
                </button>
            </li>
        </ul>

        <div class="tab-content" id="fluxoTabsContent">
            <!-- ABA 1: RESUMO GERENCIAL DRE -->
            <div class="tab-pane fade show active" id="resumo-pane" role="tabpanel" aria-labelledby="resumo-tab" tabindex="0">
                <div class="table-responsive custom-table shadow-sm">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Categoria / Centro de Custo</th>
                                <th class="text-end">Valor (R$)</th>
                                <th class="text-end">Participação (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- SEÇÃO RECEITAS -->
                            <tr class="dre-header-row">
                                <td colspan="3">FATURAMENTO (RECEITAS)</td>
                            </tr>
                            <?php foreach ($faturamentoMeios as $meio => $valor): ?>
                                <?php if ($valor > 0): ?>
                                    <?php $perc = $faturamentoTotal > 0 ? ($valor / $faturamentoTotal) * 100.0 : 0.0; ?>
                                    <tr>
                                        <td class="dre-indent"><?= strtoupper($meio) ?></td>
                                        <td class="text-end fw-semibold">R$ <?= number_format($valor, 2, ',', '.') ?></td>
                                        <td class="text-end text-muted"><?= number_format($perc, 1, ',', '.') ?> %</td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <tr class="dre-total-row">
                                <td>TOTAL FATURAMENTO</td>
                                <td class="text-end text-success">R$ <?= number_format($faturamentoTotal, 2, ',', '.') ?></td>
                                <td class="text-end">100,0 %</td>
                            </tr>

                            <!-- ESPAÇADOR -->
                            <tr><td colspan="3" style="height: 15px; border: none; background: transparent;"></td></tr>

                            <!-- SEÇÃO DESPESAS -->
                            <tr class="dre-header-row">
                                <td colspan="3">DESPESAS POR CENTRO DE CUSTO</td>
                            </tr>
                            <?php if (empty($despesasCategorias)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-3 text-muted">Nenhuma despesa ou taxa gerada no período.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($despesasCategorias as $cat => $valor): ?>
                                    <?php $perc = $faturamentoTotal > 0 ? ($valor / $faturamentoTotal) * 100.0 : 0.0; ?>
                                    <tr>
                                        <td class="dre-indent"><?= strtoupper($cat) ?></td>
                                        <td class="text-end fw-semibold">- R$ <?= number_format($valor, 2, ',', '.') ?></td>
                                        <td class="text-end text-danger"><?= number_format($perc, 1, ',', '.') ?> %</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr class="dre-total-row">
                                <td>TOTAL DESPESAS</td>
                                <td class="text-end text-danger">- R$ <?= number_format($totalDespesasVal, 2, ',', '.') ?></td>
                                <td class="text-end text-danger"><?= number_format($faturamentoTotal > 0 ? ($totalDespesasVal / $faturamentoTotal) * 100.0 : 0.0, 1, ',', '.') ?> %</td>
                            </tr>

                            <!-- ESPAÇADOR -->
                            <tr><td colspan="3" style="height: 15px; border: none; background: transparent;"></td></tr>

                            <!-- RESULTADO LIQUIDO -->
                            <tr class="dre-header-row">
                                <td>RESULTADO GERAL DO PERÍODO</td>
                                <td class="text-end <?= $saldoLiquido >= 0 ? 'text-success' : 'text-danger' ?>">
                                    R$ <?= number_format($saldoLiquido, 2, ',', '.') ?>
                                </td>
                                <td class="text-end <?= $saldoLiquido >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= number_format($faturamentoTotal > 0 ? ($saldoLiquido / $faturamentoTotal) * 100.0 : 0.0, 1, ',', '.') ?> %
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABA 2: LANÇAMENTOS DE DESPESAS -->
            <div class="tab-pane fade" id="lancamentos-pane" role="tabpanel" aria-labelledby="lancamentos-tab" tabindex="0">
                <div class="table-responsive custom-table shadow-sm">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Tipo</th>
                                <th>Descrição</th>
                                <th>Categoria / Centro de Custo</th>
                                <th>Valor</th>
                                <th>Forma Pgto</th>
                                <th>Status</th>
                                <th class="text-end btn-action">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($todosLancamentos)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">Nenhum lançamento de despesa ou retirada encontrado para o período selecionado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($todosLancamentos as $mov): ?>
                                    <tr>
                                        <td><?= date('d/m/Y H:i', strtotime($mov['data'])) ?></td>
                                        <td>
                                            <span class="badge rounded-pill <?= $mov['categoria'] === 'Retirada/Sangria' ? 'badge-despesa' : 'badge-despesa' ?>">
                                                <?= $mov['categoria'] === 'Retirada/Sangria' ? 'Retirada' : 'Despesa' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($mov['descricao']) ?></td>
                                        <td><?= htmlspecialchars($mov['categoria']) ?></td>
                                        <td class="fw-bold text-danger">
                                            - R$ <?= number_format($mov['valor'], 2, ',', '.') ?>
                                        </td>
                                        <td><span class="text-uppercase"><?= htmlspecialchars($mov['formapagamento']) ?></span></td>
                                        <td>
                                            <span class="badge text-bg-<?= strtolower($mov['status']) === 'pago' ? 'success' : 'secondary' ?>">
                                                <?= htmlspecialchars($mov['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end btn-action">
                                            <?php if (!$mov['bloqueado']): ?>
                                                <button onclick="abrirModalEdicao(<?= htmlspecialchars(json_encode($mov)) ?>)" class="btn btn-sm btn-outline-warning me-1">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <button onclick="confirmarExclusao(<?= $mov['id'] ?>, '<?= htmlspecialchars($mov['descricao']) ?>')" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.8rem;">Bloqueado</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABA 3: ADIANTAMENTOS DE FUNCIONÁRIOS -->
            <div class="tab-pane fade" id="adiantamentos-pane" role="tabpanel" aria-labelledby="adiantamentos-tab" tabindex="0">
                <div class="table-responsive custom-table shadow-sm mb-3">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Funcionário</th>
                                <th>Produto/Descrição</th>
                                <th class="text-center">Quantidade</th>
                                <th class="text-end">Valor Unit.</th>
                                <th class="text-end">Valor Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($adiantamentos)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">Nenhum adiantamento de funcionário no período.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($adiantamentos as $ad): ?>
                                    <tr>
                                        <td><?= date('d/m/Y H:i', strtotime($ad['data'])) ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars($ad['funcionario']) ?></td>
                                        <td><?= htmlspecialchars($ad['descricao']) ?></td>
                                        <td class="text-center"><?= $ad['quantidade'] ?></td>
                                        <td class="text-end">R$ <?= number_format($ad['valorunidade'], 2, ',', '.') ?></td>
                                        <td class="text-end fw-bold">R$ <?= number_format($ad['valortotal'], 2, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="glass-panel text-end py-3">
                    <h5 class="fw-bold mb-0 text-primary">Total Adiantamentos no Período: R$ <?= number_format($totalAdiantamentosVal, 2, ',', '.') ?></h5>
                </div>
            </div>

            <!-- ABA 4: TAXAS ADMINISTRATIVAS -->
            <div class="tab-pane fade" id="taxas-pane" role="tabpanel" aria-labelledby="taxas-tab" tabindex="0">
                <div class="glass-panel">
                    <form method="post" action="FluxoCaixa.php">
                        <input type="hidden" name="action" value="salvar_taxas">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-percent text-primary"></i> Configuração de Taxas Administrativas (%)</h5>
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="taxa_credito" class="form-label fw-semibold text-muted">Taxa Crédito (%)</label>
                                <div class="input-group">
                                    <input type="text" name="taxa_credito" id="taxa_credito" class="form-control" value="<?= number_format($taxaCredito, 2, ',', '.') ?>" required>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="taxa_debito" class="form-label fw-semibold text-muted">Taxa Débito (%)</label>
                                <div class="input-group">
                                    <input type="text" name="taxa_debito" id="taxa_debito" class="form-control" value="<?= number_format($taxaDebito, 2, ',', '.') ?>" required>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="taxa_pix" class="form-label fw-semibold text-muted">Taxa Pix (%)</label>
                                <div class="input-group">
                                    <input type="text" name="taxa_pix" id="taxa_pix" class="form-control" value="<?= number_format($taxaPix, 2, ',', '.') ?>" required>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 text-end">
                            <button type="submit" class="btn btn-premium px-4"><i class="bi bi-save2-fill"></i> Salvar Taxas</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- MODAL LANÇAR DESPESA -->
    <div class="modal fade" id="modalLancar" tabindex="-1" aria-labelledby="modalLancarLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="FluxoCaixa.php">
                    <input type="hidden" name="action" value="lancar">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="modalLancarLabel">Lançar Nova Despesa</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="descricao" class="form-label text-muted">Descrição / Fornecedor <span class="text-danger">*</span></label>
                            <input type="text" name="descricao" id="descricao" class="form-control" placeholder="Ex: Fornecedor de Bebidas" required>
                        </div>
                        <div class="mb-3">
                            <label for="categoria" class="form-label text-muted">Categoria / Centro de Custo <span class="text-danger">*</span></label>
                            <select name="categoria" id="categoria" class="form-select" required>
                                <option value="Gastos Fixos">Gastos Fixos</option>
                                <option value="Aluguel">Aluguel</option>
                                <option value="Limpeza / Lavanderia">Limpeza / Lavanderia</option>
                                <option value="Cozinha">Cozinha</option>
                                <option value="Manutenção">Manutenção</option>
                                <option value="Frigobar / Reposição">Frigobar / Reposição</option>
                                <option value="Marketing / Divulgação">Marketing / Divulgação</option>
                                <option value="Impostos / Taxas">Impostos / Taxas</option>
                                <option value="Salários / Comissões">Salários / Comissões</option>
                                <option value="Outros">Outros</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="horario" class="form-label text-muted">Data/Hora do Lançamento <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="horario" id="horario" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label for="valor" class="form-label text-muted">Valor (R$) <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="valor" class="form-control" placeholder="0,00" required>
                            </div>
                            <div class="col-6">
                                <label for="formapagamento" class="form-label text-muted">Forma de Pagamento</label>
                                <select name="formapagamento" id="formapagamento" class="form-select">
                                    <option value="dinheiro">Dinheiro</option>
                                    <option value="pix">PIX</option>
                                    <option value="cartao">Cartão</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label text-muted">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="pago">Pago / Quitado</option>
                                <option value="pendente">Pendente / Agendado</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-premium">Gravar Despesa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR DESPESA -->
    <div class="modal fade" id="modalEditar" tabindex="-1" aria-labelledby="modalEditarLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="FluxoCaixa.php">
                    <input type="hidden" name="action" value="editar">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="modalEditarLabel">Editar Despesa</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_descricao" class="form-label text-muted">Descrição / Fornecedor <span class="text-danger">*</span></label>
                            <input type="text" name="descricao" id="edit_descricao" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_categoria" class="form-label text-muted">Categoria / Centro de Custo <span class="text-danger">*</span></label>
                            <select name="categoria" id="edit_categoria" class="form-select" required>
                                <option value="Gastos Fixos">Gastos Fixos</option>
                                <option value="Aluguel">Aluguel</option>
                                <option value="Limpeza / Lavanderia">Limpeza / Lavanderia</option>
                                <option value="Cozinha">Cozinha</option>
                                <option value="Manutenção">Manutenção</option>
                                <option value="Frigobar / Reposição">Frigobar / Reposição</option>
                                <option value="Marketing / Divulgação">Marketing / Divulgação</option>
                                <option value="Impostos / Taxas">Impostos / Taxas</option>
                                <option value="Salários / Comissões">Salários / Comissões</option>
                                <option value="Outros">Outros</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_horario" class="form-label text-muted">Data/Hora do Lançamento <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="horario" id="edit_horario" class="form-control" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label for="edit_valor" class="form-label text-muted">Valor (R$) <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="edit_valor" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label for="edit_formapagamento" class="form-label text-muted">Forma de Pagamento</label>
                                <select name="formapagamento" id="edit_formapagamento" class="form-select">
                                    <option value="dinheiro">Dinheiro</option>
                                    <option value="pix">PIX</option>
                                    <option value="cartao">Cartão</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_status" class="form-label text-muted">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="pago">Pago / Quitado</option>
                                <option value="pendente">Pendente / Agendado</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-premium">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL DE CONFIRMAÇÃO DE EXCLUSÃO -->
    <div class="modal fade" id="modalExcluir" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <form method="post" action="FluxoCaixa.php">
                    <input type="hidden" name="action" value="excluir">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Excluir Lançamento</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center">
                        <p class="mb-0">Tem certeza que deseja excluir a despesa:</p>
                        <p class="fw-bold mt-1 text-warning" id="delete_desc"></p>
                        <p class="text-muted small">Essa exclusão será enviada para o banco local.</p>
                    </div>
                    <div class="modal-footer justify-content-center">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Não</button>
                        <button type="submit" class="btn btn-danger btn-sm">Confirmar Exclusão</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Menu Relatorios Include -->
    <?php include 'menu.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(function() {
            // Configurar datepicker do jQuery UI
            $("#data_inicio, #data_fim").datepicker({
                dateFormat: 'dd/mm/yy',
                dayNames: ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'],
                dayNamesMin: ['D','S','T','Q','Q','S','S','D'],
                dayNamesShort: ['Dom','Seg','Ter','Qua','Qui','Sexta','Sáb','Dom'],
                monthNames: ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
                monthNamesShort: ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'],
                nextText: 'Próximo',
                prevText: 'Anterior'
            });

            // Mostrar/ocultar inputs do período personalizado
            $('#period').on('change', function() {
                if ($(this).val() == 'custom') {
                    $('#custom_period').css('display', 'flex');
                } else {
                    $('#custom_period').hide();
                }
            });
        });

        function abrirModalEdicao(mov) {
            $('#edit_id').val(mov.id);
            $('#edit_descricao').val(mov.descricao);
            $('#edit_categoria').val(mov.categoria);
            // Formatar valor para ponto decimal nos inputs de edição
            $('#edit_valor').val(parseFloat(mov.valor).toFixed(2));
            $('#edit_formapagamento').val(mov.formapagamento.toLowerCase());
            $('#edit_status').val(mov.status.toLowerCase());
            if (mov.data) {
                var dt = mov.data.replace(' ', 'T').substring(0, 16);
                $('#edit_horario').val(dt);
            }
            
            var editModal = new bootstrap.Modal(document.getElementById('modalEditar'));
            editModal.show();
        }

        function confirmarExclusao(id, descricao) {
            $('#delete_id').val(id);
            $('#delete_desc').text(descricao);
            
            var deleteModal = new bootstrap.Modal(document.getElementById('modalExcluir'));
            deleteModal.show();
        }
    </script>
</body>
</html>
