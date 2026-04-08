<?php
// Função para formatar a data no formato MySQL (Y-m-d H:i:s)
function formatarData($data, $horaInicial = true)
{
    $partes = explode('/', $data);
    if(count($partes) == 3){
        $dia = $partes[0];
        $mes = $partes[1];
        $ano = $partes[2];
        $hora = $horaInicial ? '00:00:00' : '23:59:59';
        return sprintf('%04d-%02d-%02d %s', $ano, $mes, $dia, $hora);
    }
    return date('Y-m-d H:i:s');
}

$selectedPeriod = 'this_month';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["period"])) {
    $selectedPeriod = $_POST["period"];
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
        case "this_month":
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
        case "last_month":
            $dataInicio = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
            $dataFim = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
            break;
        case "custom":
            if (isset($_POST["data_inicio"]) && isset($_POST["data_fim"])) {
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

// Helper para calcular dias
$timestampInicio = strtotime($dataInicio);
$timestampFim = strtotime($dataFim);
$diferencaDias = max(1, round(($timestampFim - $timestampInicio) / (60 * 60 * 24))); // Minimum 1 for div by zero

// Calcular período anterior para insights
$diasPeriodo = $diferencaDias;
$prevInicio = date('Y-m-d H:i:s', $timestampInicio - ($diasPeriodo * 24 * 60 * 60));
$prevFim = date('Y-m-d H:i:s', $timestampFim - ($diasPeriodo * 24 * 60 * 60));

function getFaturamentoETotal($conexao, $inicio, $fim) {
    $sql = "SELECT SUM(saldofecha) AS faturamentomes, COUNT(c.id) as num_caixas
            FROM caixa c WHERE horaabre >= '$inicio' AND horaabre <= '$fim'";
    $res = $conexao->query($sql);
    $fat = 0;
    if ($res && $row = $res->fetch_assoc()) {
        $fat = $row['faturamentomes'] ?? 0;
    }
    
    // Obter numero de locacoes no periodo usando os caixas do periodo
    $sqlLoc = "SELECT COUNT(r.idlocacao) as total_loc 
               FROM registralocado r JOIN caixa c ON r.idcaixaatual = c.id 
               WHERE c.horaabre >= '$inicio' AND c.horaabre <= '$fim'";
    $resLoc = $conexao->query($sqlLoc);
    $loc = 0;
    if ($resLoc && $rowLoc = $resLoc->fetch_assoc()) {
        $loc = $rowLoc['total_loc'] ?? 0;
    }
    return ['faturamento' => $fat, 'locacoes' => $loc];
}

$atualData = getFaturamentoETotal($conexao, $dataInicio, $dataFim);
$faturamentoAtual = $atualData['faturamento'];
$locacoesAtual = $atualData['locacoes'];

$prevData = getFaturamentoETotal($conexao, $prevInicio, $prevFim);
$faturamentoPrev = $prevData['faturamento'];

// KPIs
$qtdQuartos = 9; // Conforme requisito
$revPar = $faturamentoAtual / ($qtdQuartos * $diferencaDias);
$ticketMedio = $locacoesAtual > 0 ? $faturamentoAtual / $locacoesAtual : 0;
$giroDiario = $locacoesAtual > 0 ? $locacoesAtual / ($qtdQuartos * $diferencaDias) : 0;

// Gráfico: Tipos de Quartos
$sqlTipos = "SELECT q.tipoquarto, COUNT(r.idlocacao) as qtd 
             FROM registralocado r 
             JOIN quartos q ON r.numquarto = q.numeroquarto 
             JOIN caixa c ON r.idcaixaatual = c.id 
             WHERE c.horaabre >= '$dataInicio' AND c.horaabre <= '$dataFim' 
             GROUP BY q.tipoquarto";
$resTipos = $conexao->query($sqlTipos);
$labelsTipos = [];
$dataTipos = [];
if($resTipos){
    while($row = $resTipos->fetch_assoc()){
        $labelsTipos[] = htmlspecialchars($row['tipoquarto']);
        $dataTipos[] = (int)$row['qtd'];
    }
}

// Gráfico: Ranking Quartos (Receita)
$sqlRanking = "SELECT r.numquarto, SUM(r.valorquarto + COALESCE(r.valorconsumo,0)) as receita 
               FROM registralocado r 
               JOIN caixa c ON r.idcaixaatual = c.id 
               WHERE c.horaabre >= '$dataInicio' AND c.horaabre <= '$dataFim' 
               GROUP BY r.numquarto ORDER BY receita DESC";
$resRanking = $conexao->query($sqlRanking);
$labelsRanking = [];
$dataRanking = [];
if($resRanking){
    while($row = $resRanking->fetch_assoc()){
        $labelsRanking[] = "Quarto " . $row['numquarto'];
        $dataRanking[] = (float)$row['receita'];
    }
}

// Gráfico: Heatmap Horários
$sqlHorarios = "SELECT HOUR(r.horainicio) as hora, COUNT(r.idlocacao) as qtd 
                FROM registralocado r 
                JOIN caixa c ON r.idcaixaatual = c.id 
                WHERE c.horaabre >= '$dataInicio' AND c.horaabre <= '$dataFim' 
                GROUP BY HOUR(r.horainicio)";
$resHorarios = $conexao->query($sqlHorarios);
$horasCompletas = array_fill(0, 24, 0);
if($resHorarios){
    while($row = $resHorarios->fetch_assoc()){
        if($row['hora'] !== null) {
            $horasCompletas[(int)$row['hora']] = (int)$row['qtd'];
        }
    }
}
$labelsHorarios = array_keys($horasCompletas);
$dataHorarios = array_values($horasCompletas);

// Finais de Semana vs Dias Uteis
$sqlWeek = "SELECT 
              SUM(CASE WHEN (DAYOFWEEK(r.horainicio) = 1) OR (DAYOFWEEK(r.horainicio) = 7) OR (DAYOFWEEK(r.horainicio) = 6 AND HOUR(r.horainicio) >= 18) THEN 1 ELSE 0 END) as weekend_qtd,
              SUM(CASE WHEN NOT ((DAYOFWEEK(r.horainicio) = 1) OR (DAYOFWEEK(r.horainicio) = 7) OR (DAYOFWEEK(r.horainicio) = 6 AND HOUR(r.horainicio) >= 18)) THEN 1 ELSE 0 END) as weekday_qtd
            FROM registralocado r 
            JOIN caixa c ON r.idcaixaatual = c.id 
            WHERE c.horaabre >= '$dataInicio' AND c.horaabre <= '$dataFim'";
$resWeek = $conexao->query($sqlWeek);
$weekData = ['weekend' => 0, 'weekday' => 0];
if($resWeek && $rowW = $resWeek->fetch_assoc()){
    $weekData['weekend'] = (int)$rowW['weekend_qtd'];
    $weekData['weekday'] = (int)$rowW['weekday_qtd'];
}

// Data de implantação / primeiro registro do sistema
$sqlFirst = "SELECT MIN(horaabre) as primeira_data FROM caixa";
$resFirst = $conexao->query($sqlFirst);
$dataImplantacao = null;
if ($resFirst && $rowFirst = $resFirst->fetch_assoc()) {
    $dataImplantacao = $rowFirst['primeira_data'];
}

// AI Insights
$insights = [];

// Validar integridade dos dados do período anterior
$temDadosHistoricos = true;
$dadosParciais = false;

if ($dataImplantacao) {
    if ($prevFim < $dataImplantacao) {
        $temDadosHistoricos = false;
    } elseif ($prevInicio < $dataImplantacao) {
        $dadosParciais = true; // O período anterior cruza com a época que o motel ainda não usava o sistema
    }
} else {
    $temDadosHistoricos = false;
}

if ($temDadosHistoricos) {
    if ($faturamentoPrev > 0){
        $crescimento = (($faturamentoAtual - $faturamentoPrev) / $faturamentoPrev) * 100;
        if($crescimento > 0){
            $insights[] = "O faturamento subiu " . number_format($crescimento, 1, ',', '.') . "% em relação ao período espelhado anterior.";
        } else {
             $insights[] = "O faturamento caiu " . number_format(abs($crescimento), 1, ',', '.') . "% em relação ao período espelhado anterior.";
        }
        
        if ($dadosParciais) {
            $insights[] = "⚠️ Atenção: A comparação com o período anterior pode estar distorcida. O intervalo base (" . date('d/m', strtotime($prevInicio)) . " a " . date('d/m/Y', strtotime($prevFim)) . ") cruza com a época em que seu motel ainda estava implementando o sistema (" . date('d/m/Y', strtotime($dataImplantacao)) . ").";
        }
    } else {
         if ($dadosParciais && $faturamentoAtual > 0) {
             $insights[] = "O período anterior de comparação possui dados parciais ou não faturou, impossibilitando calcular um crescimento justificado.";
         } else {
             $insights[] = "Ainda não possuímos massa de dados no período anterior (" . date('d/m/Y', strtotime($prevInicio)) . " a " . date('d/m/Y', strtotime($prevFim)) . ") para comparar o tamanho do seu faturamento.";
         }
    }
} else {
    $dataImpFormatada = $dataImplantacao ? date('d/m/Y', strtotime($dataImplantacao)) : 'uma data posterior';
    $insights[] = "A implantação do sistema no seu Motel ocorreu em " . $dataImpFormatada . ". O período anterior utilizado para comparação (" . date('d/m/Y', strtotime($prevInicio)) . " a " . date('d/m/Y', strtotime($prevFim)) . ") ocorreu antes do uso do software. Desconsideraremos cálculos comparativos de receita neste caso.";
}

if ($giroDiario >= 2.0 && $locacoesAtual > 0){
    $insights[] = "🚀 Excelente giro diário (" . number_format($giroDiario, 2, ',', '.') . " locações/quarto). A operação está performando com fluxo agressivo!";
} else if ($giroDiario < 1.0) {
    if ($giroDiario > 0 && !$dadosParciais && $diferencaDias >= 7) {
       $insights[] = "📉 Indicador de Alerta: O giro diário está abaixo do ideal da indústria hoteleira (Média menor que 1 locação por quarto). Verifique como atrair mais clientes!";
    }
}


$conexao->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Performance - Motel Inteligente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    <style>
        body { background-color: #f8f9fa; margin-bottom: 80px; }
        .main-container { max-width: 1200px; margin-top: 20px; }
        .card { margin-bottom: 15px; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: none; }
        .kpi-card { background-color: #fff; padding: 20px; border-radius: 8px; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; }
        .kpi-value { font-size: 2rem; font-weight: bold; color: #0d6efd; margin-bottom: 0; }
        .kpi-label { font-size: 0.9rem; color: #6c757d; font-weight: 600; text-transform: uppercase; }
        .chart-container { position: relative; height: 300px; width: 100%; }
        .ai-insight { background-color: #e2e3e5; border-left: 4px solid #0d6efd; padding: 15px; margin-bottom: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container main-container">
        <h3 class="text-center mb-4 text-primary">Dashboard de Performance</h3>

        <!-- Card Filtros -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="post" action="performance.php" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="period" class="form-label fw-bold">Selecione o Período</label>
                        <select name="period" id="period" class="form-select">
                            <option value="7" <?= ($selectedPeriod == '7' ? 'selected' : '') ?>>Últimos 7 dias</option>
                            <option value="15" <?= ($selectedPeriod == '15' ? 'selected' : '') ?>>Últimos 15 dias</option>
                            <option value="30" <?= ($selectedPeriod == '30' ? 'selected' : '') ?>>Últimos 30 dias</option>
                            <option value="this_month" <?= ($selectedPeriod == 'this_month' ? 'selected' : '') ?>>Este Mês</option>
                            <option value="last_month" <?= ($selectedPeriod == 'last_month' ? 'selected' : '') ?>>Mês Passado</option>
                            <option value="custom" <?= ($selectedPeriod == 'custom' ? 'selected' : '') ?>>Definir um Período</option>
                        </select>
                    </div>
                    <div id="custom_period" class="col-md-6 row g-2" style="display: <?= ($selectedPeriod == 'custom' ? 'flex' : 'none') ?>;">
                        <div class="col-sm-6">
                            <label for="data_inicio" class="form-label fw-bold">Início</label>
                            <input type="text" id="data_inicio" name="data_inicio" class="form-control" value="<?= htmlspecialchars($_POST['data_inicio'] ?? '') ?>" placeholder="dd/mm/aaaa">
                        </div>
                        <div class="col-sm-6">
                            <label for="data_fim" class="form-label fw-bold">Fim</label>
                            <input type="text" id="data_fim" name="data_fim" class="form-control" value="<?= htmlspecialchars($_POST['data_fim'] ?? '') ?>" placeholder="dd/mm/aaaa">
                        </div>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">Aplicar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- KPIs -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm kpi-card">
                    <p class="kpi-label">RevPAR</p>
                    <p class="kpi-value">R$ <?= number_format($revPar, 2, ',', '.') ?></p>
                    <small class="text-muted">Receita por quarto disponível</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm kpi-card">
                    <p class="kpi-label">Ticket Médio</p>
                    <p class="kpi-value">R$ <?= number_format($ticketMedio, 2, ',', '.') ?></p>
                    <small class="text-muted">Receita média por locação</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm kpi-card">
                    <p class="kpi-label">Giro Diário</p>
                    <p class="kpi-value"><?= number_format($giroDiario, 2, ',', '.') ?></p>
                    <small class="text-muted">Locações por quarto ao dia</small>
                </div>
            </div>
        </div>

        <!-- Ocupação Semana vs Fds -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title fw-bold text-secondary">Locações: Dias Úteis vs Finais de Semana</h5>
                        <p class="text-muted mb-0">Total: <?= $locacoesAtual ?> locações no período.</p>
                        <div class="progress mt-3" style="height: 25px; font-size: 1rem; font-weight: bold;">
                            <?php 
                                $percWeek = $locacoesAtual > 0 ? ($weekData['weekday'] / $locacoesAtual) * 100 : 0;
                                $percWkend = $locacoesAtual > 0 ? ($weekData['weekend'] / $locacoesAtual) * 100 : 0;
                            ?>
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $percWeek ?>%">Dias Úteis (<?= $weekData['weekday'] ?>)</div>
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $percWkend ?>%">Finais de Semana (<?= $weekData['weekend'] ?>)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="row mb-4">
            <div class="col-md-8 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">Horário de Pico (Entradas)</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartHorarios"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">Mix de Categoria</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartMix"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ranking & Insights -->
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">Ranking de Faturamento por Quarto</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartRanking"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">Analista de Gestão (IA)</div>
                    <div class="card-body">
                        <?php foreach($insights as $insight): ?>
                            <div class="ai-insight">
                                <?= htmlspecialchars($insight) ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($insights)): ?>
                            <div class="ai-insight">Aguardando mais dados para gerar análises.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <?php include 'menu.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Filtro customizado behavior
            $("#period").change(function () {
                if ($(this).val() === 'custom') {
                    $("#custom_period").slideDown();
                } else {
                    $("#custom_period").slideUp();
                }
            });

            $("#data_inicio, #data_fim").datepicker({
                dateFormat: 'dd/mm/yy',
                dayNames: ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'],
                dayNamesMin: ['D','S','T','Q','Q','S','S'],
                monthNames: ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
                monthNamesShort: ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'],
            });
            
            // Gráficos com Chart.js
            const ctxHorarios = document.getElementById('chartHorarios').getContext('2d');
            new Chart(ctxHorarios, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($labelsHorarios) ?>.map(h => h + 'h'),
                    datasets: [{
                        label: 'Número de Entradas',
                        data: <?= json_encode($dataHorarios) ?>,
                        backgroundColor: '#0d6efd',
                        borderRadius: 4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            const ctxMix = document.getElementById('chartMix').getContext('2d');
            new Chart(ctxMix, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($labelsTipos) ?>,
                    datasets: [{
                        data: <?= json_encode($dataTipos) ?>,
                        backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1'],
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            const ctxRanking = document.getElementById('chartRanking').getContext('2d');
            new Chart(ctxRanking, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($labelsRanking) ?>,
                    datasets: [{
                        label: 'Receita (R$)',
                        data: <?= json_encode($dataRanking) ?>,
                        backgroundColor: '#198754',
                        borderRadius: 4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    indexAxis: 'y'
                }
            });

        });
    </script>
</body>
</html>
