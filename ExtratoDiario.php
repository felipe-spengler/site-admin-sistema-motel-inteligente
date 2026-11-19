<?php
date_default_timezone_set('America/Sao_Paulo');

// Função para formatar a data no formato MySQL (Y-m-d H:i:s)
function formatarData($data, $horaInicial = true) {
    $partes = explode('/', $data);
    $dia = $partes[0];
    $mes = $partes[1];
    $ano = $partes[2];
    $hora = $horaInicial ? '00:00:00' : '23:59:59';
    return sprintf('%04d-%02d-%02d %s', $ano, $mes, $dia, $hora);
}

function obterIdCaixa($conexao, $dataInicio, $dataFim) {
    $sql = "SELECT id FROM caixa WHERE horaabre >= '$dataInicio' AND horaabre <= '$dataFim' ORDER BY horaabre DESC";
    $result = $conexao->query($sql);

    if ($result) {
        $idCaixas = array();
        while ($row = $result->fetch_assoc()) {
            $idCaixas[] = (int)$row['id'];
        }
        return $idCaixas;
    } else {
        return [];
    }
}

function calcularValorELocacoes($conexao, $idCaixas) {
    $resultados = array();

    foreach ($idCaixas as $idCaixa) {
        $sqlValor = "SELECT * FROM caixa WHERE id = $idCaixa";
        $resultValor = $conexao->query($sqlValor);
        
        if ($resultValor && $resultValor->num_rows > 0) {
            $rowValor = $resultValor->fetch_assoc();
            $horaAbertura = $rowValor['horaabre'];
            $horaFechamento = $rowValor['horafecha'];
            $saldoAbre = (float)($rowValor['saldoabre'] ?? 0);
            $saldoFechaBanco = $rowValor['saldofecha'] !== null ? (float)$rowValor['saldofecha'] : null;
            
            $dataAbre = date("d/m/y", strtotime($horaAbertura));
            $horaAbreFmt = $horaAbertura ? date("d/m/Y H:i", strtotime($horaAbertura)) : 'N/A';
            $horaFechaFmt = $horaFechamento ? date("d/m/Y H:i", strtotime($horaFechamento)) : null;

            // 1. Locações e pagamentos das suítes
            $sqlLocacoes = "
                SELECT 
                    COUNT(*) AS total,
                    COALESCE(SUM(r.pagodinheiro), 0) AS somaDinheiro,
                    COALESCE(SUM(r.pagopix), 0) AS somaPix,
                    COALESCE(SUM(r.pagocartao), 0) AS somaCartao,
                    COALESCE(SUM(r.valorquarto), 0) AS somaQuarto,
                    COALESCE(SUM(r.valorconsumo), 0) AS somaConsumoSuites,
                    (SELECT COALESCE(SUM(vc.valorcredito), 0)
                     FROM valorcartao vc
                     JOIN registralocado r2 ON vc.idlocacao = r2.idlocacao
                     WHERE r2.idcaixaatual = $idCaixa) AS somaCreditoLoc,
                    (SELECT COALESCE(SUM(vc.valordebito), 0)
                     FROM valorcartao vc
                     JOIN registralocado r2 ON vc.idlocacao = r2.idlocacao
                     WHERE r2.idcaixaatual = $idCaixa) AS somaDebitoLoc
                FROM registralocado r
                WHERE r.idcaixaatual = $idCaixa
            ";
            $resultLocacoes = $conexao->query($sqlLocacoes);
            
            $totalLocacoes = 0;
            $dinheiroLoc = 0.0;
            $pixLoc = 0.0;
            $cartaoLoc = 0.0;
            $creditoLoc = 0.0;
            $debitoLoc = 0.0;
            $valorQuartos = 0.0;
            $valorConsumoSuites = 0.0;

            if ($resultLocacoes && $rowLoc = $resultLocacoes->fetch_assoc()) {
                $totalLocacoes = (int)$rowLoc['total'];
                $dinheiroLoc = (float)$rowLoc['somaDinheiro'];
                $pixLoc = (float)$rowLoc['somaPix'];
                $cartaoLoc = (float)$rowLoc['somaCartao'];
                $creditoLoc = (float)$rowLoc['somaCreditoLoc'];
                $debitoLoc = (float)$rowLoc['somaDebitoLoc'];
                $valorQuartos = (float)$rowLoc['somaQuarto'];
                $valorConsumoSuites = (float)$rowLoc['somaConsumoSuites'];

                // Caso haja diferença de cartão sem registro detalhado em valorcartao, aloca em crédito
                $difCartao = $cartaoLoc - ($creditoLoc + $debitoLoc);
                if ($difCartao > 0.009) {
                    $creditoLoc += $difCartao;
                }
            }

            // 2. Descontos e Acréscimos (tabela justificativa)
            $descontos = 0.0;
            $acrescimos = 0.0;
            $sqlJust = "
                SELECT j.tipo, COALESCE(SUM(j.valor), 0) AS total
                FROM justificativa j
                JOIN registralocado r ON j.idlocacao = r.idlocacao
                WHERE r.idcaixaatual = $idCaixa
                GROUP BY j.tipo
            ";
            $resJust = $conexao->query($sqlJust);
            if ($resJust) {
                while ($rowJ = $resJust->fetch_assoc()) {
                    if ($rowJ['tipo'] === 'desconto') {
                        $descontos += (float)$rowJ['total'];
                    } elseif ($rowJ['tipo'] === 'acrescimo') {
                        $acrescimos += (float)$rowJ['total'];
                    }
                }
            }

            // 3. Vendas Avulsas e Adiantamentos
            $avulsasTotal = 0.0;
            $avulsasDinheiro = 0.0;
            $avulsasPix = 0.0;
            $avulsasCredito = 0.0;
            $avulsasDebito = 0.0;
            $avulsasCartao = 0.0;
            $adiantamentosTotal = 0.0;

            $sqlAvulsas = "
                SELECT tipo, formapagamento, COALESCE(SUM(valortotal), 0) AS total
                FROM vendas_avulsas
                WHERE idcaixa = $idCaixa
                GROUP BY tipo, formapagamento
            ";
            $resAvulsas = $conexao->query($sqlAvulsas);
            if ($resAvulsas) {
                while ($rowAv = $resAvulsas->fetch_assoc()) {
                    $val = (float)$rowAv['total'];
                    $tipo = strtolower(trim($rowAv['tipo'] ?? ''));
                    $pgto = strtolower(trim($rowAv['formapagamento'] ?? ''));

                    if ($tipo === 'adiantamento') {
                        $adiantamentosTotal += $val;
                    } else {
                        $avulsasTotal += $val;
                        if ($pgto === 'dinheiro') {
                            $avulsasDinheiro += $val;
                        } elseif ($pgto === 'pix') {
                            $avulsasPix += $val;
                        } elseif ($pgto === 'debito') {
                            $avulsasDebito += $val;
                            $avulsasCartao += $val;
                        } else {
                            $avulsasCredito += $val;
                            $avulsasCartao += $val;
                        }
                    }
                }
            }

            // 4. Retiradas (Sangrias) e Despesas lançadas neste caixa
            $retiradasTotal = 0.0;
            $resRet = @$conexao->query("SELECT COALESCE(SUM(valor), 0) AS total FROM retiradas_caixa WHERE idcaixa = $idCaixa");
            if ($resRet && $rowRet = $resRet->fetch_assoc()) {
                $retiradasTotal = (float)$rowRet['total'];
            }

            $despesasCaixaTotal = 0.0;
            $resDesp = @$conexao->query("SELECT COALESCE(SUM(valor), 0) AS total FROM despesas WHERE idcaixa = $idCaixa AND status = 'pago'");
            if ($resDesp && $rowDesp = $resDesp->fetch_assoc()) {
                $despesasCaixaTotal = (float)$rowDesp['total'];
            }

            // Totais consolidados por meio de pagamento
            $totalDinheiro = $dinheiroLoc + $avulsasDinheiro;
            $totalPix = $pixLoc + $avulsasPix;
            $totalCredito = $creditoLoc + $avulsasCredito;
            $totalDebito = $debitoLoc + $avulsasDebito;
            $totalCartao = $cartaoLoc + $avulsasCartao;

            $valorFechamento = $totalDinheiro + $totalPix + $totalCartao;
            $ticketMedio = $totalLocacoes > 0 ? ($valorFechamento / $totalLocacoes) : 0.0;

            $resultados[] = array(
                'id_caixa' => $idCaixa,
                'valor_fechamento' => (float)$valorFechamento,
                'total_locacoes' => (int)$totalLocacoes,
                'data' => $dataAbre,
                'hora_abre' => $horaAbertura,
                'hora_fecha' => $horaFechamento,
                'hora_abre_fmt' => $horaAbreFmt,
                'hora_fecha_fmt' => $horaFechaFmt,
                'usuario_abre' => $rowValor['usuarioabre'] ?? '',
                'usuario_fecha' => $rowValor['usuariofecha'] ?? '',
                'saldo_abre' => $saldoAbre,
                'saldo_fecha_banco' => $saldoFechaBanco,
                'dinheiro' => $totalDinheiro,
                'pix' => $totalPix,
                'credito' => $totalCredito,
                'debito' => $totalDebito,
                'cartao_total' => $totalCartao,
                'valor_quartos' => $valorQuartos,
                'valor_consumo_suites' => $valorConsumoSuites,
                'valor_vendas_avulsas' => $avulsasTotal,
                'valor_consumo_total' => ($valorConsumoSuites + $avulsasTotal),
                'descontos' => $descontos,
                'acrescimos' => $acrescimos,
                'retiradas' => $retiradasTotal,
                'despesas_caixa' => $despesasCaixaTotal,
                'adiantamentos' => $adiantamentosTotal,
                'ticket_medio' => $ticketMedio
            );
        }
    }

    return $resultados;
}

function carregarLocacoes($conexao, $idCaixas) {
    $locacoes = [];
    foreach ($idCaixas as $idCaixa) {
        $locacoes[$idCaixa] = [];
        
        $consultaSQL = "SELECT rl.*, j.valor AS valor_justificativa, j.tipo 
                        FROM registralocado rl 
                        LEFT JOIN justificativa j ON rl.idlocacao = j.idlocacao 
                        WHERE rl.idcaixaatual = ? ORDER BY rl.horainicio";

        $statement = $conexao->prepare($consultaSQL);
        $statement->bind_param("i", $idCaixa);
        $statement->execute();
        $resultadoLocacoes = $statement->get_result();

        while ($row = $resultadoLocacoes->fetch_assoc()) {
            $acrescimo = $row['tipo'] === 'acrescimo' ? (float)$row['valor_justificativa'] : 0.00;
            $desconto = $row['tipo'] === 'desconto' ? (float)$row['valor_justificativa'] : 0.00;
            
            $valorQuarto = (float)$row['valorquarto'];
            $valorConsumo = (float)$row['valorconsumo'];
            $total = $valorQuarto + $valorConsumo + $acrescimo - $desconto;

            $locacoes[$idCaixa][] = [
                'horainicio' => $row['horainicio'],
                'horafim' => $row['horafim'],
                'numquarto' => "Quarto " . $row['numquarto'],
                'valorquarto' => $valorQuarto,
                'valorconsumo' => $valorConsumo,
                'desconto' => $desconto,
                'acrescimo' => $acrescimo,
                'total' => $total
            ];
        }
        $statement->close();
        
        // Carrega as vendas avulsas
        $sqlAvulsas = "SELECT horario, descricao, quantidade, valortotal, formapagamento, tipo 
                       FROM vendas_avulsas 
                       WHERE idcaixa = ? ORDER BY horario";
        $stmtAvulsa = $conexao->prepare($sqlAvulsas);
        $stmtAvulsa->bind_param("i", $idCaixa);
        $stmtAvulsa->execute();
        $resAvulsas = $stmtAvulsa->get_result();
        
        while ($row = $resAvulsas->fetch_assoc()) {
            $tipoStr = "";
            if ($row['tipo'] === 'adiantamento') {
                $tipoStr = " (Adiantamento)";
            } else if ($row['tipo'] === 'funcionario') {
                $tipoStr = " (Func)";
            }
            $pgtoBadge = !empty($row['formapagamento']) ? " [" . strtoupper($row['formapagamento']) . "]" : "";
            $locacoes[$idCaixa][] = [
                'horainicio' => $row['horario'],
                'horafim' => null,
                'numquarto' => "Venda Avulsa: " . $row['descricao'] . $tipoStr . $pgtoBadge,
                'valorquarto' => 0.00,
                'valorconsumo' => (float)$row['valortotal'],
                'desconto' => 0.00,
                'acrescimo' => 0.00,
                'total' => (float)$row['valortotal']
            ];
        }
        $stmtAvulsa->close();
    }
    return $locacoes;
}

function carregarProdutosVendidos($conexao, $idCaixas) {
    $produtosVendidos = [];
    foreach ($idCaixas as $idCaixa) {
        $produtosVendidos[$idCaixa] = [];
        
        // 1. Produtos vendidos nas locações (tabela registravendido)
        $sqlLocacoes = "SELECT p.descricao AS produto, rv.quantidade, rv.valorunidade, rv.valortotal, rl.numquarto
                        FROM registravendido rv
                        JOIN produtos p ON rv.idproduto = p.idproduto
                        LEFT JOIN registralocado rl ON rv.idlocacao = rl.idlocacao
                        WHERE rv.idcaixaatual = ? 
                        ORDER BY p.descricao";
        $stmt = $conexao->prepare($sqlLocacoes);
        $stmt->bind_param("i", $idCaixa);
        $stmt->execute();
        $resLocacoes = $stmt->get_result();
        while ($row = $resLocacoes->fetch_assoc()) {
            $produtosVendidos[$idCaixa][] = [
                'descricao' => $row['produto'],
                'quantidade' => (int)$row['quantidade'],
                'valor_unitario' => (float)$row['valorunidade'],
                'valor_total' => (float)$row['valortotal'],
                'origem' => $row['numquarto'] ? "Quarto " . $row['numquarto'] : "Locação"
            ];
        }
        $stmt->close();

        // 2. Vendas avulsas (tabela vendas_avulsas)
        $sqlAvulsas = "SELECT descricao, quantidade, valorunidade, valortotal, tipo 
                       FROM vendas_avulsas 
                       WHERE idcaixa = ? AND tipo != 'adiantamento'
                       ORDER BY id";
        $stmtAv = $conexao->prepare($sqlAvulsas);
        $stmtAv->bind_param("i", $idCaixa);
        $stmtAv->execute();
        $resAv = $stmtAv->get_result();
        while ($row = $resAv->fetch_assoc()) {
            $qtd = (int)$row['quantidade'];
            $valTot = (float)$row['valortotal'];
            $desc = $row['descricao'];
            $origem = "Venda Avulsa" . ($row['tipo'] === 'funcionario' ? " (Func)" : "");

            // Se qtd == 0 (fatia adicional de pagamento dividido do mesmo item), soma no item anterior de mesma descrição
            if ($qtd === 0 && !empty($produtosVendidos[$idCaixa])) {
                $lastIdx = count($produtosVendidos[$idCaixa]) - 1;
                if ($produtosVendidos[$idCaixa][$lastIdx]['descricao'] === $desc) {
                    $produtosVendidos[$idCaixa][$lastIdx]['valor_total'] += $valTot;
                    continue;
                }
            }

            if ($qtd <= 0) $qtd = 1;
            $valUnit = isset($row['valorunidade']) && (float)$row['valorunidade'] > 0 ? (float)$row['valorunidade'] : ($valTot / $qtd);

            $produtosVendidos[$idCaixa][] = [
                'descricao' => $desc,
                'quantidade' => $qtd,
                'valor_unitario' => $valUnit,
                'valor_total' => $valTot,
                'origem' => $origem
            ];
        }
        $stmtAv->close();
    }
    return $produtosVendidos;
}

// Verifica se foi recebido um valor por método POST
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
            $dataFim = date('Y-m-t') . ' 23:59:59';
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
            if (isset($_POST["data_inicio"]) && isset($_POST["data_fim"])) {
                $dataInicio = formatarData($_POST["data_inicio"], true);
                $dataFim = formatarData($_POST["data_fim"], false);
            } else {
                $dataInicio = date('Y-m-01') . ' 00:00:00';
                $dataFim = date('Y-m-t') . ' 23:59:59';
            }
            break;
        default:
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-t') . ' 23:59:59';
            break;
    }
} else {
    $dataInicio = date('Y-m-01') . ' 00:00:00';
    $dataFim = date('Y-m-t') . ' 23:59:59';
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
verificarCargoUsuario(['admin']);

$dadosGeraisPorCaixa = [];
if ($conexao === null) {
    $resultado = []; 
    $locacoes = []; 
    $produtosVendidos = [];
} else {
    $idCaixas = obterIdCaixa($conexao, $dataInicio, $dataFim);
    $resultado = calcularValorELocacoes($conexao, $idCaixas);
    foreach ($resultado as $r) {
        $dadosGeraisPorCaixa[$r['id_caixa']] = $r;
    }
    $locacoes = carregarLocacoes($conexao, $idCaixas);
    $produtosVendidos = carregarProdutosVendidos($conexao, $idCaixas);
}

if (isset($conexao) && $conexao !== null) {
    $conexao->close();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extrato Diário</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>

    <style>
        body {
            background-color: #f8f9fa;
            margin-bottom: 85px;
        }
        .container {
            padding-top: 20px;
        }
        .month-content {
            margin-bottom: 15px;
        }
        .page-title {
            color: #0d6efd;
            margin-bottom: 25px;
        }
        .geral-card {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 10px;
            background: #ffffff;
        }
        .geral-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 1rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.92rem;
        }
        .geral-list-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <script>
        const dadosGeraisCaixa = <?php echo json_encode($dadosGeraisPorCaixa); ?>;
        const locacoes = <?php echo json_encode($locacoes); ?>;
        const produtosVendidos = <?php echo json_encode($produtosVendidos); ?>;
    </script>
    
    <div class="container">
        <h3 class="text-center page-title">Extrato Diário</h3>
        
        <div class="options mb-4 p-3 border rounded bg-white shadow-sm">
            <form method="post" action="ExtratoDiario.php" class="row g-3 align-items-end justify-content-center">
                
                <div class="col-md-5 col-lg-3">
                    <label for="period" class="form-label fw-bold">Selecione o período:</label>
                    <select name="period" id="period" class="form-select">
                        <option value="7" <?php echo (isset($period) && $period == '7') ? 'selected' : ''; ?>>Últimos 7 dias</option>
                        <option value="15" <?php echo (isset($period) && $period == '15') ? 'selected' : ''; ?>>Últimos 15 dias</option>
                        <option value="30" <?php echo (isset($period) && $period == '30') ? 'selected' : ''; ?>>Últimos 30 dias</option>
                        <option value="this_month" <?php echo (!isset($period) || $period == 'this_month') ? 'selected' : ''; ?>>Este Mês</option>
                        <option value="last_month" <?php echo (isset($period) && $period == 'last_month') ? 'selected' : ''; ?>>Mês Passado</option>
                        <option value="this_year" <?php echo (isset($period) && $period == 'this_year') ? 'selected' : ''; ?>>Anual (Este Ano)</option>
                        <option value="custom" <?php echo (isset($period) && $period == 'custom') ? 'selected' : ''; ?>>Definir um Período</option>
                    </select>
                </div>
                
                <div id="custom_period" class="row g-3 <?php echo (isset($period) && $period == 'custom') ? '' : 'd-none'; ?> justify-content-center">
                    <div class="col-md-3">
                        <label for="data_inicio" class="form-label">Data de Início:</label>
                        <input type="text" id="data_inicio" name="data_inicio" class="form-control" value="<?php echo isset($_POST['data_inicio']) ? htmlspecialchars($_POST['data_inicio']) : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="data_fim" class="form-label">Data de Fim:</label>
                        <input type="text" id="data_fim" name="data_fim" class="form-control" value="<?php echo isset($_POST['data_fim']) ? htmlspecialchars($_POST['data_fim']) : ''; ?>">
                    </div>
                </div>

                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary mt-3 mt-md-0">Filtrar</button>
                </div>
            </form>  
        </div> 
        
        <div class="content-panel row" id="painelConteudo">
            <?php if (empty($resultado)): ?>
                <div class="col-12">
                    <div class="alert alert-warning text-center" role="alert">
                        Nenhum resultado encontrado para o período selecionado.
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($resultado as $res): ?>
                <div class="col-12 col-sm-6 col-md-4 col-lg-3 mb-4">
                    <div class="card shadow-sm month-content h-100" onclick="exibirLocacoesNoModal(<?php echo $res['id_caixa']; ?>, '<?php echo $res['data']; ?>')" style="cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 16px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='';" title="Clique para ver os detalhes deste caixa">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-primary text-center"><?php echo $res['data']; ?></h5>
                            <p class="card-text text-center fw-bold mb-2">
                                <span class="text-muted">R$:</span>
                                <?php echo number_format($res['valor_fechamento'], 2, ',', '.'); ?> 
                                | 
                                <span class="text-muted">Locações:</span>
                                <?php echo $res['total_locacoes']; ?>
                            </p>
                            <div class="text-start small text-muted mb-0" style="font-size: 0.82rem; line-height: 1.4;">
                                <div class="d-flex justify-content-between border-bottom pb-1 mb-1 border-light-subtle">
                                    <span>Abertura:</span>
                                    <span class="fw-medium text-dark"><?php echo date("H:i", strtotime($res['hora_abre'])); ?> <span class="text-secondary">(<?php echo htmlspecialchars($res['usuario_abre'] ?: 'N/A'); ?>)</span></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Fechamento:</span>
                                    <span class="fw-medium text-dark">
                                        <?php if (!empty($res['hora_fecha'])): ?>
                                            <?php echo date("H:i", strtotime($res['hora_fecha'])); ?> 
                                            <span class="text-secondary">(<?php echo htmlspecialchars($res['usuario_fecha'] ?: 'N/A'); ?>)</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill" style="font-size: 0.7rem; padding: 2px 6px;">Aberto</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div> 
    </div> 

    <div class="modal fade" id="locacoesModal" tabindex="-1" aria-labelledby="locacoesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="modal-title mb-0" id="locacoesModalLabel">Detalhes do Caixa - <span id="modalData"></span></h5>
                    <div class="btn-group ms-auto me-2" role="group" aria-label="Visualização">
                        <button type="button" class="btn btn-sm btn-outline-primary active" id="btnVerGeral" onclick="alternarVisualizacao('geral')">
                            <i class="bi bi-card-list me-1"></i>Geral
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnVerLocacoes" onclick="alternarVisualizacao('locacoes')">
                            <i class="bi bi-door-open me-1"></i>Locações
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnVerProdutos" onclick="alternarVisualizacao('produtos')">
                            <i class="bi bi-box-seam me-1"></i>Produtos
                        </button>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- ABA 1: GERAL (Dados completos do caixa) -->
                    <div id="painelGeralContainer" class="p-3 bg-light">
                    </div>

                    <!-- ABA 2: LOCAÇÕES -->
                    <div class="table-responsive d-none" id="tabelaLocacoesContainer">
                        <table class="table table-sm table-striped table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Início</th>
                                    <th>Fim</th>
                                    <th>Quarto</th>
                                    <th>Vlr Quarto</th>
                                    <th>Vlr Consumo</th>
                                    <th>Desconto</th>
                                    <th>Acréscimo</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody id="modalLocacoesBody">
                            </tbody>
                        </table>
                    </div>

                    <!-- ABA 3: PRODUTOS -->
                    <div class="table-responsive d-none" id="tabelaProdutosContainer">
                        <table class="table table-sm table-striped table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Produto</th>
                                    <th>Quantidade</th>
                                    <th>Vlr Unitário</th>
                                    <th>Vlr Total</th>
                                    <th>Origem</th>
                                </tr>
                            </thead>
                            <tbody id="modalProdutosBody">
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(function() {
            $("#period").change(function() {
                if ($(this).val() == 'custom') {
                    $("#custom_period").removeClass('d-none');
                } else {
                    $("#custom_period").addClass('d-none');
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
        });

        function formatarMoeda(valor) {
            return "R$ " + parseFloat(valor || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }
        
        function dataSemAno(data) {
            const dataObj = new Date(data);
            if (isNaN(dataObj)) return '';
            const dia = String(dataObj.getDate()).padStart(2, '0');
            const mes = String(dataObj.getMonth() + 1).padStart(2, '0');
            const hora = String(dataObj.getHours()).padStart(2, '0');
            const minuto = String(dataObj.getMinutes()).padStart(2, '0');
            return `${dia}/${mes} ${hora}:${minuto}`;
        }

        function renderizarAbaGeral(info) {
            if (!info) {
                return `<div class="alert alert-secondary m-0">Dados gerais deste caixa não encontrados.</div>`;
            }

            const statusBadge = info.hora_fecha_fmt
                ? `<span class="badge bg-secondary">Fechado</span>`
                : `<span class="badge bg-success">Caixa Aberto</span>`;

            const fechamentoTexto = info.hora_fecha_fmt
                ? `${info.hora_fecha_fmt} <span class="text-muted">(${info.usuario_fecha || 'N/A'})</span>`
                : `<span class="text-success fw-semibold">Em andamento</span>`;

            return `
                <!-- Banner Faturamento Total do Caixa -->
                <div class="card bg-primary text-white shadow-sm mb-3 border-0">
                    <div class="card-body py-3 d-flex flex-column flex-md-row justify-content-between align-items-center text-center text-md-start">
                        <div>
                            <div class="small text-white-50 text-uppercase fw-semibold">Faturamento Total do Caixa #${info.id_caixa}</div>
                            <div class="fs-3 fw-bold">${formatarMoeda(info.valor_fechamento)}</div>
                        </div>
                        <div class="mt-2 mt-md-0 text-md-end">
                            <div>${statusBadge}</div>
                            <div class="small text-white-50 mt-1">Hospedagens: <strong class="text-white">${info.total_locacoes}</strong> | Ticket Médio: <strong class="text-white">${formatarMoeda(info.ticket_medio)}</strong></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Coluna 1: Dados do Turno e Resumo de Vendas -->
                    <div class="col-12 col-lg-6">
                        <div class="geral-card shadow-sm mb-3">
                            <div class="card-header bg-white fw-bold py-2 border-bottom">
                                <i class="bi bi-clock-history text-primary me-1"></i> Dados de Abertura e Fechamento
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">ID do Caixa:</span>
                                <strong>#${info.id_caixa}</strong>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Abertura:</span>
                                <span><strong>${info.hora_abre_fmt}</strong> <span class="text-secondary">(${info.usuario_abre || 'N/A'})</span></span>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Fechamento:</span>
                                <span><strong>${fechamentoTexto}</strong></span>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Saldo Inicial (Troco):</span>
                                <span class="fw-semibold">${formatarMoeda(info.saldo_abre)}</span>
                            </div>
                        </div>

                        <div class="geral-card shadow-sm">
                            <div class="card-header bg-white fw-bold py-2 border-bottom">
                                <i class="bi bi-receipt text-primary me-1"></i> Origem das Receitas e Ajustes
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Quant. de Hospedagens:</span>
                                <strong>${info.total_locacoes}</strong>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Valor Hospedagens (Suítes):</span>
                                <span class="fw-semibold">${formatarMoeda(info.valor_quartos)}</span>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Consumo nas Suítes:</span>
                                <span class="fw-semibold">${formatarMoeda(info.valor_consumo_suites)}</span>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Vendas Avulsas:</span>
                                <span class="fw-semibold">${formatarMoeda(info.valor_vendas_avulsas)}</span>
                            </div>
                            <div class="geral-list-item bg-light">
                                <strong>Consumo Total (Suítes + Avulsas):</strong>
                                <strong>${formatarMoeda(info.valor_consumo_total)}</strong>
                            </div>
                            <div class="geral-list-item text-danger">
                                <span>Descontos Concedidos (-):</span>
                                <strong>- ${formatarMoeda(info.descontos)}</strong>
                            </div>
                            <div class="geral-list-item text-success">
                                <span>Acréscimos (+):</span>
                                <strong>+ ${formatarMoeda(info.acrescimos)}</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Coluna 2: Recebimentos por Forma de Pagamento e Movimentações -->
                    <div class="col-12 col-lg-6">
                        <div class="geral-card shadow-sm mb-3">
                            <div class="card-header bg-white fw-bold py-2 border-bottom">
                                <i class="bi bi-wallet2 text-success me-1"></i> Entradas por Forma de Pagamento
                            </div>
                            <div class="geral-list-item">
                                <span><i class="bi bi-cash-stack text-success me-2"></i><strong>Dinheiro:</strong></span>
                                <span class="fw-bold text-dark">${formatarMoeda(info.dinheiro)}</span>
                            </div>
                            <div class="geral-list-item">
                                <span><i class="bi bi-qr-code text-info me-2"></i><strong>Pix:</strong></span>
                                <span class="fw-bold text-dark">${formatarMoeda(info.pix)}</span>
                            </div>
                            <div class="geral-list-item bg-light">
                                <span><i class="bi bi-credit-card-2-front text-primary me-2"></i><strong>Cartão (Total):</strong></span>
                                <span class="fw-bold text-primary">${formatarMoeda(info.cartao_total)}</span>
                            </div>
                            <div class="geral-list-item ps-4">
                                <span class="text-muted">• Cartão de Crédito:</span>
                                <span class="fw-semibold">${formatarMoeda(info.credito)}</span>
                            </div>
                            <div class="geral-list-item ps-4">
                                <span class="text-muted">• Cartão de Débito:</span>
                                <span class="fw-semibold">${formatarMoeda(info.debito)}</span>
                            </div>
                        </div>

                        <div class="geral-card shadow-sm">
                            <div class="card-header bg-white fw-bold py-2 border-bottom">
                                <i class="bi bi-arrow-left-right text-warning me-1"></i> Outras Movimentações no Caixa
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Retiradas / Sangrias (-):</span>
                                <span class="fw-semibold text-danger">- ${formatarMoeda(info.retiradas)}</span>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Despesas Pagas no Caixa (-):</span>
                                <span class="fw-semibold text-danger">- ${formatarMoeda(info.despesas_caixa)}</span>
                            </div>
                            <div class="geral-list-item">
                                <span class="text-muted">Adiantamentos de Funcionários:</span>
                                <span class="fw-semibold text-primary">${formatarMoeda(info.adiantamentos)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function exibirLocacoesNoModal(idCaixa, dataCaixa) {
            const modalTitleSpan = document.getElementById('modalData');
            const painelGeral = document.getElementById('painelGeralContainer');
            const tabelaBody = document.getElementById('modalLocacoesBody');
            const tabelaProdutosBody = document.getElementById('modalProdutosBody');
            
            painelGeral.innerHTML = renderizarAbaGeral(dadosGeraisCaixa[idCaixa]);
            tabelaBody.innerHTML = '';
            tabelaProdutosBody.innerHTML = '';
            
            modalTitleSpan.textContent = dataCaixa + ' (Caixa #' + idCaixa + ')';

            // Abre por padrão na aba Geral
            alternarVisualizacao('geral');

            // Preenche a tabela de locações
            if (locacoes[idCaixa] && locacoes[idCaixa].length > 0) {
                locacoes[idCaixa].forEach(locacao => {
                    const row = tabelaBody.insertRow();
                    row.innerHTML = `
                        <td>${dataSemAno(locacao.horainicio)}</td>
                        <td>${locacao.horafim ? dataSemAno(locacao.horafim) : 'N/A'}</td>
                        <td>${locacao.numquarto}</td>
                        <td>${formatarMoeda(locacao.valorquarto)}</td>
                        <td>${formatarMoeda(locacao.valorconsumo)}</td>
                        <td class="text-danger">${formatarMoeda(locacao.desconto)}</td>
                        <td class="text-success">${formatarMoeda(locacao.acrescimo)}</td>
                        <td class="fw-semibold">${formatarMoeda(locacao.total)}</td>`;
                });
            } else {
                const row = tabelaBody.insertRow();
                row.innerHTML = `<td colspan="8" class="text-center text-muted py-3">Nenhuma locação encontrada para este caixa.</td>`;
            }

            // Preenche a tabela de produtos vendidos
            if (produtosVendidos[idCaixa] && produtosVendidos[idCaixa].length > 0) {
                produtosVendidos[idCaixa].forEach(prod => {
                    const row = tabelaProdutosBody.insertRow();
                    row.innerHTML = `
                        <td>${prod.descricao}</td>
                        <td>${prod.quantidade}</td>
                        <td>${formatarMoeda(prod.valor_unitario)}</td>
                        <td class="fw-semibold">${formatarMoeda(prod.valor_total)}</td>
                        <td><span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">${prod.origem}</span></td>`;
                });
            } else {
                const row = tabelaProdutosBody.insertRow();
                row.innerHTML = `<td colspan="5" class="text-center text-muted py-3">Nenhum produto vendido neste caixa.</td>`;
            }

            const locacoesModal = new bootstrap.Modal(document.getElementById('locacoesModal'));
            locacoesModal.show();
        }

        function alternarVisualizacao(tipo) {
            const geralEl = document.getElementById('painelGeralContainer');
            const locEl = document.getElementById('tabelaLocacoesContainer');
            const prodEl = document.getElementById('tabelaProdutosContainer');

            const btnGeral = document.getElementById('btnVerGeral');
            const btnLoc = document.getElementById('btnVerLocacoes');
            const btnProd = document.getElementById('btnVerProdutos');

            geralEl.classList.add('d-none');
            locEl.classList.add('d-none');
            prodEl.classList.add('d-none');

            btnGeral.classList.remove('active');
            btnLoc.classList.remove('active');
            btnProd.classList.remove('active');

            if (tipo === 'geral') {
                geralEl.classList.remove('d-none');
                btnGeral.classList.add('active');
            } else if (tipo === 'locacoes') {
                locEl.classList.remove('d-none');
                btnLoc.classList.add('active');
            } else {
                prodEl.classList.remove('d-none');
                btnProd.classList.add('active');
            }
        }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <?php include 'menu.php'; ?> 
</body>
</html>
