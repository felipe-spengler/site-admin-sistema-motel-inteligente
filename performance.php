<?php
date_default_timezone_set('America/Sao_Paulo');

function formatarData($data, $horaInicial = true) {
    $partes = explode('/', $data);
    if (count($partes) == 3) {
        $dia = $partes[0];
        $mes = $partes[1];
        $ano = $partes[2];
        $hora = $horaInicial ? '00:00:00' : '23:59:59';
        return sprintf('%04d-%02d-%02d %s', $ano, $mes, $dia, $hora);
    }
    return date('Y-m-d') . ($horaInicial ? ' 00:00:00' : ' 23:59:59');
}

function resolverPeriodo($selectedPeriod, $customInicio = '', $customFim = '') {
    switch ($selectedPeriod) {
        case "7":
            $dataInicio = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "15":
            $dataInicio = date('Y-m-d', strtotime('-15 days')) . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "30":
            $dataInicio = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "this_quarter":
            $mesAtual = date('n');
            $trimestreAtual = ceil($mesAtual / 3);
            $mesInicio = ($trimestreAtual - 1) * 3 + 1;
            $dataInicio = date('Y') . '-' . sprintf('%02d', $mesInicio) . '-01 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "this_semester":
            $mesAtual = date('n');
            $semestreAtual = ceil($mesAtual / 6);
            $mesInicio = ($semestreAtual - 1) * 6 + 1;
            $dataInicio = date('Y') . '-' . sprintf('%02d', $mesInicio) . '-01 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "this_year":
            $dataInicio = date('Y-01-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "last_year":
            $dataInicio = date('Y-01-01', strtotime('-1 year')) . ' 00:00:00';
            $dataFim = date('Y-12-31', strtotime('-1 year')) . ' 23:59:59';
            break;
        case "last_month":
            $dataInicio = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
            $dataFim = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
            break;
        case "custom":
            if (!empty($customInicio) && !empty($customFim)) {
                $dataInicio = formatarData($customInicio, true);
                $dataFim = formatarData($customFim, false);
            } else {
                $dataInicio = date('Y-m-01') . ' 00:00:00';
                $dataFim = date('Y-m-d') . ' 23:59:59';
            }
            break;
        case "this_month":
        default:
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
    }
    return [$dataInicio, $dataFim];
}

include 'verificar_acesso.php';
if (!isset($_COOKIE["usuario_filial"])) {
    header("Location: index.php");
    exit();
}

$filial = $_COOKIE["usuario_filial"];

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

function carregarLocacoesPeriodo($conexao, $inicio, $fim, $quartosMap) {
    $sqlCaixas = "SELECT id FROM caixa WHERE horaabre >= ? AND horaabre <= ?";
    $stmtC = $conexao->prepare($sqlCaixas);
    $stmtC->bind_param("ss", $inicio, $fim);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    $ids = [];
    while ($rowC = $resC->fetch_assoc()) {
        $ids[] = (int)$rowC['id'];
    }
    $stmtC->close();

    if (empty($ids)) {
        return ['locacoes' => [], 'vendas_avulsas' => 0.0];
    }

    $idsStr = implode(',', $ids);

    // Vendas avulsas no período (exceto adiantamentos)
    $vendasAvulsas = 0.0;
    $resAv = $conexao->query("SELECT COALESCE(SUM(valortotal), 0) AS total FROM vendas_avulsas WHERE idcaixa IN ($idsStr) AND tipo != 'adiantamento'");
    if ($resAv && $rowAv = $resAv->fetch_assoc()) {
        $vendasAvulsas = (float)$rowAv['total'];
    }

    // Locações detalhadas com data/hora de início e fim para permitir filtro instantâneo no JS
    $sqlLoc = "
        SELECT 
            r.idlocacao,
            r.numquarto,
            r.horainicio,
            r.horafim,
            COALESCE(r.valorquarto, 0) AS valorquarto,
            COALESCE(r.valorconsumo, 0) AS valorconsumo,
            COALESCE(r.pagodinheiro, 0) + COALESCE(r.pagopix, 0) + COALESCE(r.pagocartao, 0) AS total_pago,
            r.periodo_locado
        FROM registralocado r
        WHERE r.idcaixaatual IN ($idsStr)
        ORDER BY r.horainicio ASC
    ";
    $resLoc = $conexao->query($sqlLoc);
    $locacoes = [];
    if ($resLoc) {
        while ($row = $resLoc->fetch_assoc()) {
            $numQ = (int)$row['numquarto'];
            $tipoQ = $quartosMap[$numQ] ?? 'Padrão';
            $iniTs = strtotime($row['horainicio']);
            $fimTs = !empty($row['horafim']) ? strtotime($row['horafim']) : ($iniTs + 7200);
            if ($fimTs < $iniTs) {
                $fimTs = $iniTs + 3600;
            }
            $duracaoMin = max(1, round(($fimTs - $iniTs) / 60));

            $periodoStr = strtolower($row['periodo_locado'] ?? '');
            $isPernoite = (strpos($periodoStr, 'pernoite') !== false) || ($duracaoMin >= 360);

            $valPago = (float)$row['total_pago'];
            $valBruto = (float)$row['valorquarto'] + (float)$row['valorconsumo'];
            $receita = $valPago > 0 ? $valPago : $valBruto;

            // Dia da semana MySQL/JS: 1=Dom, 2=Seg, ..., 7=Sáb
            $diaSemana = (int)date('w', $iniTs) + 1;
            $horaInicio = (int)date('G', $iniTs);

            $locacoes[] = [
                'q' => $numQ,
                't' => $tipoQ,
                'ini' => $iniTs,
                'fim' => $fimTs,
                'd' => $diaSemana,
                'h' => $horaInicio,
                'dur' => $duracaoMin,
                'p' => $isPernoite ? 1 : 0,
                'val' => round($receita, 2)
            ];
        }
    }

    return [
        'locacoes' => $locacoes,
        'vendas_avulsas' => round($vendasAvulsas, 2)
    ];
}

function montarPayloadPerformance($conexao, $selectedPeriod, $customInicio = '', $customFim = '') {
    list($dataInicio, $dataFim) = resolverPeriodo($selectedPeriod, $customInicio, $customFim);

    $timestampInicio = strtotime($dataInicio);
    $timestampFim = strtotime($dataFim);
    $diferencaDias = max(1, (int)round(($timestampFim - $timestampInicio) / (60 * 60 * 24)));

    $prevInicio = date('Y-m-d H:i:s', $timestampInicio - ($diferencaDias * 86400));
    $prevFim = date('Y-m-d H:i:s', $timestampFim - ($diferencaDias * 86400));

    // Carregar todos os quartos cadastrados e suas categorias
    $quartosLista = [];
    $quartosMap = [];
    $categoriasContagem = [];
    $resQ = $conexao->query("SELECT numeroquarto, tipoquarto FROM quartos ORDER BY numeroquarto ASC");
    if ($resQ) {
        while ($rowQ = $resQ->fetch_assoc()) {
            $n = (int)$rowQ['numeroquarto'];
            $t = trim($rowQ['tipoquarto'] ?: 'Padrão');
            $quartosMap[$n] = $t;
            $quartosLista[] = ['numero' => $n, 'tipo' => $t];
            if (!isset($categoriasContagem[$t])) {
                $categoriasContagem[$t] = 0;
            }
            $categoriasContagem[$t]++;
        }
    }

    $atual = carregarLocacoesPeriodo($conexao, $dataInicio, $dataFim, $quartosMap);
    $anterior = carregarLocacoesPeriodo($conexao, $prevInicio, $prevFim, $quartosMap);

    // Despesas e Retiradas do período
    $despesasCategorias = [];
    $totalDespesas = 0.0;

    $sqlCat = "SELECT categoria, SUM(valor) as total FROM despesas WHERE horario >= ? AND horario <= ? GROUP BY categoria";
    $stmtCat = $conexao->prepare($sqlCat);
    if ($stmtCat) {
        $stmtCat->bind_param("ss", $dataInicio, $dataFim);
        $stmtCat->execute();
        $resCat = $stmtCat->get_result();
        while ($row = $resCat->fetch_assoc()) {
            $val = round((float)$row['total'], 2);
            if ($val > 0) {
                $despesasCategorias[] = ['categoria' => $row['categoria'], 'valor' => $val];
                $totalDespesas += $val;
            }
        }
        $stmtCat->close();
    }

    $sqlRet = "SELECT COALESCE(SUM(valor), 0) as total FROM retiradas_caixa WHERE horario >= ? AND horario <= ?";
    $stmtRet = $conexao->prepare($sqlRet);
    if ($stmtRet) {
        $stmtRet->bind_param("ss", $dataInicio, $dataFim);
        $stmtRet->execute();
        $resRet = $stmtRet->get_result();
        if ($rowR = $resRet->fetch_assoc()) {
            $retVal = round((float)$rowR['total'], 2);
            if ($retVal > 0) {
                $despesasCategorias[] = ['categoria' => 'Retiradas/Sangrias', 'valor' => $retVal];
                $totalDespesas += $retVal;
            }
        }
        $stmtRet->close();
    }

    return [
        'period' => $selectedPeriod,
        'data_inicio_fmt' => date('d/m/Y', $timestampInicio),
        'data_fim_fmt' => date('d/m/Y', $timestampFim),
        'dias' => $diferencaDias,
        'quartos' => $quartosLista,
        'categorias_qtd' => $categoriasContagem,
        'locacoes' => $atual['locacoes'],
        'vendas_avulsas' => $atual['vendas_avulsas'],
        'prev_locacoes' => $anterior['locacoes'],
        'prev_vendas_avulsas' => $anterior['vendas_avulsas'],
        'despesas_categorias' => $despesasCategorias,
        'total_despesas' => round($totalDespesas, 2)
    ];
}

// Endpoint AJAX para trocar de período sem recarregar a página!
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $p = $_GET['period'] ?? 'this_month';
    $ci = $_GET['data_inicio'] ?? '';
    $cf = $_GET['data_fim'] ?? '';
    $payload = montarPayloadPerformance($conexao, $p, $ci, $cf);
    $conexao->close();
    echo json_encode($payload);
    exit();
}

$selectedPeriod = $_POST["period"] ?? ($_GET["period"] ?? 'this_month');
$initialPayload = montarPayloadPerformance($conexao, $selectedPeriod, $_POST["data_inicio"] ?? '', $_POST["data_fim"] ?? '');
$conexao->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance & Mapa de Calor - <?= ucfirst($filial) ?></title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
            margin-bottom: 95px;
        }

        .main-container {
            max-width: 1240px;
            margin-top: 16px;
            padding-left: 12px;
            padding-right: 12px;
        }

        @media (min-width: 768px) {
            .main-container {
                margin-top: 26px;
                padding-left: 15px;
                padding-right: 15px;
            }
        }

        .title-gradient {
            font-family: var(--font-title);
            background: linear-gradient(45deg, #1e3a8a, #2563eb);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
        }

        .glass-panel {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 8px 24px -10px rgba(0, 0, 0, 0.08);
        }

        @media (min-width: 768px) {
            .glass-panel {
                border-radius: 16px;
                padding: 18px 20px;
            }
        }

        .hero-banner {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            border-radius: 16px;
            padding: 18px 22px;
            box-shadow: 0 12px 28px -8px rgba(16, 185, 129, 0.45);
            transition: background 0.3s ease;
        }

        .hero-banner.filtered-mode {
            background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%);
            box-shadow: 0 12px 28px -8px rgba(37, 99, 235, 0.4);
        }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 14px 16px;
            height: 100%;
            box-shadow: 0 8px 20px -10px rgba(0, 0, 0, 0.06);
            position: relative;
        }

        .kpi-label {
            font-size: 0.74rem;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 4px;
        }

        .kpi-value {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 2px;
        }

        @media (min-width: 768px) {
            .kpi-value {
                font-size: 1.75rem;
            }
        }

        .chart-container {
            position: relative;
            height: 290px;
            width: 100%;
        }

        /* Heatmap Styles */
        .heatmap-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 6px;
        }

        .heatmap-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 3px;
            table-layout: fixed;
            min-width: 760px;
        }

        .heatmap-cell {
            height: 36px;
            border-radius: 5px;
            font-size: 0.74rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            cursor: pointer;
            font-weight: 700;
            user-select: none;
        }

        .heatmap-cell:hover {
            transform: scale(1.15);
            box-shadow: 0 8px 16px -3px rgba(0, 0, 0, 0.18);
            z-index: 50;
            border: 2px solid #1e293b;
        }

        .heatmap-header {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-align: center;
            padding-bottom: 4px;
        }

        .heatmap-label {
            font-size: 0.76rem;
            font-weight: 700;
            color: #334155;
            text-align: right;
            padding-right: 8px;
            width: 44px;
        }

        .gargalo-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.84rem;
            font-weight: 600;
        }

        .loading-overlay {
            position: fixed;
            top: 14px;
            right: 14px;
            z-index: 9999;
            display: none;
        }

        .form-select, .form-control {
            font-size: 15px;
        }
    </style>
</head>
<body>

    <!-- Indicador discreto de atualização AJAX -->
    <div id="loadingIndicator" class="loading-overlay">
        <div class="badge bg-dark text-white px-3 py-2 shadow d-flex align-items-center gap-2">
            <div class="spinner-border spinner-border-sm text-info" role="status"></div>
            <span>Atualizando dados...</span>
        </div>
    </div>

    <div class="container main-container">
        
        <!-- Cabeçalho -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
            <div>
                <h2 class="title-gradient mb-0 fs-3 fs-md-2">Performance & Mapa de Calor</h2>
                <p class="text-muted mb-0 small">
                    Período: <strong id="lblPeriodoAtivo"><?= $initialPayload['data_inicio_fmt'] ?> a <?= $initialPayload['data_fim_fmt'] ?></strong>
                    (<span id="lblDiasAtivos"><?= $initialPayload['dias'] ?></span> dias)
                </p>
            </div>
            <div class="d-flex gap-2 w-100 w-md-auto justify-content-end">
                <button onclick="exportToWhats()" class="btn btn-success fw-semibold d-inline-flex align-items-center gap-2 shadow-sm">
                    <i class="bi bi-whatsapp"></i> Resumo p/ Whats
                </button>
            </div>
        </div>

        <!-- Painel de Filtros em Tempo Real (Sem Recarregar a Página!) -->
        <div class="glass-panel mb-3 border-top border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-primary small text-uppercase">
                    <i class="bi bi-sliders me-1"></i> Filtros Dinâmicos em Tempo Real (Sem recarregar a página)
                </span>
                <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" onclick="resetarFiltrosLocais()">
                    <i class="bi bi-arrow-counterclockwise"></i> Limpar filtros de quarto
                </button>
            </div>

            <div class="row g-2 g-md-3 align-items-end">
                <!-- Período (Atualiza via AJAX automaticamente ao selecionar) -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label for="period" class="form-label fw-semibold text-muted small mb-1">📅 Período</label>
                    <select id="period" class="form-select">
                        <option value="7" <?= ($selectedPeriod == '7' ? 'selected' : '') ?>>Últimos 7 dias</option>
                        <option value="15" <?= ($selectedPeriod == '15' ? 'selected' : '') ?>>Últimos 15 dias</option>
                        <option value="30" <?= ($selectedPeriod == '30' ? 'selected' : '') ?>>Últimos 30 dias</option>
                        <option value="this_month" <?= ($selectedPeriod == 'this_month' ? 'selected' : '') ?>>Este Mês</option>
                        <option value="last_month" <?= ($selectedPeriod == 'last_month' ? 'selected' : '') ?>>Mês Passado</option>
                        <option value="this_quarter" <?= ($selectedPeriod == 'this_quarter' ? 'selected' : '') ?>>Trimestre Atual</option>
                        <option value="this_semester" <?= ($selectedPeriod == 'this_semester' ? 'selected' : '') ?>>Semestre Atual</option>
                        <option value="this_year" <?= ($selectedPeriod == 'this_year' ? 'selected' : '') ?>>Anual (Este Ano)</option>
                        <option value="last_year" <?= ($selectedPeriod == 'last_year' ? 'selected' : '') ?>>Ano Passado</option>
                        <option value="custom" <?= ($selectedPeriod == 'custom' ? 'selected' : '') ?>>Personalizado...</option>
                    </select>
                </div>

                <!-- Categoria / Tipo de Quarto (Instantâneo 0ms) -->
                <div class="col-6 col-sm-6 col-md-3">
                    <label for="filtroCategoria" class="form-label fw-semibold text-muted small mb-1">🏷️ Tipo de Quarto</label>
                    <select id="filtroCategoria" class="form-select">
                        <option value="all">Todas as Categorias</option>
                    </select>
                </div>

                <!-- Quarto Específico (Instantâneo 0ms) -->
                <div class="col-6 col-sm-6 col-md-3">
                    <label for="filtroQuarto" class="form-label fw-semibold text-muted small mb-1">🚪 Quarto Específico</label>
                    <select id="filtroQuarto" class="form-select">
                        <option value="all">Todos os Quartos</option>
                    </select>
                </div>

                <!-- Modalidade (Período vs Pernoite - Instantâneo 0ms) -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label for="filtroModalidade" class="form-label fw-semibold text-muted small mb-1">🌙 Modalidade</label>
                    <select id="filtroModalidade" class="form-select">
                        <option value="all">Todas (Período + Pernoite)</option>
                        <option value="periodo">Somente Períodos</option>
                        <option value="pernoite">Somente Pernoites</option>
                    </select>
                </div>

                <!-- Campos de Data Personalizada (aparecem só quando Personalizado está ativo) -->
                <div id="custom_period" class="col-12 row g-2 mt-2" style="display: none;">
                    <div class="col-5 col-md-4">
                        <input type="text" id="data_inicio" class="form-control" placeholder="Início (dd/mm/aaaa)" autocomplete="off">
                    </div>
                    <div class="col-5 col-md-4">
                        <input type="text" id="data_fim" class="form-control" placeholder="Fim (dd/mm/aaaa)" autocomplete="off">
                    </div>
                    <div class="col-2 col-md-4 d-grid">
                        <button type="button" class="btn btn-primary fw-semibold" onclick="carregarPeriodoAjax()">Buscar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Banner Principal (Lucro Líquido Geral ou Receita do Filtro Selecionado) -->
        <div id="heroBanner" class="hero-banner mb-3">
            <div class="row align-items-center">
                <div class="col-12 col-md-7 text-center text-md-start">
                    <div id="heroTitulo" class="text-uppercase fw-bold small opacity-90">💰 Lucro Líquido Real do Período</div>
                    <div id="heroValor" class="display-5 fw-bold my-1">R$ 0,00</div>
                    <div id="heroSubtitulo" class="small opacity-90">Faturamento bruto menos despesas e sangrias lançadas no Fluxo de Caixa.</div>
                </div>
                <div class="col-12 col-md-5 mt-3 mt-md-0 text-center text-md-end border-top border-md-0 pt-2 pt-md-0" style="border-color: rgba(255,255,255,0.2) !important;">
                    <div class="d-inline-block text-start me-4">
                        <div class="small opacity-75">Faturamento Bruto</div>
                        <div id="heroFatBruto" class="fs-5 fw-bold">R$ 0,00</div>
                    </div>
                    <div class="d-inline-block text-start">
                        <div id="heroLabelSecundario" class="small opacity-75">Despesas / Margem</div>
                        <div id="heroValorSecundario" class="fs-5 fw-bold">R$ 0,00</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPIs 4 Colunas -->
        <div class="row g-2 g-md-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">RevPAR</div>
                    <div class="kpi-value text-primary" id="kpiRevPar">R$ 0,00</div>
                    <small class="text-muted" id="kpiRevParSub">Receita / quarto / dia</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="kpi-label">Ticket Médio</div>
                        <span id="kpiTicketBadge" class="badge rounded-pill"></span>
                    </div>
                    <div class="kpi-value text-primary" id="kpiTicket">R$ 0,00</div>
                    <small class="text-muted">Média por locação</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">Giro Diário</div>
                    <div class="kpi-value text-primary" id="kpiGiro">0,00</div>
                    <small class="text-muted" id="kpiGiroSub">Locações / quarto / dia</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">Taxa de Ocupação</div>
                    <div class="kpi-value text-primary" id="kpiOcupacao">0,0%</div>
                    <small class="text-muted">Ref: Máx. 4 giros/dia</small>
                </div>
            </div>
        </div>

        <!-- MAPA DE CALOR INTERATIVO & DETECTOR DE GARGALOS -->
        <div class="glass-panel mb-3 border-top border-4 border-warning">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-1 text-dark">
                        🔥 Mapa de Calor & Análise de Gargalos
                        <span id="badgeFiltroHeatmap" class="badge bg-primary ms-1"> Geral (Todos os Quartos)</span>
                    </h5>
                    <p class="text-muted small mb-0">
                        Alterne a visão abaixo para ver <strong>Horários de Entrada</strong>, <strong>Gargalo de Ocupação Simultânea (quando faltam quartos)</strong> ou <strong>Receita por Horário</strong>.
                    </p>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Modo Mapa de Calor">
                        <button type="button" class="btn btn-outline-dark active" id="btnModoEntradas" onclick="mudarModoHeatmap('entradas')">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Entradas (Check-in)
                        </button>
                        <button type="button" class="btn btn-outline-danger" id="btnModoSimultaneo" onclick="mudarModoHeatmap('simultaneo')">
                            <i class="bi bi-exclamation-diamond-fill me-1"></i>Gargalo (Ocupação Simultânea)
                        </button>
                        <button type="button" class="btn btn-outline-success" id="btnModoReceita" onclick="mudarModoHeatmap('receita')">
                            <i class="bi bi-currency-dollar me-1"></i>Faturamento (R$)
                        </button>
                    </div>
                </div>
            </div>

            <!-- Painel Inteligente de Diagnóstico de Gargalo do Filtro Atual -->
            <div id="painelGargaloInfo" class="alert alert-light border d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 py-2 px-3 mb-3">
                <div id="textoDiagnosticoGargalo" class="small text-dark"></div>
                <div id="badgePicoHeatmap" class="text-nowrap"></div>
            </div>

            <div class="heatmap-container">
                <table class="heatmap-table">
                    <thead>
                        <tr>
                            <th></th>
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <th class="heatmap-header"><?= $h ?>h</th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody id="heatmapBody">
                    </tbody>
                </table>
            </div>

            <div class="mt-3 d-flex flex-wrap align-items-center justify-content-between gap-2 text-muted small">
                <span class="d-md-none"><i class="bi bi-arrows-expand-vertical"></i> Deslize a tabela para o lado para ver as 24h</span>
                <div class="d-flex align-items-center mx-auto">
                    <span class="me-2 fw-semibold">Menor Intensidade</span>
                    <div class="d-flex gap-1">
                        <span style="width:22px;height:12px;border-radius:6px;background:#dbeafe;display:inline-block;"></span>
                        <span style="width:22px;height:12px;border-radius:6px;background:#60a5fa;display:inline-block;"></span>
                        <span style="width:22px;height:12px;border-radius:6px;background:#4ade80;display:inline-block;"></span>
                        <span style="width:22px;height:12px;border-radius:6px;background:#facc15;display:inline-block;"></span>
                        <span style="width:22px;height:12px;border-radius:6px;background:#fb923c;display:inline-block;"></span>
                        <span style="width:22px;height:12px;border-radius:6px;background:#ef4444;display:inline-block;"></span>
                    </div>
                    <span class="ms-2 fw-semibold">Pico / Gargalo Máximo</span>
                </div>
            </div>
        </div>

        <!-- Barra Dias Úteis vs Finais de Semana -->
        <div class="glass-panel mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-calendar-week text-primary me-1"></i> Distribuição: Dias Úteis vs. Finais de Semana</h6>
                <span class="small text-muted" id="lblTotalLocacoesSemana">0 locações</span>
            </div>
            <div class="progress" style="height: 26px; font-weight: 700; font-size: 0.85rem;">
                <div id="barWeekday" class="progress-bar bg-primary" role="progressbar" style="width: 50%">Dias Úteis</div>
                <div id="barWeekend" class="progress-bar bg-success" role="progressbar" style="width: 50%">Fim de Semana</div>
            </div>
        </div>

        <!-- Gráficos Linha 1: Horário de Pico + Mix de Categoria -->
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-8">
                <div class="glass-panel h-100">
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="bi bi-clock-fill text-primary me-1"></i> Curva de Demanda por Hora do Dia (0h às 23h)
                    </h6>
                    <div class="chart-container">
                        <canvas id="chartHorarios"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="glass-panel h-100">
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="bi bi-pie-chart-fill text-success me-1"></i> Mix de Ocupação por Categoria
                    </h6>
                    <div class="chart-container">
                        <canvas id="chartMix"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos Linha 2: Ranking por Quarto + Pressão de Demanda por Categoria + Destino do Faturamento -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-4">
                <div class="glass-panel h-100">
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="bi bi-trophy-fill text-warning me-1"></i> Ranking de Receita por Quarto
                    </h6>
                    <div class="chart-container">
                        <canvas id="chartRanking"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="glass-panel h-100">
                    <h6 class="fw-bold text-dark mb-2">
                        <i class="bi bi-speedometer2 text-danger me-1"></i> Gargalo por Categoria (Giro por Suíte)
                    </h6>
                    <p class="text-muted small mb-2">Mostra quais tipos de suíte sofrem maior pressão de demanda por quarto disponível:</p>
                    <div id="listaPressaoCategorias"></div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="glass-panel h-100">
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="bi bi-cash-coin text-success me-1"></i> Destino do Faturamento Geral
                    </h6>
                    <div class="chart-container">
                        <canvas id="chartDestino"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <?php include 'menu.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let PERF_DATA = <?= json_encode($initialPayload) ?>;
        let modoHeatmapAtual = 'entradas'; // 'entradas' | 'simultaneo' | 'receita'

        let chartHorariosInst = null;
        let chartMixInst = null;
        let chartRankingInst = null;
        let chartDestinoInst = null;

        const DIAS_NOMES = { 1: 'Dom', 2: 'Seg', 3: 'Ter', 4: 'Qua', 5: 'Qui', 6: 'Sex', 7: 'Sáb' };
        const DIAS_COMPLETOS = { 1: 'Domingo', 2: 'Segunda', 3: 'Terça', 4: 'Quarta', 5: 'Quinta', 6: 'Sexta', 7: 'Sábado' };

        function formatBRL(v) {
            return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function popularSelectsQuartosECategorias() {
            const selCat = document.getElementById('filtroCategoria');
            const selQuarto = document.getElementById('filtroQuarto');
            const catAtual = selCat.value;
            const quartoAtual = selQuarto.value;

            // Categorias
            selCat.innerHTML = '<option value="all">Todas as Categorias (' + PERF_DATA.quartos.length + ' suítes)</option>';
            Object.keys(PERF_DATA.categorias_qtd).forEach(cat => {
                const qtd = PERF_DATA.categorias_qtd[cat];
                const opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = `${cat} (${qtd} ${qtd > 1 ? 'suítes' : 'suíte'})`;
                selCat.appendChild(opt);
            });
            if ([...selCat.options].some(o => o.value === catAtual)) {
                selCat.value = catAtual;
            }

            atualizarOpcoesSelectQuarto(quartoAtual);
        }

        function atualizarOpcoesSelectQuarto(manterValor = 'all') {
            const selCat = document.getElementById('filtroCategoria').value;
            const selQuarto = document.getElementById('filtroQuarto');
            selQuarto.innerHTML = '<option value="all">Todos os Quartos</option>';

            PERF_DATA.quartos.forEach(q => {
                if (selCat === 'all' || q.tipo === selCat) {
                    const opt = document.createElement('option');
                    opt.value = String(q.numero);
                    opt.textContent = `Quarto ${q.numero} (${q.tipo})`;
                    selQuarto.appendChild(opt);
                }
            });

            if ([...selQuarto.options].some(o => o.value === String(manterValor))) {
                selQuarto.value = String(manterValor);
            } else {
                selQuarto.value = 'all';
            }
        }

        function resetarFiltrosLocais() {
            document.getElementById('filtroCategoria').value = 'all';
            atualizarOpcoesSelectQuarto('all');
            document.getElementById('filtroModalidade').value = 'all';
            aplicarFiltrosInstantaneos();
        }

        function mudarModoHeatmap(novoModo) {
            modoHeatmapAtual = novoModo;
            document.getElementById('btnModoEntradas').classList.toggle('active', novoModo === 'entradas');
            document.getElementById('btnModoSimultaneo').classList.toggle('active', novoModo === 'simultaneo');
            document.getElementById('btnModoReceita').classList.toggle('active', novoModo === 'receita');
            aplicarFiltrosInstantaneos();
        }

        function getThermalColor(val, max) {
            if (val <= 0 || max <= 0) return { bg: '#f1f5f9', text: '#94a3b8' };
            const ratio = val / max;
            if (ratio < 0.18) return { bg: '#dbeafe', text: '#1e40af' };
            if (ratio < 0.35) return { bg: '#60a5fa', text: '#ffffff' };
            if (ratio < 0.55) return { bg: '#4ade80', text: '#064e3b' };
            if (ratio < 0.75) return { bg: '#facc15', text: '#713f12' };
            if (ratio < 0.90) return { bg: '#fb923c', text: '#ffffff' };
            return { bg: '#ef4444', text: '#ffffff' };
        }

        function filtrarListaLocacoes(lista, catFiltro, quartoFiltro, modFiltro) {
            return lista.filter(loc => {
                if (catFiltro !== 'all' && loc.t !== catFiltro) return false;
                if (quartoFiltro !== 'all' && String(loc.q) !== String(quartoFiltro)) return false;
                if (modFiltro === 'periodo' && loc.p === 1) return false;
                if (modFiltro === 'pernoite' && loc.p === 0) return false;
                return true;
            });
        }

        function aplicarFiltrosInstantaneos() {
            const catFiltro = document.getElementById('filtroCategoria').value;
            const quartoFiltro = document.getElementById('filtroQuarto').value;
            const modFiltro = document.getElementById('filtroModalidade').value;
            const temFiltroQuartoOuCat = (catFiltro !== 'all' || quartoFiltro !== 'all' || modFiltro !== 'all');

            document.getElementById('lblPeriodoAtivo').textContent = `${PERF_DATA.data_inicio_fmt} a ${PERF_DATA.data_fim_fmt}`;
            document.getElementById('lblDiasAtivos').textContent = PERF_DATA.dias;

            // Quantidade de quartos considerados no denominador (RevPAR e Giro)
            let qtdQuartosEscopo = PERF_DATA.quartos.length || 1;
            if (quartoFiltro !== 'all') {
                qtdQuartosEscopo = 1;
            } else if (catFiltro !== 'all') {
                qtdQuartosEscopo = PERF_DATA.categorias_qtd[catFiltro] || 1;
            }

            // Atualiza badge de escopo do Mapa de Calor
            let labelEscopo = 'Geral (Todos os Quartos)';
            if (quartoFiltro !== 'all') {
                const qObj = PERF_DATA.quartos.find(x => String(x.numero) === String(quartoFiltro));
                labelEscopo = `Quarto ${quartoFiltro}` + (qObj ? ` (${qObj.tipo})` : '');
            } else if (catFiltro !== 'all') {
                labelEscopo = `Categoria: ${catFiltro} (${qtdQuartosEscopo} suítes)`;
            }
            if (modFiltro !== 'all') {
                labelEscopo += ` • ${modFiltro === 'pernoite' ? 'Pernoites' : 'Períodos'}`;
            }
            document.getElementById('badgeFiltroHeatmap').textContent = labelEscopo;

            // Filtra locações atuais e do período anterior (MoM)
            const locacoesFiltradas = filtrarListaLocacoes(PERF_DATA.locacoes, catFiltro, quartoFiltro, modFiltro);
            const prevFiltradas = filtrarListaLocacoes(PERF_DATA.prev_locacoes, catFiltro, quartoFiltro, modFiltro);

            const somaLocacoesVal = locacoesFiltradas.reduce((acc, l) => acc + l.val, 0);
            const faturamentoEscopo = temFiltroQuartoOuCat ? somaLocacoesVal : (somaLocacoesVal + Number(PERF_DATA.vendas_avulsas || 0));

            const somaPrevVal = prevFiltradas.reduce((acc, l) => acc + l.val, 0);
            const faturamentoPrevEscopo = temFiltroQuartoOuCat ? somaPrevVal : (somaPrevVal + Number(PERF_DATA.prev_vendas_avulsas || 0));

            const totalLoc = locacoesFiltradas.length;
            const totalPrevLoc = prevFiltradas.length;
            const dias = Math.max(1, Number(PERF_DATA.dias || 1));

            // Atualiza Hero Banner
            const heroBanner = document.getElementById('heroBanner');
            if (!temFiltroQuartoOuCat) {
                heroBanner.classList.remove('filtered-mode');
                const lucroLiq = faturamentoEscopo - Number(PERF_DATA.total_despesas || 0);
                const margem = faturamentoEscopo > 0 ? ((lucroLiq / faturamentoEscopo) * 100) : 0;
                document.getElementById('heroTitulo').innerHTML = '💰 Lucro Líquido Real do Período';
                document.getElementById('heroValor').textContent = formatBRL(lucroLiq);
                document.getElementById('heroSubtitulo').textContent = 'Faturamento bruto descontando todas as despesas e sangrias lançadas no Fluxo de Caixa.';
                document.getElementById('heroFatBruto').textContent = formatBRL(faturamentoEscopo);
                document.getElementById('heroLabelSecundario').textContent = 'Despesas / Margem';
                document.getElementById('heroValorSecundario').textContent = `${formatBRL(PERF_DATA.total_despesas)} (${margem.toFixed(1).replace('.', ',')}%)`;
            } else {
                heroBanner.classList.add('filtered-mode');
                const fatTotalGeral = PERF_DATA.locacoes.reduce((a, b) => a + b.val, 0) + Number(PERF_DATA.vendas_avulsas || 0);
                const partReceita = fatTotalGeral > 0 ? ((faturamentoEscopo / fatTotalGeral) * 100) : 0;
                document.getElementById('heroTitulo').innerHTML = '🎯 Receita Gerada pelo Filtro: ' + labelEscopo;
                document.getElementById('heroValor').textContent = formatBRL(faturamentoEscopo);
                document.getElementById('heroSubtitulo').textContent = `Representa ${partReceita.toFixed(1).replace('.', ',')}% do faturamento total do motel neste período (${totalLoc} locações).`;
                document.getElementById('heroFatBruto').textContent = `${totalLoc} locações`;
                document.getElementById('heroLabelSecundario').textContent = 'Tempo Médio / Estadia';
                const mediaMin = totalLoc > 0 ? (locacoesFiltradas.reduce((a, b) => a + b.dur, 0) / totalLoc) : 0;
                const hMed = Math.floor(mediaMin / 60);
                const mMed = Math.round(mediaMin % 60);
                document.getElementById('heroValorSecundario').textContent = `${hMed}h ${mMed}min`;
            }

            // KPIs
            const revPar = faturamentoEscopo / (qtdQuartosEscopo * dias);
            const ticketMedio = totalLoc > 0 ? (faturamentoEscopo / totalLoc) : 0;
            const ticketPrev = totalPrevLoc > 0 ? (faturamentoPrevEscopo / totalPrevLoc) : 0;
            const giroDiario = totalLoc / (qtdQuartosEscopo * dias);
            const taxaOcupacao = Math.min(100, (giroDiario / 4) * 100);

            document.getElementById('kpiRevPar').textContent = formatBRL(revPar);
            document.getElementById('kpiRevParSub').textContent = `Em ${qtdQuartosEscopo} ${qtdQuartosEscopo > 1 ? 'suítes' : 'suíte'}`;
            document.getElementById('kpiTicket').textContent = formatBRL(ticketMedio);
            document.getElementById('kpiGiro').textContent = giroDiario.toFixed(2).replace('.', ',');
            document.getElementById('kpiGiroSub').textContent = `${totalLoc} locações no período`;
            document.getElementById('kpiOcupacao').textContent = taxaOcupacao.toFixed(1).replace('.', ',') + '%';

            const badgeTicket = document.getElementById('kpiTicketBadge');
            if (ticketPrev > 0) {
                const diffPerc = ((ticketMedio - ticketPrev) / ticketPrev) * 100;
                badgeTicket.className = 'badge rounded-pill ' + (diffPerc >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger');
                badgeTicket.textContent = (diffPerc >= 0 ? '▲ ' : '▼ ') + Math.abs(diffPerc).toFixed(1).replace('.', ',') + '%';
            } else {
                badgeTicket.textContent = '';
            }

            // Dias Úteis vs Fim de Semana
            let weekendCount = 0;
            let weekdayCount = 0;
            locacoesFiltradas.forEach(l => {
                if (l.d === 1 || l.d === 7 || (l.d === 6 && l.h >= 18)) {
                    weekendCount++;
                } else {
                    weekdayCount++;
                }
            });
            const percWeek = totalLoc > 0 ? ((weekdayCount / totalLoc) * 100) : 50;
            const percWkend = totalLoc > 0 ? ((weekendCount / totalLoc) * 100) : 50;
            document.getElementById('lblTotalLocacoesSemana').textContent = `${totalLoc} locações filtradas`;
            const barW = document.getElementById('barWeekday');
            const barE = document.getElementById('barWeekend');
            barW.style.width = percWeek.toFixed(1) + '%';
            barW.textContent = `Dias Úteis: ${weekdayCount} (${percWeek.toFixed(0)}%)`;
            barE.style.width = percWkend.toFixed(1) + '%';
            barE.textContent = `Fim de Semana: ${weekendCount} (${percWkend.toFixed(0)}%)`;

            // Construir Matriz do Mapa de Calor (7 dias x 24 horas) e Curva Horária (0..23)
            const matrix = {};
            for (let d = 1; d <= 7; d++) {
                matrix[d] = new Array(24).fill(0);
            }
            const curvaHoraria = new Array(24).fill(0);

            locacoesFiltradas.forEach(l => {
                if (modoHeatmapAtual === 'entradas') {
                    matrix[l.d][l.h] += 1;
                    curvaHoraria[l.h] += 1;
                } else if (modoHeatmapAtual === 'receita') {
                    matrix[l.d][l.h] += l.val;
                    curvaHoraria[l.h] += l.val;
                } else if (modoHeatmapAtual === 'simultaneo') {
                    // Ocupação Simultânea (Permanência real): marca todas as horas em que o quarto esteve ocupado!
                    const horasOcupadas = Math.min(24, Math.max(1, Math.ceil(l.dur / 60)));
                    for (let step = 0; step < horasOcupadas; step++) {
                        const tsHora = l.ini + (step * 3600);
                        const dt = new Date(tsHora * 1000);
                        const diaS = dt.getDay() + 1;
                        const horaD = dt.getHours();
                        matrix[diaS][horaD] += 1;
                        curvaHoraria[horaD] += 1;
                    }
                }
            });

            let maxHeat = 0;
            let picoDia = 1;
            let picoHora = 0;
            for (let d = 1; d <= 7; d++) {
                for (let h = 0; h < 24; h++) {
                    if (matrix[d][h] > maxHeat) {
                        maxHeat = matrix[d][h];
                        picoDia = d;
                        picoHora = h;
                    }
                }
            }

            // Renderizar Tabela do Mapa de Calor
            const tbody = document.getElementById('heatmapBody');
            tbody.innerHTML = '';
            for (let d = 1; d <= 7; d++) {
                const tr = document.createElement('tr');
                const tdLabel = document.createElement('td');
                tdLabel.className = 'heatmap-label';
                tdLabel.textContent = DIAS_NOMES[d];
                tr.appendChild(tdLabel);

                for (let h = 0; h < 24; h++) {
                    const val = matrix[d][h];
                    const colors = getThermalColor(val, maxHeat);
                    const td = document.createElement('td');
                    const div = document.createElement('div');
                    div.className = 'heatmap-cell';
                    div.style.backgroundColor = colors.bg;
                    div.style.color = colors.text;

                    if (val > 0) {
                        if (modoHeatmapAtual === 'receita') {
                            div.textContent = val >= 1000 ? (val / 1000).toFixed(1) + 'k' : Math.round(val);
                            div.title = `${DIAS_COMPLETOS[d]} às ${h}h: ${formatBRL(val)}`;
                        } else if (modoHeatmapAtual === 'simultaneo') {
                            div.textContent = val;
                            div.title = `${DIAS_COMPLETOS[d]} às ${h}h: ${val} ocupações simultâneas acumuladas no período`;
                        } else {
                            div.textContent = val;
                            div.title = `${DIAS_COMPLETOS[d]} às ${h}h: ${val} entradas (check-ins)`;
                        }
                    } else {
                        div.title = `${DIAS_COMPLETOS[d]} às ${h}h: Sem movimento`;
                    }

                    td.appendChild(div);
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            }

            // Diagnóstico Automático de Gargalo no Topo do Mapa de Calor
            const diagEl = document.getElementById('textoDiagnosticoGargalo');
            const badgePicoEl = document.getElementById('badgePicoHeatmap');
            if (maxHeat > 0) {
                const semanasNoPeriodo = Math.max(1, dias / 7);
                if (modoHeatmapAtual === 'simultaneo') {
                    const mediaSimultaneaNoPico = (maxHeat / semanasNoPeriodo);
                    const lotacaoPerc = Math.min(100, Math.round((mediaSimultaneaNoPico / qtdQuartosEscopo) * 100));
                    diagEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-danger me-1"></i> <strong>Ponto de Maior Gargalo (${labelEscopo}):</strong> Nas <strong>${DIAS_COMPLETOS[picoDia]}s às ${picoHora}h</strong> ocorre a maior concentração de suítes ocupadas ao mesmo tempo (${maxHeat} ocupações acumuladas / média de <strong>~${mediaSimultaneaNoPico.toFixed(1)} de ${qtdQuartosEscopo} suítes</strong> ocupadas simultaneamente nesse horário).`;
                    badgePicoEl.innerHTML = `<span class="badge ${lotacaoPerc >= 80 ? 'bg-danger' : 'bg-warning text-dark'} fs-6">Pico: ${DIAS_NOMES[picoDia]} ${picoHora}h (~${lotacaoPerc}% lotação)</span>`;
                } else if (modoHeatmapAtual === 'receita') {
                    diagEl.innerHTML = `<i class="bi bi-cash-coin text-success me-1"></i> <strong>Horário Mais Lucrativo (${labelEscopo}):</strong> O pico de geração de receita ocorre nas <strong>${DIAS_COMPLETOS[picoDia]}s às ${picoHora}h</strong>, acumulando <strong>${formatBRL(maxHeat)}</strong> nas locações iniciadas nessa faixa.`;
                    badgePicoEl.innerHTML = `<span class="badge bg-success fs-6">Pico: ${DIAS_NOMES[picoDia]} ${picoHora}h (${formatBRL(maxHeat)})</span>`;
                } else {
                    diagEl.innerHTML = `<i class="bi bi-lightning-charge-fill text-primary me-1"></i> <strong>Horário de Pico de Entrada (${labelEscopo}):</strong> O maior fluxo de chegada de clientes ocorre nas <strong>${DIAS_COMPLETOS[picoDia]}s às ${picoHora}h</strong> com <strong>${maxHeat} entradas</strong> registradas no período.`;
                    badgePicoEl.innerHTML = `<span class="badge bg-primary fs-6">Pico: ${DIAS_NOMES[picoDia]} ${picoHora}h (${maxHeat} entradas)</span>`;
                }
            } else {
                diagEl.innerHTML = `<span class="text-muted">Nenhuma locação encontrada para a combinação de filtros selecionada.</span>`;
                badgePicoEl.innerHTML = '';
            }

            // Atualizar Gráficos Chart.js e Painel de Pressão de Demanda por Categoria
            atualizarGraficos(locacoesFiltradas, curvaHoraria, dias);
        }

        function atualizarGraficos(locacoesFiltradas, curvaHoraria, dias) {
            // 1. Gráfico de Curva Horária (0h às 23h)
            const ctxH = document.getElementById('chartHorarios').getContext('2d');
            if (chartHorariosInst) chartHorariosInst.destroy();
            chartHorariosInst = new Chart(ctxH, {
                type: 'bar',
                data: {
                    labels: Array.from({ length: 24 }, (_, i) => i + 'h'),
                    datasets: [{
                        label: modoHeatmapAtual === 'receita' ? 'Receita (R$)' : (modoHeatmapAtual === 'simultaneo' ? 'Ocupações Simultâneas' : 'Entradas (Check-ins)'),
                        data: curvaHoraria,
                        backgroundColor: modoHeatmapAtual === 'simultaneo' ? '#ef4444' : (modoHeatmapAtual === 'receita' ? '#10b981' : '#2563eb'),
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true }
                    }
                }
            });

            // 2. Gráfico Mix de Categoria & Lista de Pressão de Gargalo por Categoria
            const contagemPorCat = {};
            Object.keys(PERF_DATA.categorias_qtd).forEach(c => contagemPorCat[c] = 0);
            locacoesFiltradas.forEach(l => {
                contagemPorCat[l.t] = (contagemPorCat[l.t] || 0) + 1;
            });

            const labelsCat = Object.keys(contagemPorCat).filter(c => contagemPorCat[c] > 0);
            const valoresCat = labelsCat.map(c => contagemPorCat[c]);

            const ctxM = document.getElementById('chartMix').getContext('2d');
            if (chartMixInst) chartMixInst.destroy();
            chartMixInst = new Chart(ctxM, {
                type: 'doughnut',
                data: {
                    labels: labelsCat,
                    datasets: [{
                        data: valoresCat,
                        backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });

            // Painel de Pressão de Demanda por Categoria (Giro por suíte de cada tipo!)
            const containerPressao = document.getElementById('listaPressaoCategorias');
            containerPressao.innerHTML = '';
            const listaPressao = Object.keys(PERF_DATA.categorias_qtd).map(cat => {
                const qtdSuites = PERF_DATA.categorias_qtd[cat] || 1;
                const locsCat = PERF_DATA.locacoes.filter(l => l.t === cat).length;
                const giroCat = locsCat / (qtdSuites * dias);
                const locPorSuite = locsCat / qtdSuites;
                return { cat, qtdSuites, locsCat, giroCat, locPorSuite };
            }).sort((a, b) => b.giroCat - a.giroCat);

            const maxGiroCat = listaPressao.length > 0 ? Math.max(0.01, listaPressao[0].giroCat) : 1;

            listaPressao.forEach((item, idx) => {
                const percBarra = Math.min(100, Math.round((item.giroCat / maxGiroCat) * 100));
                const corBarra = idx === 0 ? 'bg-danger' : (idx === 1 ? 'bg-warning' : 'bg-primary');
                const div = document.createElement('div');
                div.className = 'mb-3 p-2 rounded border bg-light';
                div.style.cursor = 'pointer';
                div.title = 'Clique para filtrar o Mapa de Calor por esta categoria';
                div.onclick = () => {
                    document.getElementById('filtroCategoria').value = item.cat;
                    atualizarOpcoesSelectQuarto('all');
                    aplicarFiltrosInstantaneos();
                };
                div.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-bold text-dark">${item.cat} <span class="badge bg-secondary-subtle text-secondary">${item.qtdSuites} ${item.qtdSuites > 1 ? 'suítes' : 'suíte'}</span></span>
                        <span class="fw-bold ${idx === 0 ? 'text-danger' : 'text-primary'}">${item.locPorSuite.toFixed(1).replace('.', ',')} loc/suíte</span>
                    </div>
                    <div class="progress" style="height: 7px;">
                        <div class="progress-bar ${corBarra}" style="width: ${percBarra}%"></div>
                    </div>
                    <div class="d-flex justify-content-between text-muted mt-1" style="font-size: 0.75rem;">
                        <span>Total: ${item.locsCat} locações</span>
                        <span>Giro: ${item.giroCat.toFixed(2).replace('.', ',')} / dia</span>
                    </div>
                `;
                containerPressao.appendChild(div);
            });

            // 3. Gráfico Ranking de Receita por Quarto
            const receitaPorQuarto = {};
            locacoesFiltradas.forEach(l => {
                receitaPorQuarto[l.q] = (receitaPorQuarto[l.q] || 0) + l.val;
            });
            const quartosOrdenados = Object.keys(receitaPorQuarto)
                .map(q => ({ q, val: receitaPorQuarto[q] }))
                .sort((a, b) => b.val - a.val);

            const ctxR = document.getElementById('chartRanking').getContext('2d');
            if (chartRankingInst) chartRankingInst.destroy();
            chartRankingInst = new Chart(ctxR, {
                type: 'bar',
                data: {
                    labels: quartosOrdenados.map(x => 'Quarto ' + x.q),
                    datasets: [{
                        label: 'Receita (R$)',
                        data: quartosOrdenados.map(x => Number(x.val.toFixed(2))),
                        backgroundColor: '#10b981',
                        borderRadius: 5
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }
                }
            });

            // 4. Gráfico Destino do Faturamento
            const fatTotalBruto = PERF_DATA.locacoes.reduce((a, b) => a + b.val, 0) + Number(PERF_DATA.vendas_avulsas || 0);
            const lucroLiquidoGeral = fatTotalBruto - Number(PERF_DATA.total_despesas || 0);
            const destLabels = PERF_DATA.despesas_categorias.map(d => d.categoria);
            const destValues = PERF_DATA.despesas_categorias.map(d => d.valor);
            if (lucroLiquidoGeral > 0) {
                destLabels.push('Lucro Líquido');
                destValues.push(Number(lucroLiquidoGeral.toFixed(2)));
            }

            const ctxD = document.getElementById('chartDestino').getContext('2d');
            if (chartDestinoInst) chartDestinoInst.destroy();
            chartDestinoInst = new Chart(ctxD, {
                type: 'doughnut',
                data: {
                    labels: destLabels,
                    datasets: [{
                        data: destValues,
                        backgroundColor: ['#f59e0b', '#3b82f6', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        function carregarPeriodoAjax() {
            const p = document.getElementById('period').value;
            const di = document.getElementById('data_inicio').value;
            const df = document.getElementById('data_fim').value;

            if (p === 'custom' && (!di || !df)) {
                return;
            }

            document.getElementById('loadingIndicator').style.display = 'block';
            const url = `performance.php?ajax=1&period=${encodeURIComponent(p)}&data_inicio=${encodeURIComponent(di)}&data_fim=${encodeURIComponent(df)}`;

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    PERF_DATA = data;
                    popularSelectsQuartosECategorias();
                    aplicarFiltrosInstantaneos();
                })
                .catch(err => console.error('Erro ao atualizar período:', err))
                .finally(() => {
                    document.getElementById('loadingIndicator').style.display = 'none';
                });
        }

        function exportToWhats() {
            const escopo = document.getElementById('badgeFiltroHeatmap').textContent.trim();
            const periodo = document.getElementById('lblPeriodoAtivo').textContent.trim();
            const valorPrincipal = document.getElementById('heroValor').textContent.trim();
            const tituloPrincipal = document.getElementById('heroTitulo').textContent.trim();
            const ticket = document.getElementById('kpiTicket').textContent.trim();
            const giro = document.getElementById('kpiGiro').textContent.trim();
            const ocupacao = document.getElementById('kpiOcupacao').textContent.trim();
            const gargalo = document.getElementById('textoDiagnosticoGargalo').innerText.trim();

            let texto = `*📊 Relatório de Performance - Motel Inteligente*\n`;
            texto += `_Período: ${periodo}_\n`;
            texto += `_Filtro: ${escopo}_\n\n`;
            texto += `*${tituloPrincipal}:* ${valorPrincipal}\n`;
            texto += `*Ticket Médio:* ${ticket}\n`;
            texto += `*Giro Diário:* ${giro} loc/quarto/dia\n`;
            texto += `*Taxa de Ocupação:* ${ocupacao}\n\n`;
            if (gargalo) {
                texto += `*🔥 Diagnóstico de Fluxo:*\n${gargalo}\n`;
            }

            const url = `https://api.whatsapp.com/send?text=${encodeURIComponent(texto)}`;
            window.open(url, '_blank');
        }

        $(document).ready(function() {
            $("#data_inicio, #data_fim").datepicker({
                dateFormat: 'dd/mm/yy',
                dayNames: ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'],
                dayNamesMin: ['D','S','T','Q','Q','S','S'],
                monthNames: ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
                monthNamesShort: ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']
            });

            // Ao trocar o período no select, já busca via AJAX na hora (sem recarregar a página!)
            $('#period').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#custom_period').slideDown(150);
                } else {
                    $('#custom_period').slideUp(150);
                    carregarPeriodoAjax();
                }
            });

            // Ao trocar Categoria, atualiza lista de quartos daquela categoria e recalcula tudo em 0ms!
            $('#filtroCategoria').on('change', function() {
                atualizarOpcoesSelectQuarto('all');
                aplicarFiltrosInstantaneos();
            });

            // Ao trocar Quarto ou Modalidade, recalcula tudo em 0ms!
            $('#filtroQuarto, #filtroModalidade').on('change', function() {
                aplicarFiltrosInstantaneos();
            });

            popularSelectsQuartosECategorias();
            aplicarFiltrosInstantaneos();
        });
    </script>
</body>
</html>
