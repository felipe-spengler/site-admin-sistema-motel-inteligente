<?php
date_default_timezone_set('America/Sao_Paulo');

// Função para formatar a data no formato MySQL (Y-m-d H:i:s)
function formatarData($data, $horaInicial = true)
{
    // Divide a data em dia, mês e ano
    $partes = explode('/', $data);
    $dia = $partes[0];
    $mes = $partes[1];
    $ano = $partes[2];

    // Verifica se devemos adicionar a hora 00:00:00 ou 23:59:59
    $hora = $horaInicial ? '00:00:00' : '23:59:59';

    // Formata a data e hora de acordo com o formato MySQL
    return sprintf('%04d-%02d-%02d %s', $ano, $mes, $dia, $hora);
}
$selectedPeriod = 'this_month';
// Verifica se foi recebido um valor por método POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["period"])) {
    $selectedPeriod = $_POST["period"];
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
        case "custom":
            // Verifica se foram enviados dados de período personalizado
            if (isset($_POST["data_inicio"]) && isset($_POST["data_fim"])) {
                // Para adicionar a hora 00:00:00
                $dataInicio = formatarData($_POST["data_inicio"], true);

                // Para adicionar a hora 23:59:59
                $dataFim = formatarData($_POST["data_fim"], false);

            } else {
                // Se não foram recebidos dados, assumir período "Este Mês"
                $dataInicio = date('Y-m-01') . ' 00:00:00';
                $dataFim = date('Y-m-d') . ' 23:59:59';
            }
            break;
        default:
            // Se nenhum dos casos corresponder, assume-se "Este Mês"
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-d') . ' 23:59:59';
            break;
    }
} else {
    // Se não foi recebido nenhum valor, assume-se "Este Mês"
    $dataInicio = date('Y-m-01') . ' 00:00:00';
    $dataFim = date('Y-m-d') . ' 23:59:59';
}

include 'verificar_acesso.php';
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


$conexao = conectarAoBanco();

$paginaAtual = basename($_SERVER['PHP_SELF']);
verificarCookie($conexao, $paginaAtual);
verificarCargoUsuario(['admin', 'gerente']);
if ($conexao === null) {
    return ["erro" => true, "mensagem" => "Erro na conexão com o banco de dados."];
} else {
    $idCaixas = obterIdCaixa($conexao, $dataInicio, $dataFim);

    $numLocacoes = obterTotalLocacoes($conexao, $idCaixas);
    $valorAcresDesc = calcularDescontoAcrescimo($conexao, $idCaixas);
    $diferencaDias = diferencaDias($dataInicio, $dataFim);
    if ($diferencaDias === 0) {
        $mediaLocacoes = 0;
    } else {
        $mediaLocacoes = $numLocacoes / $diferencaDias;
    }

    $numDias = calcularNumeroDias($dataInicio, $dataFim);
    $medias = calcularMedias($conexao, $idCaixas);
    $faturamentoAtual = $medias["faturamentoTotal"];
    $faturamentoMes = obterFaturamentoMes($conexao, $dataInicio, $dataFim);
    if ($diferencaDias === 0) {
        $mediaFaturamento = 0;
    } else {
        $mediaFaturamento = $faturamentoAtual / $diferencaDias;
    }

}

$conexao->close();


function obterIdCaixa($conexao, $dataInicio, $dataFim)
{

    $sql = "SELECT id FROM caixa WHERE horaabre >= '$dataInicio' AND horaabre <= '$dataFim'";

    $result = $conexao->query($sql);

    if ($result) {
        $idCaixas = array();

        while ($row = $result->fetch_assoc()) {
            $idCaixas[] = $row['id'];
        }

        return $idCaixas;
    } else {
        return array();
    }
}
function obterFaturamentoMes($conexao, $dataInicio, $dataFim)
{
    // Obter todos os caixas no período
    $sql = "SELECT id FROM caixa WHERE horaabre >= '$dataInicio' AND horaabre <= '$dataFim'";
    $result = $conexao->query($sql);
    $faturamento = 0;
    
    if ($result && $result->num_rows > 0) {
        $idsArray = array();
        while ($row = $result->fetch_assoc()) {
            $idsArray[] = $row['id'];
        }
        $ids = implode(",", $idsArray);
        
        $sqlLoc = "SELECT SUM(pagodinheiro + pagopix + pagocartao) as soma FROM registralocado WHERE idcaixaatual IN ($ids)";
        $resLoc = $conexao->query($sqlLoc);
        $somaLoc = 0;
        if ($resLoc && $rowLoc = $resLoc->fetch_assoc()) {
            $somaLoc = (float)($rowLoc['soma'] ?? 0.00);
        }
        
        $sqlAv = "SELECT SUM(valortotal) as soma FROM vendas_avulsas WHERE idcaixa IN ($ids) AND tipo != 'adiantamento'";
        $resAv = $conexao->query($sqlAv);
        $somaAv = 0;
        if ($resAv && $rowAv = $resAv->fetch_assoc()) {
            $somaAv = (float)($rowAv['soma'] ?? 0.00);
        }
        
        $faturamento = $somaLoc + $somaAv;
    }
    return $faturamento;
}
function diferencaDias($dataInicio, $dataFim)
{
    // Converte as datas para objetos DateTime
    $inicio = new DateTime($dataInicio);
    $fim = new DateTime($dataFim);

    // Calcula a diferença em dias
    $diferenca = $fim->diff($inicio)->days;

    return $diferenca;
}

function obterTotalLocacoes($conexao, $idCaixas)
{
    if (empty($idCaixas)) {
        return 0; // Retorna 0 se o array estiver vazio
    }

    $ids = implode(",", $idCaixas);

    $sql = "SELECT SUM((SELECT COUNT(*) FROM registralocado WHERE idcaixaatual IN ($ids))) as totalLocacoes";


    $result = $conexao->query($sql);

    if ($result) {
        $row = $result->fetch_assoc();
        return ($row['totalLocacoes'] != null) ? $row['totalLocacoes'] : 0;
    } else {
        return 0;
    }
}
function calcularNumeroDias($dataInicio, $dataFim)
{
    // Converte as datas para o formato timestamp
    $timestampInicio = strtotime($dataInicio);
    $timestampFim = strtotime($dataFim);

    // Calcula a diferença em segundos
    $diferencaSegundos = $timestampFim - $timestampInicio;

    // Converte a diferença de segundos para dias
    $diferencaDias = round($diferencaSegundos / (60 * 60 * 24));

    return $diferencaDias;
}
function calcularDescontoAcrescimo($conexao, $arrayDeIds)
{
    // Verifica se o array de IDs não está vazio
    if (empty($arrayDeIds)) {
        return ["descontos" => 0, "acrescimos" => 0];
    }

    // Converte o array de IDs para uma string para usar na cláusula IN
    $ids = implode(',', $arrayDeIds);

    $sql = "
        SELECT j.tipo, j.valor
        FROM justificativa j
        JOIN registralocado r ON j.idlocacao = r.idlocacao
        JOIN caixa c ON r.idcaixaatual = c.id
        WHERE c.id IN ($ids)"; // Ajuste para considerar o intervalo de datas

    $resultado = mysqli_query($conexao, $sql);

    if ($resultado) {
        $descontos = 0;
        $acrescimos = 0;

        // Processa os resultados e aplica o desconto/acréscimo
        while ($row = mysqli_fetch_assoc($resultado)) {
            if (!isset($row['tipo']) || !isset($row['valor'])) {
                continue; // Se os dados não estiverem completos, ignora o item
            }

            $tipo = $row['tipo'];
            $valor = $row['valor'];

            if ($tipo === 'desconto') {
                $descontos += $valor;
            } elseif ($tipo === 'acrescimo') {
                $acrescimos += $valor;
            }
        }

        return [
            "descontos" => $descontos,
            "acrescimos" => $acrescimos
        ];
    } else {
        // Se houve erro na execução da consulta
        return ["descontos" => 0, "acrescimos" => 0];
    }
}


function calcularMedias($conexao, $idCaixas)
{
    if (empty($idCaixas)) {
        return [
            "mediaValorConsumo" => 0,
            "mediaValorQuarto" => 0,
            "ticketMedioLocacoes" => 0,
            "somaDinheiro" => 0,
            "somaCartao" => 0,
            "somaPix" => 0,
            "somaValorConsumo" => 0,
            "somaValorQuarto" => 0,
            "numRegistros" => 0,
            "somaCartaoCredito" => 0,
            "somaCartaoDebito" => 0,
            "faturamentoTotal" => 0
        ];
    }

    // Converte os IDs para uma string para a cláusula IN
    $ids = implode(",", $idCaixas);

    // Somar dados de vendas_avulsas para os caixas no período
    $somaAvulsasConsumo = 0;
    $somaAvulsasDinheiro = 0;
    $somaAvulsasPix = 0;
    $somaAvulsasCartao = 0;
    $somaAvulsasCredito = 0;
    $somaAvulsasDebito = 0;

    $sqlAvulsas = "SELECT formapagamento, tipo, SUM(valortotal) AS total 
                   FROM vendas_avulsas 
                   WHERE idcaixa IN ($ids) 
                   GROUP BY formapagamento, tipo";
    $resultAvulsas = $conexao->query($sqlAvulsas);
    if ($resultAvulsas) {
        while ($rowAv = $resultAvulsas->fetch_assoc()) {
            $total = (float)$rowAv['total'];
            $pgto = $rowAv['formapagamento'];
            $tipo = $rowAv['tipo'];
            
            $somaAvulsasConsumo += $total;
            
            if ($tipo !== 'adiantamento') {
                if ($pgto === 'dinheiro') {
                    $somaAvulsasDinheiro += $total;
                } else if ($pgto === 'pix') {
                    $somaAvulsasPix += $total;
                } else if ($pgto === 'credito') {
                    $somaAvulsasCartao += $total;
                    $somaAvulsasCredito += $total;
                } else if ($pgto === 'debito') {
                    $somaAvulsasCartao += $total;
                    $somaAvulsasDebito += $total;
                } else {
                    $somaAvulsasCartao += $total;
                }
            }
        }
    }

    // Consulta SQL Corrigida:
    // 1. Mantemos o JOIN com a tabela caixa e o filtro 'horafecha IS NOT NULL' conforme seu critério.
    // 2. Removemos o LEFT JOIN direto com valorcartao para evitar a duplicação de somas (SUM).
    // 3. Utilizamos subconsultas para obter os detalhes de crédito/débito sem inflar os valores de hospedagem.
    $consulta = "
        SELECT
            SUM(r.valorconsumo) AS somaValorConsumo,
            SUM(r.valorquarto) AS somaValorQuarto,
            SUM(r.valorconsumo + r.valorquarto) AS somaLocacoes,
            SUM(r.pagodinheiro) AS somaDinheiro,
            SUM(r.pagocartao) AS somaCartao,
            SUM(r.pagopix) AS somaPix,
            -- COUNT(DISTINCT) garante a contagem correta de hospedagens únicas
            COUNT(DISTINCT r.idlocacao) AS numRegistros,
            
            -- Buscamos os detalhes do cartão de forma independente
            (SELECT COALESCE(SUM(vc.valorcredito), 0) 
             FROM valorcartao vc 
             JOIN registralocado r2 ON vc.idlocacao = r2.idlocacao 
             WHERE r2.idcaixaatual IN ($ids)) AS somaCartaoCredito,
             
            (SELECT COALESCE(SUM(vc.valordebito), 0) 
             FROM valorcartao vc 
             JOIN registralocado r2 ON vc.idlocacao = r2.idlocacao 
             WHERE r2.idcaixaatual IN ($ids)) AS somaCartaoDebito
            
        FROM registralocado r
        JOIN caixa c ON r.idcaixaatual = c.id
        WHERE r.idcaixaatual IN ($ids)
    ";

    // Executa a consulta
    $resultado = mysqli_query($conexao, $consulta);

    if ($resultado) {
        $row = mysqli_fetch_assoc($resultado);

        // Faturamento total (Hospedagem + Consumo + Vendas Avulsas)
        $faturamentoTotal = ($row['somaValorConsumo'] ?? 0) + $somaAvulsasConsumo + ($row['somaValorQuarto'] ?? 0);

        return [
            "mediaValorConsumo" => $row['numRegistros'] > 0 ? (($row['somaValorConsumo'] ?? 0) + $somaAvulsasConsumo) / $row['numRegistros'] : 0,
            "mediaValorQuarto" => $row['numRegistros'] > 0 ? ($row['somaValorQuarto'] ?? 0) / $row['numRegistros'] : 0,
            "ticketMedioLocacoes" => $row['numRegistros'] > 0 ? (($row['somaValorConsumo'] ?? 0) + $somaAvulsasConsumo + ($row['somaValorQuarto'] ?? 0)) / $row['numRegistros'] : 0,
            "somaDinheiro" => ($row['somaDinheiro'] ?? 0) + $somaAvulsasDinheiro,
            "somaCartao" => ($row['somaCartao'] ?? 0) + $somaAvulsasCartao,
            "somaPix" => ($row['somaPix'] ?? 0) + $somaAvulsasPix,
            "somaValorConsumo" => ($row['somaValorConsumo'] ?? 0) + $somaAvulsasConsumo,
            "somaConsumoSuites" => $row['somaValorConsumo'] ?? 0,
            "somaAvulsas" => $somaAvulsasConsumo,
            "somaValorQuarto" => $row['somaValorQuarto'] ?? 0,
            "numRegistros" => $row['numRegistros'],
            "somaCartaoCredito" => ($row['somaCartaoCredito'] ?? 0) + $somaAvulsasCredito,
            "somaCartaoDebito" => ($row['somaCartaoDebito'] ?? 0) + $somaAvulsasDebito,
            "faturamentoTotal" => $faturamentoTotal
        ];
    } else {
        // Retorno padrão em caso de erro na query
        return [
            "mediaValorConsumo" => 0, "mediaValorQuarto" => 0, "ticketMedioLocacoes" => 0,
            "somaDinheiro" => 0, "somaCartao" => 0, "somaPix" => 0,
            "somaCartaoCredito" => 0, "somaCartaoDebito" => 0,
            "somaValorConsumo" => 0, "somaConsumoSuites" => 0, "somaAvulsas" => 0, "somaValorQuarto" => 0,
            "numRegistros" => 0, "faturamentoTotal" => 0
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demonstrativo de Resultado - DRE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <style>
        /* Estilos customizados para melhorar o visual do Bootstrap */
        body {
            background-color: #f8f9fa;
            /* Light grey background */
        }

        .main-container {
            max-width: 960px;
            /* Largura máxima para desktops */
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .card {
            margin-bottom: 15px;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .list-group-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1.25rem;
        }

        .list-group-item strong {
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div class="container main-container">
        <h3 class="text-center mb-4 text-primary">Demonstrativo de Resultado (DRE)</h3>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="post" action="Dre.php" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="period" class="form-label fw-bold">Selecione o Período</label>
                        <select name="period" id="period" class="form-select">
                            <option value="7" <?php echo ($selectedPeriod == '7' ? 'selected' : ''); ?>>Últimos 7 dias
                            </option>
                            <option value="15" <?php echo ($selectedPeriod == '15' ? 'selected' : ''); ?>>Últimos 15 dias
                            </option>
                            <option value="30" <?php echo ($selectedPeriod == '30' ? 'selected' : ''); ?>>Últimos 30 dias
                            </option>
                            <option value="this_month" <?php echo ($selectedPeriod == 'this_month' ? 'selected' : ''); ?>>
                                Este Mês</option>
                            <option value="last_month" <?php echo ($selectedPeriod == 'last_month' ? 'selected' : ''); ?>>
                                Mês Passado</option>
                            <option value="custom" <?php echo ($selectedPeriod == 'custom' ? 'selected' : ''); ?>>Definir
                                um Período</option>
                        </select>
                    </div>

                    <div id="custom_period" class="col-md-6 row g-2"
                        style="display: <?php echo ($selectedPeriod == 'custom' ? 'flex' : 'none'); ?>;">
                        <div class="col-sm-6">
                            <label for="data_inicio" class="form-label fw-bold">Início</label>
                            <input type="text" id="data_inicio" name="data_inicio" class="form-control"
                                value="<?php echo ($selectedPeriod == 'custom' && isset($_POST['data_inicio']) ? htmlspecialchars($_POST['data_inicio']) : ''); ?>"
                                placeholder="dd/mm/aaaa">
                        </div>
                        <div class="col-sm-6">
                            <label for="data_fim" class="form-label fw-bold">Fim</label>
                            <input type="text" id="data_fim" name="data_fim" class="form-control"
                                value="<?php echo ($selectedPeriod == 'custom' && isset($_POST['data_fim']) ? htmlspecialchars($_POST['data_fim']) : ''); ?>"
                                placeholder="dd/mm/aaaa">
                        </div>
                    </div>

                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">Aplicar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">

            <div class="col-lg-12">
                <div class="card text-bg-primary mb-3 shadow">
                    <div class="card-body">
                        <h5 class="card-title">Faturamento Total no Período</h5>
                        <p class="card-text fs-3 fw-bold">
                            <?php echo "R$ " . number_format($faturamentoMes, 2, ',', '.'); ?>
                        </p>
                        <p class="card-text">
                            <small>Faturamento total (Caixa Fechado + Acresc/Desc)</small>
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">

                <div class="card shadow mb-4">
                    <div class="card-header bg-light fw-bold">Vendas no Período (<?php echo $numDias; ?> dias)</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>Quant. de Hospedagens:</strong>
                            <span><?php echo number_format($numLocacoes, 0, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Valor Hospedagem:</strong>
                            <span><?php echo "R$ " . number_format($medias["somaValorQuarto"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Consumo nas Suítes:</strong>
                            <span><?php echo "R$ " . number_format($medias["somaConsumoSuites"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Vendas Avulsas:</strong>
                            <span><?php echo "R$ " . number_format($medias["somaAvulsas"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Consumo Total (Suítes + Avulsas):</strong>
                            <span><?php echo "R$ " . number_format($medias["somaValorConsumo"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item text-danger">
                            <strong>Descontos (-):</strong>
                            <span class="fw-bold"><?php echo "R$ " . number_format($valorAcresDesc["descontos"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item text-success">
                            <strong>Acréscimos (+):</strong>
                            <span class="fw-bold"><?php echo "R$ " . number_format($valorAcresDesc["acrescimos"], 2, ',', '.'); ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-6">

                <div class="card shadow mb-4">
                    <div class="card-header bg-light fw-bold">Médias por Registro / Diária</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>Ticket Médio:</strong>
                            <span><?php echo "R$ " . number_format($medias["ticketMedioLocacoes"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Média Hospedagem:</strong>
                            <span><?php echo "R$ " . number_format($medias["mediaValorQuarto"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Média Diária (valor):</strong>
                            <span><?php echo "R$ " . number_format($mediaFaturamento, 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Média Diária (qnt):</strong>
                            <span><?php echo number_format($mediaLocacoes, 0, ',', '.'); ?></span>
                        </li>
                    </ul>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header bg-light fw-bold">Recebimentos por Forma de Pagamento</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>Dinheiro:</strong>
                            <span><?php echo "R$ " . number_format($medias["somaDinheiro"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item">
                            <strong>Pix:</strong>
                            <span><?php echo "R$ " . number_format($medias["somaPix"], 2, ',', '.'); ?></span>
                        </li>
                        <li class="list-group-item list-group-item-secondary">
                            <strong class="text-dark">Cartão (Total):</strong>
                            <span
                                class="fw-bold text-dark"><?php echo "R$ " . number_format($medias["somaCartao"], 2, ',', '.'); ?></span>
                        </li>

                        <li class="list-group-item py-1">
                            <strong class="ms-4">Crédito:</strong>
                            <span><?php echo "R$ " . number_format($medias["somaCartaoCredito"], 2, ',', '.'); ?></span>
                        </li>

                        <li class="list-group-item py-1">
                            <strong class="ms-4">Débito:</strong>
                            <span><?php echo "R$ " . number_format($medias["somaCartaoDebito"], 2, ',', '.'); ?></span>
                        </li>


                    </ul>
                </div>

            </div>

        </div>
    </div> <?php include 'menu.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
    <script>
        $(document).ready(function () {
            // Exibir/Esconder campos de data customizada
            $("#period").change(function () {
                if ($(this).val() === 'custom') {
                    $("#custom_period").slideDown();
                } else {
                    $("#custom_period").slideUp();
                }
            });

            // Datepicker para os campos de data
            $("#data_inicio, #data_fim").datepicker({
                dateFormat: 'dd/mm/yy', // Formato de exibição da data
                dayNames: ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'],
                dayNamesMin: ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'],
                monthNames: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
                monthNamesShort: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                nextText: 'Próximo',
                prevText: 'Anterior'
            });

            // Validação simples antes de submeter
            $('form').submit(function (e) {
                if ($("#period").val() === 'custom') {
                    var dataInicio = $("#data_inicio").val();
                    var dataFim = $("#data_fim").val();

                    // Converte para um formato comparável (YYYYMMDD)
                    function parseDate(str) {
                        var parts = str.split('/');
                        if (parts.length === 3) {
                            return new Date(parts[2], parts[1] - 1, parts[0]);
                        }
                        return null;
                    }

                    var inicio = parseDate(dataInicio);
                    var fim = parseDate(dataFim);

                    if (!inicio || !fim) {
                        alert("Por favor, preencha as datas de Início e Fim no formato dd/mm/aaaa.");
                        e.preventDefault();
                        return;
                    }

                    if (inicio > fim) {
                        alert("A data de Início deve ser anterior ou igual à data de Fim.");
                        e.preventDefault();
                    }
                }
            });
        });
    </script>
</body>

</html>
