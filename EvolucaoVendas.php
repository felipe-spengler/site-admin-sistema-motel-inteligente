<?php
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
    default:
        die("Filial inválida.");
}

function obterAnosDistintos($conexao)
{
    $sql = "SELECT DISTINCT YEAR(horaabre) as ano FROM caixa";
    $result = $conexao->query($sql);

    if ($result) {
        $anos = array();

        while ($row = $result->fetch_assoc()) {
            $anos[] = $row['ano'];
        }
        return $anos;
    } else {
        return array();
    }
}
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
        return 0;
    }
}

function obterTotalLocacoes($conexao, $idCaixas)
{
    if (empty($idCaixas)) {
        return 0; // Retorna 0 se o array estiver vazio
    }

    $ids = implode(",", $idCaixas);

    // *AVISO: Esta query é muito ineficiente. Uma subconsulta dentro de SUM não é o ideal.*
    // Recomenda-se corrigir a lógica SQL para:
    // $sql = "SELECT COUNT(*) as totalLocacoes FROM registralocado WHERE idcaixaatual IN ($ids)";
    // *Para manter o código funcional como o original, mantive a query:*
    $sql = "SELECT SUM((SELECT COUNT(*) FROM registralocado WHERE idcaixaatual IN ($ids))) as totalLocacoes";


    $result = $conexao->query($sql);

    if ($result) {
        $row = $result->fetch_assoc();
        return ($row['totalLocacoes'] != null) ? $row['totalLocacoes'] : 0;
    } else {
        return 0;
    }
}
function contarLocacoesPorMes($conexao, $ano)
{
    $contagemPorMes = array();

    for ($mes = 1; $mes <= 12; $mes++) {
        // Definir o primeiro dia e último dia do mês
        $primeiroDia = date("$ano-$mes-01 00:01:00");
        $ultimoDia = date("Y-m-t 23:59:00", strtotime($primeiroDia));

        // Obter IDs da caixa para o mês atual
        $idCaixas = obterIdCaixa($conexao, $primeiroDia, $ultimoDia);

        // Contar total de locações para os IDs da caixa
        $totalLocacoes = obterTotalLocacoes($conexao, $idCaixas);

        // Armazenar a contagem no array
        $contagemPorMes[$mes] = $totalLocacoes;
    }

    return array('success' => true, 'contagem' => $contagemPorMes);
}

function calculaFaturamentoPorMes($conexao, $ano)
{
    $faturamentoPorMes = array();

    // Inicializa o array com valores padrão de 0 para todos os meses
    for ($mes = 1; $mes <= 12; $mes++) {
        $faturamentoPorMes[$mes] = 0;
    }

    // Consulta para obter o faturamento para cada mês do ano
    $sql = "SELECT MONTH(horaabre) as mes, SUM(saldofecha) as total_faturamento
             FROM caixa 
             WHERE YEAR(horaabre) = $ano 
             GROUP BY MONTH(horaabre)";

    $result = $conexao->query($sql);

    if (!$result) {
        // Trate o erro na consulta
        return array('success' => false, 'mensagem' => $conexao->error);
    }

    while ($row = $result->fetch_assoc()) {
        $faturamentoPorMes[$row['mes']] = $row['total_faturamento'] ?? 0;
    }

    return array('success' => true, 'faturamento' => $faturamentoPorMes);
}
if (!(isset($_COOKIE['usuario_cargo']))) {
    header('Location: index.php');
    exit;
} else {
    $cargo = $_COOKIE['usuario_cargo'];
    if ($cargo != "admin") {
        //não mostra o conteudo, vai para pagina de falta de cargo
        header('Location: faltaPermissao.php');
        exit;
    }
}
$conexao = conectarAoBanco();
include 'verificar_acesso.php';

$paginaAtual = basename($_SERVER['PHP_SELF']);
verificarCookie($conexao, $paginaAtual);
verificarCargoUsuario(['admin', 'gerente']);
if ($conexao === null) {
    echo "Erro na conexão com o banco de dados.";
} else {
    $anoAtual = date('Y');
    $ano = isset($_POST['ano']) ? $_POST['ano'] : $anoAtual;
    $anosSelect = obterAnosDistintos($conexao);

    if (!in_array($anoAtual, $anosSelect)) {
        $anosSelect[] = $anoAtual;
        // Ordena a lista de anos
        sort($anosSelect);
    }

    $contagemLocacoes = contarLocacoesPorMes($conexao, $ano);
    if ($contagemLocacoes['success']) {
        $locacoesPorMes = $contagemLocacoes['contagem'];

        // Calcula o faturamento por mês
        $faturamentoPorMes = calculaFaturamentoPorMes($conexao, $ano);
        if ($faturamentoPorMes['success']) {
            $faturamentoPorMes = $faturamentoPorMes['faturamento'];

            // Inicializa as variáveis para o melhor e pior mês
            $faturamentoMelhor = reset($faturamentoPorMes); // Primeiro valor do array de faturamento
            $locacoesMelhor = reset($locacoesPorMes); // Primeiro valor do array de locações
            $faturamentoMenor = 0;
            $locacoesMenor = 0;

            // Encontra o primeiro faturamento maior que zero e inicializa o menor faturamento e locações correspondentes
            foreach ($faturamentoPorMes as $mes => $faturamento) {
                if ($faturamento > 0) {
                    $faturamentoMenor = $faturamento;
                    $locacoesMenor = $locacoesPorMes[$mes]; // Garante que o menor faturamento inicial corresponde às locações
                    break; // Para a iteração assim que o primeiro valor maior que zero for encontrado
                }
            }

            // Calcula o faturamento total e o total de locações
            $faturamentoTotal = 0;
            $locacoesTotal = 0;

            // Encontra o melhor e pior mês
            foreach ($faturamentoPorMes as $mes => $faturamento) {
                $faturamentoTotal += $faturamento;
                $locacoesTotal += $locacoesPorMes[$mes];

                // Verifica o melhor faturamento
                if ($faturamento > $faturamentoMelhor) {
                    $faturamentoMelhor = $faturamento;
                    $locacoesMelhor = $locacoesPorMes[$mes];
                }

                // Verifica o pior faturamento
                if ($faturamento < $faturamentoMenor && $faturamento > 0) {
                    $faturamentoMenor = $faturamento;
                    $locacoesMenor = $locacoesPorMes[$mes]; // Atualiza o locacoesMenor corretamente
                }
            }
        } else {
            echo "Erro ao calcular o faturamento por mês: " . $faturamentoPorMes['mensagem'];
        }
    } else {
        echo "Erro ao contar locações por mês: " . $contagemLocacoes['mensagem'];
        // Define todos os valores como 0 caso haja erro na contagem de locações
        $faturamentoMelhor = 0;
        $locacoesMelhor = 0;
        $faturamentoMenor = 0;
        $locacoesMenor = 0;
        $faturamentoTotal = 0;
        $locacoesTotal = 0;
    }

    $conexao->close();
}

?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evolução de Vendas</title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <style>
        /* Estilos gerais */
        body {
            background-color: #f8f9fa;
            /* Fundo levemente mais claro */
        }

        /* Simula o max-width do container original, centralizando */
        .custom-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 15px;
            /* Mais padding no geral */
        }

        h4.main-title {
            text-align: center;
            margin-top: 2%;
            margin-bottom: 5%;
            /* Mais espaço abaixo do título principal */
            font-weight: 300;
            /* Título mais leve */
            color: #212529;
        }

        /* --- Estilo para as Estatísticas de Mês (Melhor/Pior/Total) --- */
        /* Usamos classes do Bootstrap para cores e sombras, e CSS para ajuste fino */
        .month-stat-card {
            text-align: center;
            height: 100%;
            transition: transform 0.2s;
            /* Efeito hover sutil */
        }

        .month-stat-card:hover {
            transform: translateY(-2px);
        }

        .month-stat-card h5 {
            font-size: 1rem;
            /* Títulos menores */
            font-weight: 600;
        }

        .month-stat-card p {
            margin: 0;
            font-size: 13px;
        }

        .stat-value {
            font-size: 14px;
            font-weight: 700;
            /* Destaque para o valor */
            display: block;
            /* Garante que Faturamento e Locações fiquem em linhas separadas */
        }

        /* --- Estilo para o Painel de Conteúdo Mensal --- */
        .custom-content-panel {
            padding: 10px 0;
            /* Remove padding lateral, mantém vertical */
            margin-top: 20px;
            /* Não usamos mais a borda, o visual será baseado nos cards internos */
        }

        /* Estilo para cada item de conteúdo mensal (CARD LIMPO) */
        .custom-month-item {
            /* Fundo branco e sombra leve para separação */
            background-color: white;
            padding: 10px;
            border-radius: 8px;
            /* Cantos arredondados */
            margin-bottom: 8px;
            /* Espaço entre os itens */
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            /* Sombra customizada */
        }

        .custom-month-item h5 {
            font-size: 1rem;
            font-weight: 500;
            color: #007bff;
            margin: 0;
        }

        .data-group {
            text-align: right;
            line-height: 1.2;
        }

        .data-group p {
            font-size: 12px;
            color: #495057;
            /* Texto mais suave */
            margin: 0;
        }

        /* Ajuste para o formulário de ano */
        .custom-select-group {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
        }

        .custom-select-group label {
            width: auto;
            margin-right: 15px;
            font-weight: 500;
        }

        .custom-select-group select {
            width: 50%;
            /* Um pouco mais compacto */
        }
    </style>
</head>

<body class="bg-light">
    <div class="custom-container">
        <h4 class="main-title">Evolução de Vendas Anual</h4>

        <div class="custom-select-group">
            <form id="form_ano" method="post" class="d-flex align-items-center w-100 justify-content-center">
                <input type="hidden" name="ano" id="ano-hidden" value="<?php echo $ano; ?>">
                <label for="ano-select" class="form-label text-muted">Selecione o Ano:</label>
                <select id="ano-select" name="ano" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php
                    foreach ($anosSelect as $anoDistinto) {
                        echo "<option value='$anoDistinto'" . ($ano == $anoDistinto ? ' selected' : '') . ">$anoDistinto</option>";
                    }
                    ?>
                </select>
            </form>
        </div>

        <div class="row g-3 mb-4">

            <div class="col-6">
                <div class="card month-stat-card shadow-sm rounded-3 bg-success-subtle border-0">
                    <div class="card-body p-3">
                        <h5 class="card-title text-success mb-1">Melhor Mês</h5>
                        <p>Faturamento: <span class="stat-value text-success">R$
                                <?php echo number_format($faturamentoMelhor, 2, ',', '.'); ?></span></p>
                        <p>Locações: <span class="stat-value text-success"><?php echo $locacoesMelhor; ?></span></p>
                    </div>
                </div>
            </div>

            <div class="col-6">
                <div class="card month-stat-card shadow-sm rounded-3 bg-danger-subtle border-0">
                    <div class="card-body p-3">
                        <h5 class="card-title text-danger mb-1">Pior Mês</h5>
                        <p>Faturamento: <span class="stat-value text-danger">R$
                                <?php echo number_format($faturamentoMenor, 2, ',', '.'); ?></span></p>
                        <p>Locações: <span class="stat-value text-danger"><?php echo $locacoesMenor; ?></span></p>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card month-stat-card shadow-sm rounded-3 bg-primary-subtle border-0">
                    <div class="card-body p-3">
                        <h5 class="card-title text-primary mb-1">Total Anual</h5>
                        <p>Faturamento: <span class="stat-value text-primary">R$
                                <?php echo number_format($faturamentoTotal, 2, ',', '.'); ?></span></p>
                        <p>Locações: <span class="stat-value text-primary"><?php echo $locacoesTotal; ?></span></p>
                    </div>
                </div>
            </div>

        </div>

        <div class="custom-content-panel" id="painelConteudo">
            <h5 class="text-center text-muted mb-3">Detalhes Mensais de <?php echo $ano; ?></h5>

            <?php
            // Define os nomes dos meses em português
            $meses = array(1 => "Janeiro", 2 => "Fevereiro", 3 => "Março", 4 => "Abril", 5 => "Maio", 6 => "Junho", 7 => "Julho", 8 => "Agosto", 9 => "Setembro", 10 => "Outubro", 11 => "Novembro", 12 => "Dezembro");

            // Loop pelos meses com locações registradas
            foreach ($contagemLocacoes['contagem'] as $mes => $total_locacoes) {
                if (isset($faturamentoPorMes[$mes]) && $total_locacoes > 0) {
                    // Usa a classe customizada para o card de item mensal
                    echo "<div class='custom-month-item'>";
                    echo "<h5>" . $meses[$mes] . "</h5>";
                    // Agrupamento para os dados
                    echo "<div class='data-group'>";
                    echo "<p>Faturamento: <strong>R$ " . number_format($faturamentoPorMes[$mes], 2, ',', '.') . "</strong></p>";
                    echo "<p>Locações: <strong>" . $total_locacoes . "</strong></p>";
                    echo "</div>";
                    echo "</div>";
                }
            }
            ?>
        </div>
    </div>

    <?php include 'menu.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
</body>

</html>