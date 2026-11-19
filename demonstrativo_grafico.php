<?php
date_default_timezone_set('America/Sao_Paulo');

// Verifica se o cookie da filial existe
if (!isset($_COOKIE["usuario_filial"])) {
    header("Location: index.php");
    exit();
}

$filial = $_COOKIE["usuario_filial"];

// Inclui a conexão correta
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

include 'verificar_acesso.php';

// --- DEFINIÇÃO DO PERÍODO DE FILTRO ---
$selectedPeriod = $_POST["period"] ?? 'this_month';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["period"])) {
    $period = $_POST["period"];
    switch ($period) {
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
        case "this_month":
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "last_month":
            $dataInicio = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
            $dataFim = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
            break;
        case "this_year":
            $dataInicio = date('Y-01-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "custom":
            if (isset($_POST["data_inicio"]) && isset($_POST["data_fim"]) && !empty($_POST["data_inicio"]) && !empty($_POST["data_fim"])) {
                $dataInicio = formatarData($_POST["data_inicio"], true);
                $dataFim = formatarData($_POST["data_fim"], false);
            } else {
                $dataInicio = date('Y-m-01') . ' 00:00:00';
                $dataFim = date('Y-m-d') . ' 23:59:59';
            }
            break;
        default:
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
    }
} else {
    $dataInicio = date('Y-m-01') . ' 00:00:00';
    $dataFim = date('Y-m-d') . ' 23:59:59';
}

// Conectando ao banco de dados
$conexao = conectarAoBanco();
$paginaAtual = basename($_SERVER['PHP_SELF']);

verificarCookie($conexao, $paginaAtual);
verificarCargoUsuario(['admin', 'gerente']);

$numVendido = 0;
$valorVendido = 0.0;
$totalLocacoes = 0;
$faturamentoTotalCaixas = 0.0;
$dias = 0;
$caixas = [];
$produtosVendidos = [];
$locacoes = [];
$locacoesPorQuarto = [];
$locacoesPorTipoQuarto = [];

if ($conexao !== null) {
    $idsCaixa = getIdCaixas($conexao, $dataInicio, $dataFim);
    $caixas = dadosCaixa($conexao, $idsCaixa);

    foreach ($caixas as $c) {
        $faturamentoTotalCaixas += (float)$c[1];
    }

    $data1 = new DateTime(substr($dataInicio, 0, 10));
    $data2 = new DateTime(substr($dataFim, 0, 10));
    $intervalo = $data1->diff($data2);
    $dias = $intervalo->days + 1;

    $produtosVendidos = carregaProdutosVendidos($conexao, $idsCaixa);

    foreach ($produtosVendidos as $produto) {
        $numVendido += (int)$produto['quantidade'];
        $valorVendido += (float)$produto['valorTotal'];
    }

    $locacoes = carregarLocacoes($conexao, $idsCaixa);

    foreach ($locacoes as $loc) {
        $totalLocacoes += (int)$loc['total_locacoes'];
    }

    list($locacoesPorQuarto, $locacoesPorTipoQuarto) = obterLocacoesPorQuartoETipo($idsCaixa, $conexao);
    $conexao->close();
}

$mediaPorCaixa = count($caixas) > 0 ? ($faturamentoTotalCaixas / count($caixas)) : 0.0;
$mediaLocacoesDia = $dias > 0 ? ($totalLocacoes / $dias) : 0.0;

// -------------------- FUNÇÕES AUXILIARES --------------------

function formatarData($data, $horaInicial = true) {
    $partes = explode('/', $data);
    if (count($partes) === 3) {
        $dia = $partes[0];
        $mes = $partes[1];
        $ano = $partes[2];
        $hora = $horaInicial ? '00:00:00' : '23:59:59';
        return sprintf('%04d-%02d-%02d %s', $ano, $mes, $dia, $hora);
    }
    return date('Y-m-d') . ($horaInicial ? ' 00:00:00' : ' 23:59:59');
}

function getIdCaixas($conexao, $dataInicio, $dataFim) {
    $sql = "SELECT id FROM caixa WHERE horaabre >= ? AND horaabre <= ? ORDER BY horaabre ASC";
    $idCaixas = array();
    $statement = null;

    try {
        $statement = $conexao->prepare($sql);
        $statement->bind_param("ss", $dataInicio, $dataFim);
        $statement->execute();
        $result = $statement->get_result();

        while ($row = $result->fetch_assoc()) {
            $idCaixas[] = (int)$row['id'];
        }
    } catch (Exception $e) {
    } finally {
        if (isset($statement)) {
            $statement->close();
        }
    }
    return $idCaixas;
}

function dadosCaixa($conexao, $idsCaixa) {
    if (empty($idsCaixa)) {
        return [];
    }
    $idsStr = implode(',', array_map('intval', $idsCaixa));
    $sql = "
        SELECT 
            c.id,
            DATE_FORMAT(c.horaabre, '%d/%m') AS data_fmt,
            DATE_FORMAT(c.horaabre, '%d/%m/%Y %H:%i') AS hora_abre_fmt,
            c.usuarioabre,
            COALESCE(loc.soma_loc, 0) + COALESCE(av.soma_av, 0) AS total_faturado,
            c.saldofecha
        FROM caixa c
        LEFT JOIN (
            SELECT idcaixaatual, SUM(pagodinheiro + pagopix + pagocartao) AS soma_loc
            FROM registralocado
            WHERE idcaixaatual IN ($idsStr)
            GROUP BY idcaixaatual
        ) loc ON c.id = loc.idcaixaatual
        LEFT JOIN (
            SELECT idcaixa, SUM(valortotal) AS soma_av
            FROM vendas_avulsas
            WHERE idcaixa IN ($idsStr) AND tipo != 'adiantamento'
            GROUP BY idcaixa
        ) av ON c.id = av.idcaixa
        WHERE c.id IN ($idsStr)
        ORDER BY c.horaabre ASC
    ";

    $data = array();
    $res = $conexao->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $valor = (float)$row['total_faturado'];
            if ($valor <= 0 && $row['saldofecha'] !== null) {
                $valor = (float)$row['saldofecha'];
            }
            $data[] = array(
                $row['data_fmt'],
                round($valor, 2),
                $row['id'],
                $row['hora_abre_fmt'],
                $row['usuarioabre'] ?? ''
            );
        }
    }
    return $data;
}

function carregaProdutosVendidos($conexao, $idsCaixa) {
    $listaProdutos = array();
    if (empty($idsCaixa)) {
        return $listaProdutos;
    }

    $placeholders = implode(',', array_fill(0, count($idsCaixa), '?'));
    $consultaSQL = "
        SELECT idproduto, SUM(quantidade) AS quantidade_total, valorunidade, SUM(valortotal) AS soma_total
        FROM (
            SELECT idproduto, quantidade, valorunidade, valortotal
            FROM registravendido 
            WHERE idcaixaatual IN ($placeholders)
            UNION ALL
            SELECT idproduto, quantidade, valorunidade, valortotal
            FROM vendas_avulsas 
            WHERE idcaixa IN ($placeholders) AND tipo != 'adiantamento'
        ) AS combinado 
        GROUP BY idproduto, valorunidade
    ";

    $statement = null;
    try {
        $statement = $conexao->prepare($consultaSQL);
        $tipos = str_repeat("i", count($idsCaixa) * 2);
        $paramsCombined = array_merge($idsCaixa, $idsCaixa);
        $statement->bind_param($tipos, ...$paramsCombined);
        $statement->execute();
        $resultado = $statement->get_result();

        while ($row = $resultado->fetch_assoc()) {
            $qtd = (int)$row['quantidade_total'];
            if ($qtd <= 0) $qtd = 1;
            $valUnd = (float)$row['valorunidade'];
            $valTot = isset($row['soma_total']) && (float)$row['soma_total'] > 0
                ? (float)$row['soma_total']
                : ($qtd * $valUnd);

            $desc = getDescricao($row['idproduto'], $conexao);
            if (!$desc) {
                $desc = "Produto #" . $row['idproduto'];
            }

            $listaProdutos[] = array(
                'idProduto' => $row['idproduto'],
                'descricao' => $desc,
                'quantidade' => $qtd,
                'valorUnd' => $valUnd,
                'valorTotal' => $valTot
            );
        }
    } catch (Exception $e) {
    } finally {
        if (isset($statement)) {
            $statement->close();
        }
    }

    usort($listaProdutos, function ($a, $b) {
        return $b['quantidade'] - $a['quantidade'];
    });

    return $listaProdutos;
}

function getDescricao($idPassado, $conexao) {
    $consultaSQL = "SELECT descricao FROM produtos WHERE idproduto = ?";
    $resultado = null;
    $statement = null;

    try {
        $statement = $conexao->prepare($consultaSQL);
        if (!$statement) return null;
        $statement->bind_param("i", $idPassado);
        if (!$statement->execute()) return null;
        $resultado = $statement->get_result();
        if ($resultado && $resultado->num_rows > 0) {
            $row = $resultado->fetch_assoc();
            return $row['descricao'];
        }
        return null;
    } catch (Exception $e) {
        return null;
    } finally {
        if ($resultado) $resultado->close();
        if ($statement) $statement->close();
    }
}

function carregarLocacoes($conexao, $idsCaixa) {
    if (empty($idsCaixa)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($idsCaixa), '?'));
    $consultaSQL = "SELECT 
                        DATE_FORMAT(caixa.horaabre, '%d/%m') as data_fmt,
                        DATE_FORMAT(caixa.horaabre, '%d/%m/%Y %H:%i') as data_completa,
                        caixa.id as id_caixa,
                        COALESCE(rl.total_locacoes, 0) as total_locacoes
                    FROM caixa
                    LEFT JOIN (
                        SELECT idcaixaatual, COUNT(*) as total_locacoes 
                        FROM registralocado 
                        WHERE idcaixaatual IN ($placeholders)
                        GROUP BY idcaixaatual
                    ) rl ON caixa.id = rl.idcaixaatual
                    WHERE caixa.id IN ($placeholders)
                    ORDER BY caixa.horaabre ASC";

    $locacoesPorCaixa = [];
    $statement = null;

    try {
        $statement = $conexao->prepare($consultaSQL);
        $tipos = str_repeat("i", count($idsCaixa) * 2);
        $params = array_merge($idsCaixa, $idsCaixa);
        $statement->bind_param($tipos, ...$params);
        $statement->execute();
        $resultado = $statement->get_result();

        while ($row = $resultado->fetch_assoc()) {
            $locacoesPorCaixa[] = [
                'data' => $row['data_fmt'],
                'data_completa' => $row['data_completa'],
                'id_caixa' => (int)$row['id_caixa'],
                'total_locacoes' => (int)$row['total_locacoes']
            ];
        }
    } catch (Exception $e) {
    } finally {
        if ($statement) {
            $statement->close();
        }
    }

    return $locacoesPorCaixa;
}

function obterLocacoesPorQuartoETipo($idsCaixa, $conn) {
    $locacoesPorQuarto = [];
    $locacoesPorTipoQuarto = [];
    if (empty($idsCaixa)) {
        return [$locacoesPorQuarto, $locacoesPorTipoQuarto];
    }

    $placeholders = implode(',', array_fill(0, count($idsCaixa), '?'));
    $sqlLocacoes = "SELECT numquarto FROM registralocado WHERE idcaixaatual IN ($placeholders)";
    $locacoesGerais = [];
    $statementLocacoes = null;

    try {
        $statementLocacoes = $conn->prepare($sqlLocacoes);
        $tipos = str_repeat("i", count($idsCaixa));
        $statementLocacoes->bind_param($tipos, ...$idsCaixa);
        $statementLocacoes->execute();
        $resultadoLocacoes = $statementLocacoes->get_result();

        while ($row = $resultadoLocacoes->fetch_assoc()) {
            $locacoesGerais[] = $row;
        }
    } catch (Exception $e) {
    } finally {
        if ($statementLocacoes) {
            $statementLocacoes->close();
        }
    }

    $quartosEncontrados = [];
    foreach ($locacoesGerais as $row) {
        $numeroQuarto = $row['numquarto'];
        if (isset($locacoesPorQuarto[$numeroQuarto])) {
            $locacoesPorQuarto[$numeroQuarto]['total_locacoes']++;
        } else {
            $locacoesPorQuarto[$numeroQuarto] = [
                'numeroquarto' => $numeroQuarto,
                'total_locacoes' => 1,
                'tipoquarto' => 'Suíte'
            ];
            $quartosEncontrados[] = $numeroQuarto;
        }
    }

    if (!empty($quartosEncontrados)) {
        $quartosPlaceholders = implode(',', array_fill(0, count($quartosEncontrados), '?'));
        $sqlTipos = "SELECT numeroquarto, tipoquarto FROM quartos WHERE numeroquarto IN ($quartosPlaceholders)";
        $statementTipos = null;

        try {
            $statementTipos = $conn->prepare($sqlTipos);
            $tiposQ = str_repeat("i", count($quartosEncontrados));
            $statementTipos->bind_param($tiposQ, ...$quartosEncontrados);
            $statementTipos->execute();
            $resultadoTipos = $statementTipos->get_result();

            $tiposQuartosMap = [];
            while ($row = $resultadoTipos->fetch_assoc()) {
                $tiposQuartosMap[$row['numeroquarto']] = $row['tipoquarto'];
            }

            foreach ($locacoesPorQuarto as $numQ => &$quartoRef) {
                $tipoQuarto = $tiposQuartosMap[$numQ] ?? 'Padrão';
                $quartoRef['tipoquarto'] = $tipoQuarto;

                if (!isset($locacoesPorTipoQuarto[$tipoQuarto])) {
                    $locacoesPorTipoQuarto[$tipoQuarto] = 0;
                }
                $locacoesPorTipoQuarto[$tipoQuarto] += $quartoRef['total_locacoes'];
            }
            unset($quartoRef);
        } catch (Exception $e) {
        } finally {
            if ($statementTipos) {
                $statementTipos->close();
            }
        }
    }

    $locacoesPorQuartoArray = array_values($locacoesPorQuarto);
    usort($locacoesPorQuartoArray, function ($a, $b) {
        return $b['total_locacoes'] - $a['total_locacoes'];
    });
    arsort($locacoesPorTipoQuarto);

    return [$locacoesPorQuartoArray, $locacoesPorTipoQuarto];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demonstrativo Gráfico - <?= ucfirst($filial) ?></title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

    <!-- Google Fonts - Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            margin-bottom: 95px;
        }

        .main-container {
            max-width: 1200px;
            margin-top: 16px;
            padding-left: 12px;
            padding-right: 12px;
        }

        @media (min-width: 768px) {
            .main-container {
                margin-top: 28px;
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
                padding: 20px;
            }
        }

        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 14px 16px;
            box-shadow: 0 8px 24px -10px rgba(0, 0, 0, 0.07);
            height: 100%;
        }

        .metric-label {
            font-size: 0.74rem;
            letter-spacing: 0.6px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }

        .metric-value {
            font-size: 1.3rem;
            font-weight: 700;
            margin-top: 4px;
            margin-bottom: 0;
        }

        @media (min-width: 768px) {
            .metric-value {
                font-size: 1.6rem;
            }
        }

        /* Abas Mobile-First com Scroll Horizontal Suave */
        .nav-tabs-premium {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 16px;
            flex-wrap: nowrap;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            gap: 6px;
        }

        .nav-tabs-premium::-webkit-scrollbar {
            display: none;
        }

        .nav-tabs-premium .nav-link {
            border: none;
            color: #64748b;
            font-weight: 600;
            padding: 10px 16px;
            font-size: 0.9rem;
            white-space: nowrap;
            position: relative;
            background: transparent;
            transition: color 0.2s ease;
        }

        @media (min-width: 768px) {
            .nav-tabs-premium .nav-link {
                padding: 12px 22px;
                font-size: 1rem;
            }
        }

        .nav-tabs-premium .nav-link.active {
            color: #2563eb;
            background: transparent;
        }

        .nav-tabs-premium .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background-color: #2563eb;
            border-radius: 3px;
        }

        .conteudo-aba {
            display: none;
        }

        .conteudo-aba.ativo {
            display: block;
        }

        /* Container Inteligente de Gráfico (Evita esmagamento no mobile!) */
        .chart-scroll-outer {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 6px;
        }

        .chart-canvas-box {
            position: relative;
            height: 290px;
            width: 100%;
        }

        @media (min-width: 768px) {
            .chart-canvas-box {
                height: 360px;
            }
        }

        .mobile-swipe-hint {
            font-size: 0.76rem;
            color: #64748b;
            background: #f1f5f9;
            border-radius: 20px;
            padding: 3px 10px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .custom-table {
            background: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--card-border);
        }

        .custom-table th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 600;
            font-size: 0.84rem;
            padding: 10px 12px;
            border-bottom: 2px solid rgba(0, 0, 0, 0.06);
        }

        .custom-table td {
            padding: 10px 12px;
            vertical-align: middle;
            font-size: 0.88rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
        }

        .progress-slim {
            height: 6px;
            border-radius: 4px;
            background-color: #e2e8f0;
        }

        .form-control, .form-select {
            font-size: 16px;
        }

        @media (min-width: 768px) {
            .form-control, .form-select {
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>
    <div class="container main-container">
        
        <!-- Header -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 mb-md-4">
            <div>
                <h2 class="title-gradient mb-0 fs-3 fs-md-2">Demonstrativo Gráfico</h2>
                <p class="text-muted mb-0 small">Análise visual de caixas, produtos e ocupação de <?= ucfirst($filial) ?></p>
            </div>
        </div>

        <!-- Painel de Filtro -->
        <div class="glass-panel mb-3 mb-md-4">
            <form method="post" action="demonstrativo_grafico.php" class="row g-2 g-md-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="period" class="form-label fw-bold text-muted small mb-1">Período de Análise</label>
                    <select name="period" id="period" class="form-select">
                        <option value="7" <?= ($selectedPeriod == '7') ? 'selected' : ''; ?>>Últimos 7 dias</option>
                        <option value="15" <?= ($selectedPeriod == '15') ? 'selected' : ''; ?>>Últimos 15 dias</option>
                        <option value="30" <?= ($selectedPeriod == '30') ? 'selected' : ''; ?>>Últimos 30 dias</option>
                        <option value="this_month" <?= ($selectedPeriod == 'this_month') ? 'selected' : ''; ?>>Este Mês</option>
                        <option value="last_month" <?= ($selectedPeriod == 'last_month') ? 'selected' : ''; ?>>Mês Passado</option>
                        <option value="this_year" <?= ($selectedPeriod == 'this_year') ? 'selected' : ''; ?>>Anual (Este Ano)</option>
                        <option value="custom" <?= ($selectedPeriod == 'custom') ? 'selected' : ''; ?>>Período Personalizado</option>
                    </select>
                </div>

                <div id="custom_period" class="col-12 col-md-6 row g-2 m-0" style="display: <?= ($selectedPeriod == 'custom') ? 'flex' : 'none'; ?>;">
                    <div class="col-6 ps-0">
                        <label for="data_inicio" class="form-label fw-bold text-muted small mb-1">Início</label>
                        <input type="text" id="data_inicio" name="data_inicio" class="form-control"
                            value="<?= isset($_POST['data_inicio']) ? htmlspecialchars($_POST['data_inicio']) : ''; ?>"
                            placeholder="dd/mm/aaaa" autocomplete="off">
                    </div>
                    <div class="col-6 pe-0">
                        <label for="data_fim" class="form-label fw-bold text-muted small mb-1">Fim</label>
                        <input type="text" id="data_fim" name="data_fim" class="form-control"
                            value="<?= isset($_POST['data_fim']) ? htmlspecialchars($_POST['data_fim']) : ''; ?>"
                            placeholder="dd/mm/aaaa" autocomplete="off">
                    </div>
                </div>

                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary fw-semibold py-2">
                        <i class="bi bi-funnel-fill me-1"></i> Aplicar
                    </button>
                </div>
            </form>
        </div>

        <!-- Abas de Navegação -->
        <ul class="nav nav-tabs nav-tabs-premium abas" role="tablist">
            <li class="nav-item">
                <button type="button" class="nav-link active" id="aba-caixas">
                    <i class="bi bi-cash-coin me-1"></i> Faturamento por Caixa
                    <span class="badge rounded-pill bg-primary-subtle text-primary ms-1"><?= count($caixas) ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" id="aba-produtos">
                    <i class="bi bi-box-seam me-1"></i> Produtos Vendidos
                    <span class="badge rounded-pill bg-success-subtle text-success ms-1"><?= $numVendido ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" id="aba-locacoes">
                    <i class="bi bi-door-open me-1"></i> Locações & Suítes
                    <span class="badge rounded-pill bg-warning-subtle text-dark ms-1"><?= $totalLocacoes ?></span>
                </button>
            </li>
        </ul>

        <!-- ==================== ABA 1: CAIXAS ==================== -->
        <div id="conteudo-caixas" class="conteudo-aba ativo">
            <div class="row g-2 g-md-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="metric-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Faturamento Total</div>
                            <div class="metric-value text-primary">R$ <?= number_format($faturamentoTotalCaixas, 2, ',', '.') ?></div>
                        </div>
                        <i class="bi bi-wallet2 text-primary fs-2 opacity-75"></i>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="metric-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Caixas no Período</div>
                            <div class="metric-value text-dark"><?= count($caixas) ?></div>
                        </div>
                        <i class="bi bi-archive text-secondary fs-3 opacity-50 d-none d-sm-inline"></i>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="metric-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Média por Caixa</div>
                            <div class="metric-value text-success">R$ <?= number_format($mediaPorCaixa, 2, ',', '.') ?></div>
                        </div>
                        <i class="bi bi-graph-up-arrow text-success fs-3 opacity-75 d-none d-sm-inline"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-activity text-primary me-1"></i> Evolução de Faturamento por Caixa
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <?php if (count($caixas) > 8): ?>
                            <span class="mobile-swipe-hint d-md-none">
                                <i class="bi bi-arrows-expand-vertical"></i> Deslize o gráfico para o lado
                            </span>
                        <?php endif; ?>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary active" id="btnCaixaLinha" onclick="mudarTipoGraficoCaixa('line')">Linha</button>
                            <button type="button" class="btn btn-outline-primary" id="btnCaixaBarra" onclick="mudarTipoGraficoCaixa('bar')">Barras</button>
                        </div>
                    </div>
                </div>

                <div class="chart-scroll-outer">
                    <div class="chart-canvas-box" id="wrapper-grafico-caixas">
                        <canvas id="grafico-caixas"></canvas>
                    </div>
                </div>
            </div>

            <div class="custom-table shadow-sm">
                <div class="table-responsive">
                    <table id="tabela-caixas" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Caixa / Abertura</th>
                                <th>Operador</th>
                                <th class="text-end">Valor Faturado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($caixas)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-muted">Nenhum caixa encontrado neste período.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (array_reverse($caixas) as $caixa): ?>
                                    <tr>
                                        <td class="text-nowrap">
                                            <span class="badge bg-primary-subtle text-primary me-1">#<?= $caixa[2] ?></span>
                                            <span class="fw-medium"><?= $caixa[3] ?></span>
                                        </td>
                                        <td class="text-muted"><?= htmlspecialchars($caixa[4] ?: 'N/A') ?></td>
                                        <td class="text-end fw-bold text-success text-nowrap">
                                            R$ <?= number_format((float)$caixa[1], 2, ',', '.') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== ABA 2: PRODUTOS VENDIDOS ==================== -->
        <div id="conteudo-produtos" class="conteudo-aba">
            <div class="row g-2 g-md-3 mb-3">
                <div class="col-6">
                    <div class="metric-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Itens Vendidos</div>
                            <div class="metric-value text-primary"><?= number_format($numVendido, 0, ',', '.') ?> un.</div>
                        </div>
                        <i class="bi bi-bag-check-fill text-primary fs-2 opacity-75 d-none d-sm-inline"></i>
                    </div>
                </div>
                <div class="col-6">
                    <div class="metric-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Receita de Produtos</div>
                            <div class="metric-value text-success">R$ <?= number_format($valorVendido, 2, ',', '.') ?></div>
                        </div>
                        <i class="bi bi-cash-stack text-success fs-2 opacity-75 d-none d-sm-inline"></i>
                    </div>
                </div>
            </div>

            <div class="glass-panel mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-trophy-fill text-warning me-1"></i> Top 10 Produtos Mais Vendidos
                    </h6>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary active" id="btnProdBarra" onclick="mudarTipoGraficoProdutos('bar')">Ranking (Barras)</button>
                        <button type="button" class="btn btn-outline-primary" id="btnProdRosca" onclick="mudarTipoGraficoProdutos('doughnut')">Rosca</button>
                    </div>
                </div>

                <div class="chart-canvas-box" style="height: 340px;">
                    <canvas id="grafico-produtos"></canvas>
                </div>
            </div>

            <div class="custom-table shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th class="text-center">Qtd.</th>
                                <th class="text-end d-none d-sm-table-cell">Vlr. Unit.</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($produtosVendidos)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">Nenhum produto vendido no período selecionado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($produtosVendidos as $idx => $produto): ?>
                                    <?php 
                                        $percQtd = $numVendido > 0 ? (($produto['quantidade'] / $numVendido) * 100) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-dark">
                                                <span class="text-muted small me-1"><?= $idx + 1 ?>º</span>
                                                <?= htmlspecialchars($produto['descricao']) ?>
                                            </div>
                                            <div class="progress progress-slim mt-1" style="max-width: 180px;">
                                                <div class="progress-bar bg-success" style="width: <?= min(100, round($percQtd, 1)) ?>%"></div>
                                            </div>
                                        </td>
                                        <td class="text-center fw-bold text-nowrap">
                                            <?= $produto['quantidade'] ?>
                                            <span class="d-block text-muted" style="font-size: 0.72rem;"><?= number_format($percQtd, 1, ',', '.') ?>%</span>
                                        </td>
                                        <td class="text-end text-muted d-none d-sm-table-cell text-nowrap">
                                            R$ <?= number_format((float)$produto['valorUnd'], 2, ',', '.') ?>
                                        </td>
                                        <td class="text-end fw-bold text-success text-nowrap">
                                            R$ <?= number_format((float)$produto['valorTotal'], 2, ',', '.') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== ABA 3: LOCAÇÕES & SUÍTES ==================== -->
        <div id="conteudo-locacoes" class="conteudo-aba">
            <div class="row g-2 g-md-3 mb-3">
                <div class="col-4">
                    <div class="metric-card">
                        <div class="metric-label">Dias</div>
                        <div class="metric-value text-info"><?= $dias ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="metric-card">
                        <div class="metric-label">Locações</div>
                        <div class="metric-value text-primary"><?= $totalLocacoes ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="metric-card">
                        <div class="metric-label">Média / Dia</div>
                        <div class="metric-value text-success"><?= number_format($mediaLocacoesDia, 1, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <div class="glass-panel mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-bar-chart-fill text-info me-1"></i> Quantidade de Locações por Caixa
                    </h6>
                    <?php if (count($locacoes) > 8): ?>
                        <span class="mobile-swipe-hint d-md-none">
                            <i class="bi bi-arrows-expand-vertical"></i> Deslize o gráfico para o lado
                        </span>
                    <?php endif; ?>
                </div>

                <div class="chart-scroll-outer">
                    <div class="chart-canvas-box" id="wrapper-grafico-locacoes">
                        <canvas id="grafico-locacoes"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tabelas de Ranking por Suíte e por Categoria -->
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="custom-table shadow-sm h-100">
                        <div class="p-3 bg-white border-bottom fw-bold text-dark">
                            <i class="bi bi-door-closed-fill text-warning me-1"></i> Ranking de Ocupação por Quarto
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Quarto</th>
                                        <th>Categoria</th>
                                        <th class="text-end">Locações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($locacoesPorQuarto)): ?>
                                        <tr><td colspan="3" class="text-center py-3 text-muted">Sem registros no período.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($locacoesPorQuarto as $quarto): ?>
                                            <?php if ($quarto['total_locacoes'] > 0): ?>
                                                <?php $percQ = $totalLocacoes > 0 ? (($quarto['total_locacoes'] / $totalLocacoes) * 100) : 0; ?>
                                                <tr>
                                                    <td class="fw-bold">Quarto <?= $quarto['numeroquarto'] ?></td>
                                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($quarto['tipoquarto']) ?></span></td>
                                                    <td class="text-end">
                                                        <span class="fw-bold text-primary"><?= $quarto['total_locacoes'] ?></span>
                                                        <span class="text-muted small ms-1">(<?= number_format($percQ, 1, ',', '.') ?>%)</span>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="custom-table shadow-sm h-100">
                        <div class="p-3 bg-white border-bottom fw-bold text-dark">
                            <i class="bi bi-layers-fill text-danger me-1"></i> Ocupação por Categoria de Suíte
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Tipo de Quarto</th>
                                        <th class="text-end">Total de Locações</th>
                                        <th class="text-end">Participação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($locacoesPorTipoQuarto)): ?>
                                        <tr><td colspan="3" class="text-center py-3 text-muted">Sem registros no período.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($locacoesPorTipoQuarto as $tipoQuarto => $qtdTipo): ?>
                                            <?php $percT = $totalLocacoes > 0 ? (($qtdTipo / $totalLocacoes) * 100) : 0; ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?= htmlspecialchars($tipoQuarto) ?></div>
                                                    <div class="progress progress-slim mt-1" style="max-width: 140px;">
                                                        <div class="progress-bar bg-primary" style="width: <?= min(100, round($percT, 1)) ?>%"></div>
                                                    </div>
                                                </td>
                                                <td class="text-end fw-bold"><?= $qtdTipo ?></td>
                                                <td class="text-end text-muted"><?= number_format($percT, 1, ',', '.') ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        const caixasData = <?= json_encode($caixas) ?>;
        const locacoesData = <?= json_encode($locacoes) ?>;
        const produtosData = <?= json_encode($produtosVendidos) ?>;

        let chartCaixas = null;
        let chartProdutos = null;
        let chartLocacoes = null;

        function formatarBRL(valor) {
            return 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Ajusta largura mínima no mobile quando há muitos pontos para não esmagar o gráfico!
        function ajustarLarguraResponsiva(wrapperId, totalPontos) {
            const wrapper = document.getElementById(wrapperId);
            if (!wrapper) return;
            const isMobile = window.innerWidth < 768;
            if (isMobile && totalPontos > 8) {
                const larguraCalculada = Math.max(window.innerWidth - 50, totalPontos * 44);
                wrapper.style.minWidth = larguraCalculada + 'px';
            } else {
                wrapper.style.minWidth = '100%';
            }
        }

        function renderizarGraficoCaixas(tipo = 'line') {
            ajustarLarguraResponsiva('wrapper-grafico-caixas', caixasData.length);
            const canvas = document.getElementById('grafico-caixas');
            const ctx = canvas.getContext('2d');

            if (chartCaixas) {
                chartCaixas.destroy();
            }

            const labels = caixasData.map(item => item[0]);
            const valores = caixasData.map(item => Number(item[1] || 0));

            const gradient = ctx.createLinearGradient(0, 0, 0, 320);
            gradient.addColorStop(0, 'rgba(37, 99, 235, 0.32)');
            gradient.addColorStop(1, 'rgba(37, 99, 235, 0.02)');

            chartCaixas = new Chart(ctx, {
                type: tipo,
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Faturamento do Caixa',
                        data: valores,
                        borderColor: '#2563eb',
                        backgroundColor: tipo === 'line' ? gradient : 'rgba(37, 99, 235, 0.82)',
                        borderWidth: 2.5,
                        borderRadius: tipo === 'bar' ? 6 : 0,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#2563eb',
                        pointBorderWidth: 2,
                        pointRadius: caixasData.length > 25 ? 3 : 4,
                        pointHoverRadius: 6,
                        tension: 0.32,
                        fill: tipo === 'line'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 10,
                            callbacks: {
                                title: function(items) {
                                    const idx = items[0].dataIndex;
                                    const item = caixasData[idx];
                                    return item ? ('Caixa #' + item[2] + ' - ' + item[3]) : items[0].label;
                                },
                                label: function(context) {
                                    return ' Faturamento: ' + formatarBRL(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0,
                                font: { size: 11 }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: {
                                font: { size: 11 },
                                callback: function(value) {
                                    if (value >= 1000) {
                                        return 'R$ ' + (value / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + 'k';
                                    }
                                    return 'R$ ' + value;
                                }
                            }
                        }
                    }
                }
            });
        }

        function mudarTipoGraficoCaixa(tipo) {
            document.getElementById('btnCaixaLinha').classList.toggle('active', tipo === 'line');
            document.getElementById('btnCaixaBarra').classList.toggle('active', tipo === 'bar');
            renderizarGraficoCaixas(tipo);
        }

        function renderizarGraficoProdutos(tipo = 'bar') {
            const canvas = document.getElementById('grafico-produtos');
            const ctx = canvas.getContext('2d');

            if (chartProdutos) {
                chartProdutos.destroy();
            }

            // Pega os Top 10 produtos para o gráfico ficar limpo e legível no celular e no PC
            const topProdutos = produtosData.slice(0, 10);
            const labels = topProdutos.map(p => p.descricao.length > 22 ? p.descricao.substring(0, 20) + '…' : p.descricao);
            const quantidades = topProdutos.map(p => Number(p.quantidade || 0));

            const palette = [
                '#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                '#06b6d4', '#ec4899', '#14b8a6', '#f97316', '#6366f1'
            ];

            const isHorizontalBar = (tipo === 'bar');

            chartProdutos = new Chart(ctx, {
                type: isHorizontalBar ? 'bar' : 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Unidades Vendidas',
                        data: quantidades,
                        backgroundColor: palette,
                        borderRadius: isHorizontalBar ? 6 : 0,
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: isHorizontalBar ? 'y' : 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: !isHorizontalBar,
                            position: 'bottom',
                            labels: { boxWidth: 12, font: { size: 11 } }
                        },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 10,
                            callbacks: {
                                title: function(items) {
                                    const idx = items[0].dataIndex;
                                    return topProdutos[idx] ? topProdutos[idx].descricao : items[0].label;
                                },
                                label: function(context) {
                                    const idx = context.dataIndex;
                                    const p = topProdutos[idx];
                                    const qtd = p ? p.quantidade : context.formattedValue;
                                    const totalRS = p ? formatarBRL(p.valorTotal) : '';
                                    return ` Vendidos: ${qtd} un. (${totalRS})`;
                                }
                            }
                        }
                    },
                    scales: isHorizontalBar ? {
                        x: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { precision: 0 }
                        },
                        y: {
                            grid: { display: false },
                            ticks: { font: { size: 11, weight: '600' } }
                        }
                    } : {}
                }
            });
        }

        function mudarTipoGraficoProdutos(tipo) {
            document.getElementById('btnProdBarra').classList.toggle('active', tipo === 'bar');
            document.getElementById('btnProdRosca').classList.toggle('active', tipo === 'doughnut');
            renderizarGraficoProdutos(tipo);
        }

        function renderizarGraficoLocacoes() {
            ajustarLarguraResponsiva('wrapper-grafico-locacoes', locacoesData.length);
            const canvas = document.getElementById('grafico-locacoes');
            const ctx = canvas.getContext('2d');

            if (chartLocacoes) {
                chartLocacoes.destroy();
            }

            const labels = locacoesData.map(l => l.data);
            const valores = locacoesData.map(l => Number(l.total_locacoes || 0));

            chartLocacoes = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Locações no Caixa',
                        data: valores,
                        backgroundColor: 'rgba(14, 165, 233, 0.85)',
                        borderColor: '#0284c7',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 10,
                            callbacks: {
                                title: function(items) {
                                    const idx = items[0].dataIndex;
                                    const item = locacoesData[idx];
                                    return item ? ('Caixa #' + item.id_caixa + ' (' + item.data_completa + ')') : items[0].label;
                                },
                                label: function(context) {
                                    return ' Hospedagens: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 11 } }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { precision: 0, font: { size: 11 } }
                        }
                    }
                }
            });
        }

        $(function () {
            $("#period").change(function () {
                if ($(this).val() == 'custom') {
                    $("#custom_period").css('display', 'flex');
                } else {
                    $("#custom_period").hide();
                }
            });

            $("#data_inicio, #data_fim").datepicker({
                dateFormat: 'dd/mm/yy',
                dayNames: ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'],
                dayNamesMin: ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'],
                monthNames: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
                monthNamesShort: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                nextText: 'Próximo',
                prevText: 'Anterior'
            });

            $(".abas button").click(function () {
                $(".abas button").removeClass("active");
                $(".conteudo-aba").removeClass("ativo");

                $(this).addClass("active");
                const targetId = $(this).attr("id").replace("aba-", "conteudo-");
                $("#" + targetId).addClass("ativo");

                // Re-renderiza/redimensiona o gráfico da aba aberta
                if (targetId === 'conteudo-caixas') {
                    renderizarGraficoCaixas(document.getElementById('btnCaixaBarra').classList.contains('active') ? 'bar' : 'line');
                } else if (targetId === 'conteudo-produtos') {
                    renderizarGraficoProdutos(document.getElementById('btnProdRosca').classList.contains('active') ? 'doughnut' : 'bar');
                } else if (targetId === 'conteudo-locacoes') {
                    renderizarGraficoLocacoes();
                }
            });

            // Inicializa os gráficos
            renderizarGraficoCaixas('line');
            renderizarGraficoProdutos('bar');
            renderizarGraficoLocacoes();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'menu.php'; ?>
</body>
</html>
