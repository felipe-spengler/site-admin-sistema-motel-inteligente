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

// ====== PARÂMETROS DO NEGÓCIO E ATUALIZAÇÃO ======
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_gestao"])) {
    $tipoAluguel = $_POST['tipo_aluguel'];
    $valorAluguel = (float)str_replace(',', '.', $_POST['valor_aluguel']);
    $custoLimpeza = (float)str_replace(',', '.', $_POST['custo_limpeza']);
    $despesasFixas = (float)str_replace(',', '.', $_POST['despesas_fixas']);
    
    $upSQL = "UPDATE dados_gestao SET tipo_aluguel = ?, valor_aluguel = ?, custo_variavel_limpeza = ?, despesas_fixas_mensais = ? WHERE id = 1";
    $stmt = $conexao->prepare($upSQL);
    if($stmt) {
        $stmt->bind_param("sddd", $tipoAluguel, $valorAluguel, $custoLimpeza, $despesasFixas);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            $conexao->query("INSERT IGNORE INTO dados_gestao (id, tipo_aluguel, valor_aluguel, custo_variavel_limpeza, despesas_fixas_mensais) VALUES (1, '$tipoAluguel', $valorAluguel, $custoLimpeza, $despesasFixas)");
        }
        $stmt->close();
    }
}

$resGestao = $conexao->query("SELECT * FROM dados_gestao ORDER BY id LIMIT 1");
if ($resGestao && $resGestao->num_rows > 0) {
    $rowGestao = $resGestao->fetch_assoc();
} else {
    // Cria caso não exista
    $conexao->query("INSERT INTO dados_gestao (id, tipo_aluguel, valor_aluguel, custo_variavel_limpeza, despesas_fixas_mensais) VALUES (1, 'porcentagem', 20.00, 0.00, 10775.00)");
    $rowGestao = [
        'tipo_aluguel' => 'porcentagem',
        'valor_aluguel' => 20.00,
        'custo_variavel_limpeza' => 0.00,
        'despesas_fixas_mensais' => 10775.00
    ];
}

$tipoAluguel = $rowGestao['tipo_aluguel'];
$valorAluguel = (float)$rowGestao['valor_aluguel'];
$custoLimpeza = (float)$rowGestao['custo_variavel_limpeza'];
$despesasFixas = (float)$rowGestao['despesas_fixas_mensais'];
// ==================================================

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
$locacoesPrev = $prevData['locacoes'];

// --- CÁLCULO DE LUCRO REAL ---
$despesasProrateadas = ($despesasFixas / 30) * $diferencaDias;
$custoOperacionalTotal = $locacoesAtual * $custoLimpeza;

$deducaoAluguel = 0;
if ($tipoAluguel == 'porcentagem') {
    $deducaoAluguel = $faturamentoAtual * ($valorAluguel / 100);
} else {
    $deducaoAluguel = ($valorAluguel / 30) * $diferencaDias;
}

$lucroLiquido = $faturamentoAtual - $deducaoAluguel - $custoOperacionalTotal - $despesasProrateadas;
// -----------------------------

// KPIs
$qtdQuartos = 9; // Conforme requisito
$revPar = $faturamentoAtual / ($qtdQuartos * $diferencaDias);
$ticketMedio = $locacoesAtual > 0 ? $faturamentoAtual / $locacoesAtual : 0;
$giroDiario = $locacoesAtual > 0 ? $locacoesAtual / ($qtdQuartos * $diferencaDias) : 0;

$ticketMedioPrev = $locacoesPrev > 0 ? $faturamentoPrev / $locacoesPrev : 0;
$crescimentoTicket = 0;
if ($ticketMedioPrev > 0) {
    $crescimentoTicket = (($ticketMedio - $ticketMedioPrev) / $ticketMedioPrev) * 100;
}

// Assumindo 4 locações ao longo de 24h como 100% de ocupação ótima para um Motel
$taxaOcupacao = min(100, ($giroDiario / 4) * 100); 

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
// Regras de negócio de Gestão (Sócio Virtual)
if ($faturamentoAtual < ($despesasProrateadas + $deducaoAluguel) && $faturamentoAtual > 0) {
    $falta = ($despesasProrateadas + $deducaoAluguel) - $faturamentoAtual;
    $insights[] = "📉 Faltam R$ " . number_format($falta, 2, ',', '.') . " para você cobrir seus custos fixos este mês. A partir daí, entramos na zona de lucro real.";
}

if ($faturamentoAtual > 0) {
    $margem = ($lucroLiquido / $faturamentoAtual) * 100;
    if ($margem > 0 && $margem < 30) {
         $insights[] = "⚠️ <b>Margem Apertada:</b> Seu custo operacional está alto. Como sua lavanderia é interna, considere revisar o desperdício de produtos ou ajustar o valor da hora adicional.";
    }
}

if ($tipoAluguel == 'porcentagem') {
    $insights[] = "📈 <b>Dica de Sócia:</b> Como seu aluguel é sobre o faturamento, foque em aumentar as vendas de Frigobar e Cozinha. Geralmente esses itens têm margem maior e ajudam a diluir o custo do aluguel sobre a locação.";
}

if ($faturamentoPrev > 0 && $locacoesPrev > 0) {
    if ($faturamentoAtual < $faturamentoPrev && $locacoesAtual > $locacoesPrev) {
        $insights[] = "💡 <b>Meta de Ticket Médio:</b> Você está trabalhando mais por menos. Seu Ticket Médio caiu. Sugiro aumentar o valor das Suítes com Hidro em 5% para compensar o desgaste.";
    }
}

// Integração IA GEMINI
$geminiApiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');
$prompt = "Atue como um consultor sênior de gestão hoteleira e motéis. Analise os seguintes dados atuais do dashboard:\n" .
          "Faturamento: R$ " . number_format($faturamentoAtual, 2, ',', '.') . "\n" .
          "Ticket Médio: R$ " . number_format($ticketMedio, 2, ',', '.') . "\n" .
          "Giro Diário: " . number_format($giroDiario, 2, ',', '.') . "\n" .
          "Período: " . date('d/m/Y', strtotime($dataInicio)) . " a " . date('d/m/Y', strtotime($dataFim)) . "\n\n" .
          "Sua tarefa é gerar uma 'Dica de Ouro' curta (máximo 3 frases).\n" .
          "Se o giro estiver baixo, sugira uma estratégia de marketing ou promoção.\n" .
          "Se o ticket médio estiver abaixo de R$ 90,00, sugira upselling de produtos de consumo (frigobar/cozinha).\n" .
          "Use um tom profissional e motivador. Retorne apenas o texto da dica.";

$geminiData = json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]);
$ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $geminiApiKey);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $geminiData);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 6); 
$geminiResponse = curl_exec($ch);
curl_close($ch);

if ($geminiResponse) {
    $geminiJson = json_decode($geminiResponse, true);
    if (isset($geminiJson['candidates'][0]['content']['parts'][0]['text'])) {
         $insights[] = "🧠 <b>Consultoria IA Avançada:</b><br>" . nl2br(trim($geminiJson['candidates'][0]['content']['parts'][0]['text']));
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
    <script src="https://cdn.tailwindcss.com"></script>
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
        
        <!-- Cabeçalho (WhatsApp Export e Período) -->
        <div class="d-flex justify-content-between align-items-center mb-4 mt-2">
            <div>
                <h3 class="mb-1 text-primary fw-bold">Dashboard de Lucratividade</h3>
                <p class="text-muted mb-0">Exibindo dados de <?= date('d/m/Y', strtotime($dataInicio)) ?> a <?= date('d/m/Y', strtotime($dataFim)) ?></p>
            </div>
            <div>
                <button onclick="exportToWhats()" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded shadow-sm inline-flex items-center transition duration-300">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.86s.274.072.376-.043c.101-.116.433-.506.549-.68.116-.173.231-.145.39-.087s1.011.477 1.184.564.289.13.332.202c.045.072.045.419-.098.824z"/></svg>
                    Resumo p/ Whats
                </button>
            </div>
        </div>

        <!-- Lucro Líquido Dash -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="bg-emerald-500 text-white rounded-xl shadow-lg p-6 text-center transform transition duration-500 hover:scale-105">
                    <p class="text-xl font-bold uppercase tracking-widest opacity-90 mb-2">💰 Lucro Líquido Estimado</p>
                    <p class="text-5xl font-extrabold mb-2">R$ <?= number_format($lucroLiquido, 2, ',', '.') ?></p>
                    <p class="text-sm font-medium opacity-85">Receita livre após abater Aluguel, Operação e Despesas Fixas desse período.</p>
                </div>
            </div>
        </div>

        <!-- Card Filtros & Config de Gestão -->
        <div class="card shadow-sm mb-4 border-t-4 border-blue-500">
            <div class="card-body">
                <form method="post" action="performance.php" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="period" class="form-label font-bold text-gray-700">📅 Selecione o Período</label>
                        <select name="period" id="period" class="form-select border-2 border-gray-200">
                            <option value="this_month" <?= ($selectedPeriod == 'this_month' ? 'selected' : '') ?>>Este Mês</option>
                            <option value="last_month" <?= ($selectedPeriod == 'last_month' ? 'selected' : '') ?>>Mês Passado</option>
                            <option value="this_quarter" <?= ($selectedPeriod == 'this_quarter' ? 'selected' : '') ?>>Trimestre Atual</option>
                            <option value="this_semester" <?= ($selectedPeriod == 'this_semester' ? 'selected' : '') ?>>Semestre Atual</option>
                            <option value="this_year" <?= ($selectedPeriod == 'this_year' ? 'selected' : '') ?>>Este Ano</option>
                            <option value="last_year" <?= ($selectedPeriod == 'last_year' ? 'selected' : '') ?>>Ano Passado</option>
                            <option value="custom" <?= ($selectedPeriod == 'custom' ? 'selected' : '') ?>>Definir um Período</option>
                        </select>
                    </div>
                    <div id="custom_period" class="col-md-7 row g-2" style="display: <?= ($selectedPeriod == 'custom' ? 'flex' : 'none') ?>;">
                        <div class="col-sm-6">
                            <label for="data_inicio" class="form-label font-bold text-gray-700">Início</label>
                            <input type="text" id="data_inicio" name="data_inicio" class="form-control" value="<?= htmlspecialchars($_POST['data_inicio'] ?? '') ?>" placeholder="dd/mm/aaaa">
                        </div>
                        <div class="col-sm-6">
                            <label for="data_fim" class="form-label font-bold text-gray-700">Fim</label>
                            <input type="text" id="data_fim" name="data_fim" class="form-control" value="<?= htmlspecialchars($_POST['data_fim'] ?? '') ?>" placeholder="dd/mm/aaaa">
                        </div>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded shadow transition-all duration-300">Consultar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="mb-4">
            <button class="w-full bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold py-2 px-4 border border-gray-300 rounded shadow-sm flex items-center justify-center transition-colors" type="button" data-bs-toggle="modal" data-bs-target="#modalSettings">
                ⚙️ Ajustar Parâmetros Financeiros (Custo Fixo e Variável)
                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            </button>
        </div>

        <!-- Modal -->
        <div class="modal fade" id="modalSettings" tabindex="-1" aria-labelledby="modalSettingsLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg">
              <div class="modal-header bg-slate-100 text-gray-800 border-b border-gray-200">
                <h5 class="modal-title font-bold" id="modalSettingsLabel">⚙️ Parâmetros Financeiros</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body bg-slate-50 p-4">
                <form method="post" action="performance.php" class="row g-3">
                    <input type="hidden" name="period" value="<?= htmlspecialchars($selectedPeriod) ?>">
                    <input type="hidden" name="update_gestao" value="1">
                    
                    <div class="col-md-6 mb-2">
                        <label class="form-label font-bold text-gray-700">🏠 Modelo de Aluguel</label>
                        <select name="tipo_aluguel" class="form-select border-indigo-200 shadow-sm">
                            <option value="porcentagem" <?= $tipoAluguel == 'porcentagem' ? 'selected' : '' ?>>Porcentagem do Faturamento (%)</option>
                            <option value="fixo" <?= $tipoAluguel == 'fixo' ? 'selected' : '' ?>>Valor Fixo Mensal (R$)</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label font-bold text-gray-700">Valor do Aluguel (% ou R$)</label>
                        <input type="text" name="valor_aluguel" class="form-control border-indigo-200 shadow-sm" value="<?= number_format($valorAluguel, 2, ',', '') ?>">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label font-bold text-gray-700">🧼 Limpeza por Locação (R$)</label>
                        <input type="text" name="custo_limpeza" class="form-control border-indigo-200 shadow-sm" value="<?= number_format($custoLimpeza, 2, ',', '') ?>" placeholder="Custo lavanderia/insumo">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label font-bold text-gray-700">Gasto Fixo Mensal (R$)</label>
                        <input type="text" name="despesas_fixas" class="form-control border-indigo-200 shadow-sm" value="<?= number_format($despesasFixas, 2, ',', '') ?>" placeholder="Soma de luz, net, holerites...">
                    </div>
                    <div class="col-12 mt-4 text-end">
                        <button type="button" class="btn btn-secondary bg-gray-500 hover:bg-gray-600 text-white font-semibold py-2 px-4 rounded shadow me-2 border-0" data-bs-dismiss="modal">Fechar</button>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 border-0 text-white font-semibold py-2 px-6 rounded shadow transition duration-200">
                            Gravar Parâmetros
                        </button>
                    </div>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- KPIs 4 Colunas -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm kpi-card">
                    <p class="kpi-label">RevPAR</p>
                    <p class="kpi-value">R$ <?= number_format($revPar, 2, ',', '.') ?></p>
                    <small class="text-muted">Receita por quarto</small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm kpi-card position-relative">
                    <p class="kpi-label">Ticket Médio</p>
                    <p class="kpi-value">R$ <?= number_format($ticketMedio, 2, ',', '.') ?></p>
                    <?php if($crescimentoTicket != 0): ?>
                        <span class="position-absolute top-0 end-0 mt-3 me-3 inline-flex items-center rounded-md px-2 py-1 text-xs font-bold <?= $crescimentoTicket > 0 ? 'bg-green-100 text-green-700 ring-1 ring-inset ring-green-600/20' : 'bg-red-100 text-red-700 ring-1 ring-inset ring-red-600/10' ?>">
                            <?= $crescimentoTicket > 0 ? '▲' : '▼' ?> <?= number_format(abs($crescimentoTicket), 1, ',', '.') ?>% MoM
                        </span>
                    <?php endif; ?>
                    <small class="text-muted">Receita média por loc.</small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm kpi-card">
                    <p class="kpi-label">Giro Diário</p>
                    <p class="kpi-value"><?= number_format($giroDiario, 2, ',', '.') ?></p>
                    <small class="text-muted">Locações/quarto/dia</small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm kpi-card">
                    <p class="kpi-label">Taxa de Ocupação</p>
                    <p class="kpi-value"><?= number_format($taxaOcupacao, 1, ',', '.') ?>%</p>
                    <small class="text-muted">Ref: Máx. de 4 Giros Dia</small>
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

        <!-- Ranking, Destino e Insights -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100 border-t-4 border-green-500">
                    <div class="card-header bg-white fw-bold text-gray-700">📊 Destino do seu Faturamento</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartDestino"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100 border-t-4 border-yellow-500">
                    <div class="card-header bg-white fw-bold text-gray-700">🏆 Faturamento por Quarto</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartRanking"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100 border-t-4 border-purple-500">
                    <div class="card-header bg-white fw-bold text-gray-700">🧠 Sócio de Gestão Virtual</div>
                    <div class="card-body overflow-y-auto" style="max-height: 380px;">
                        <?php foreach($insights as $insight): ?>
                            <div class="ai-insight bg-purple-50/50 border-l-4 border-purple-500 p-3 mb-3 rounded shadow-sm text-sm text-gray-800 leading-relaxed">
                                <?= $insight ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($insights)): ?>
                            <div class="ai-insight">Aguardando geração de diagnósticos...</div>
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
                        backgroundColor: '#10b981',
                        borderRadius: 4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    indexAxis: 'y'
                }
            });

            const ctxDestino = document.getElementById('chartDestino').getContext('2d');
            new Chart(ctxDestino, {
                type: 'doughnut',
                data: {
                    labels: ['Aluguel (<?= $tipoAluguel == 'porcentagem' ? number_format($valorAluguel,0).'% da fatia' : 'Fixo prorateado' ?>)', 'Custos Insumos', 'Despesas Fixas', 'Lucro Líquido'],
                    datasets: [{
                        data: [
                            <?= json_encode(max(0, $deducaoAluguel)) ?>, 
                            <?= json_encode(max(0, $custoOperacionalTotal)) ?>, 
                            <?= json_encode(max(0, $despesasProrateadas)) ?>, 
                            <?= json_encode(max(0, $lucroLiquido)) ?>
                        ],
                        backgroundColor: ['#f59e0b', '#3b82f6', '#ef4444', '#10b981'],
                        borderWidth: 0,
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });


        });
        
        // WhatsApp Export
        function exportToWhats() {
            const faturamento = "R$ <?= number_format($faturamentoAtual, 2, ',', '.') ?>";
            const lucro = "R$ <?= number_format($lucroLiquido, 2, ',', '.') ?>";
            const ticket = "R$ <?= number_format($ticketMedio, 2, ',', '.') ?>";
            const giro = "<?= number_format($giroDiario, 2, ',', '.') ?>";
            const ocupacao = "<?= number_format($taxaOcupacao, 1, ',', '.') ?>%";
            const periodo = "<?= date('d/m/Y', strtotime($dataInicio)) ?> a <?= date('d/m/Y', strtotime($dataFim)) ?>";
            
            let texto = `*📊 Relatório Motel Inteligente*\n`;
            texto += `Período: ${periodo}\n\n`;
            texto += `*Faturamento Bruto:* ${faturamento}\n`;
            texto += `*Lucro Líquido Real (est.):* ${lucro}\n`;
            texto += `*Ticket Médio:* ${ticket}\n`;
            texto += `*Giro Diário:* ${giro} loc/dia\n`;
            texto += `*Ocupação Média:* ${ocupacao}\n\n`;
            texto += `_Painel de Gestão Estratégica_`;
            
            const url = `https://api.whatsapp.com/send?text=${encodeURIComponent(texto)}`;
            window.open(url, '_blank');
        }
    </script>
</body>
</html>
