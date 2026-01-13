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

// Arquivos de funções de controle (devem existir no seu projeto)
include 'verificar_acesso.php';

// --- DEFINIÇÃO DO PERÍODO DE FILTRO ---

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

// Conectando ao banco de dados
$conexao = conectarAoBanco();
$paginaAtual = basename($_SERVER['PHP_SELF']);

// Verificar login e registrar acesso
verificarCookie($conexao, $paginaAtual);
verificarCargoUsuario(['admin', 'gerente']);

// Inicializa variáveis que serão usadas no HTML
$numVendido = 0;
$valorVendido = 0;
$totalLocacoes = 0;
$dias = 0; // Para calcular a diferença de dias no período

if ($conexao === null) {
    echo "<p>Erro na conexão com o banco de dados.</p>";
} else {
    $imagemCodificada = null;
    $caixas = dadosCaixa($conexao, $dataInicio, $dataFim);
    $idsCaixa = getIdCaixas($conexao, $dataInicio, $dataFim);

    // Calcula a diferença de dias no período
    $data1 = new DateTime($dataInicio);
    $data2 = new DateTime(substr($dataFim, 0, 10)); // Pega apenas a data
    $intervalo = $data1->diff($data2);
    $dias = $intervalo->days + 1;

    $produtosVendidos = carregaProdutosVendidos($conexao, $idsCaixa);

    // --- CORREÇÃO: CALCULAR TOTAIS DE PRODUTOS VENDIDOS ---
    foreach ($produtosVendidos as $produto) {
        $numVendido += $produto['quantidade']; // Soma a quantidade
        $valorVendido += ($produto['quantidade'] * $produto['valorUnd']); // Soma o valor total do produto
    }
    // --------------------------------------------------------

    $locacoes = carregarLocacoes($conexao, $idsCaixa);
    $locacoesJson = json_encode($locacoes);

    // Calcula o total de locações para o card
    foreach ($locacoes as $loc) {
        $totalLocacoes += $loc['total_locacoes'];
    }

    list($locacoesPorQuarto, $locacoesPorTipoQuarto) = obterLocacoesPorQuartoETipo($idsCaixa, $conexao);
}

// Garante que a conexão seja fechada.
if (isset($conexao) && $conexao !== null) {
    $conexao->close();
}

// -------------------- FIM DO BLOCO PRINCIPAL --------------------


// -------------------- FUNÇÕES AUXILIARES DE PROCESSAMENTO DE DADOS --------------------

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

function getIdCaixas($conexao, $dataInicio, $dataFim)
{
    $sql = "SELECT id FROM caixa WHERE horaabre >= ? AND horaabre <= ?";
    $idCaixas = array();
    $statement = null;

    try {
        $statement = $conexao->prepare($sql);
        $statement->bind_param("ss", $dataInicio, $dataFim);
        $statement->execute();
        $result = $statement->get_result();

        while ($row = $result->fetch_assoc()) {
            $idCaixas[] = $row['id'];
        }
    } catch (Exception $e) {
        // Tratar erro
    } finally {
        if (isset($statement)) {
            $statement->close();
        }
    }
    return $idCaixas;
}

function dadosCaixa($conexao, $dataInicio, $dataFim)
{
    $sql = "SELECT DAY(horaabre) as dia, MONTH(horaabre) as mes, saldofecha FROM caixa WHERE horaabre >= ? AND horaabre <= ?";
    $data = array();
    $statement = null;

    try {
        $statement = $conexao->prepare($sql);
        $statement->bind_param("ss", $dataInicio, $dataFim);
        $statement->execute();
        $result = $statement->get_result();

        while ($row = $result->fetch_assoc()) {
            $dataFormatada = $row['dia'] . '/' . $row['mes'];
            $data[] = array($dataFormatada, $row['saldofecha']);
        }
    } catch (Exception $e) {
        // Tratar erro
    } finally {
        if ($statement) {
            $statement->close();
        }
    }
    return $data;
}

// --- FUNÇÃO REFATORADA PARA REUTILIZAR A CONEXÃO ---
function carregaProdutosVendidos($conexao, $idsCaixa)
{
    $listaProdutos = array();
    if (empty($idsCaixa)) {
        return $listaProdutos;
    }

    $placeholders = implode(',', array_fill(0, count($idsCaixa), '?'));
    $consultaSQL = "SELECT idproduto, SUM(quantidade) AS quantidade_total, valorunidade FROM registravendido WHERE idcaixaatual IN ($placeholders) GROUP BY idproduto, valorunidade";

    $statement = null;
    try {
        $statement = $conexao->prepare($consultaSQL);

        // Faz o bind dos IDs de caixa como parâmetros da consulta preparada
        $tipos = str_repeat("i", count($idsCaixa));
        $statement->bind_param($tipos, ...$idsCaixa);

        $statement->execute();
        $resultado = $statement->get_result();

        while ($row = $resultado->fetch_assoc()) {
            $produto = array(
                'idProduto' => $row['idproduto'],
                // Passando a CONEXÃO aberta para a função getDescricao
                'descricao' => getDescricao($row['idproduto'], $conexao),
                'quantidade' => $row['quantidade_total'],
                'valorUnd' => $row['valorunidade'],
            );
            $listaProdutos[] = $produto;
        }
    } catch (Exception $e) {
        echo "<p>Ocorreu um erro ao carregar a lista de produtos vendidos: " . $e->getMessage() . "</p>";
    } finally {
        if (isset($statement)) {
            $statement->close();
        }
    }

    // Ordena a lista de produtos pela quantidade total vendida em ordem decrescente
    usort($listaProdutos, function ($a, $b) {
        return $b['quantidade'] - $a['quantidade'];
    });

    return $listaProdutos;
}

// --- FUNÇÃO REFATORADA PARA REUTILIZAR A CONEXÃO ---
function getDescricao($idPassado, $conexao)
{
    $consultaSQL = "SELECT descricao FROM produtos WHERE idproduto = ?";
    $resultado = null;
    $statement = null;

    try {
        $statement = $conexao->prepare($consultaSQL);
        if (!$statement) {
            throw new Exception("Erro ao preparar a consulta SQL: " . $conexao->error);
        }

        $statement->bind_param("i", $idPassado);

        if (!$statement->execute()) {
            throw new Exception("Erro ao executar a consulta SQL: " . $statement->error);
        }

        $resultado = $statement->get_result();

        if ($resultado && $resultado->num_rows > 0) {
            $row = $resultado->fetch_assoc();
            return $row['descricao'];
        } else {
            return null;
        }
    } catch (Exception $e) {
        return null;
    } finally {
        // Fecha APENAS o statement e o resultado (CONEXÃO continua aberta).
        if ($resultado) {
            $resultado->close();
        }
        if ($statement) {
            $statement->close();
        }
    }
}

function carregarLocacoes($conexao, $idsCaixa)
{
    if (empty($idsCaixa)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($idsCaixa), '?'));

    $consultaSQL = "SELECT 
                        DAY(caixa.horaabre) as dia, 
                        MONTH(caixa.horaabre) as mes,
                        caixa.saldofecha,
                        rl.total_locacoes
                    FROM 
                        caixa
                    JOIN 
                        (SELECT 
                            idcaixaatual, 
                            COUNT(*) as total_locacoes 
                        FROM 
                            registralocado 
                        WHERE 
                            idcaixaatual IN ($placeholders)
                        GROUP BY 
                            idcaixaatual) rl
                    ON 
                        caixa.id = rl.idcaixaatual
                    ORDER BY 
                        caixa.horaabre";

    $locacoesPorCaixa = [];
    $statement = null;

    try {
        $statement = $conexao->prepare($consultaSQL);
        $tipos = str_repeat("i", count($idsCaixa));
        $statement->bind_param($tipos, ...$idsCaixa);

        $statement->execute();
        $resultado = $statement->get_result();

        while ($row = $resultado->fetch_assoc()) {
            $locacoesPorCaixa[] = [
                'data' => $row['dia'] . '/' . $row['mes'],
                'total_locacoes' => $row['total_locacoes']
            ];
        }
    } catch (Exception $e) {
        // Tratar exceção
    } finally {
        if ($statement) {
            $statement->close();
        }
    }

    return $locacoesPorCaixa;
}

// --- FUNÇÃO REFATORADA PARA REUTILIZAR A CONEXÃO ---
function obterLocacoesPorQuartoETipo($idsCaixa, $conn)
{
    $locacoesPorQuarto = [];
    $locacoesPorTipoQuarto = [];
    if (empty($idsCaixa)) {
        return [$locacoesPorQuarto, $locacoesPorTipoQuarto];
    }

    // 1. Obter todas as locações e números de quarto em uma única consulta
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
        // Tratar erro
    } finally {
        if ($statementLocacoes) {
            $statementLocacoes->close();
        }
    }

    // 2. Contar locações por quarto (em PHP)
    $quartosEncontrados = [];
    foreach ($locacoesGerais as $row) {
        $numeroQuarto = $row['numquarto'];

        if (isset($locacoesPorQuarto[$numeroQuarto])) {
            $locacoesPorQuarto[$numeroQuarto]['total_locacoes']++;
        } else {
            $locacoesPorQuarto[$numeroQuarto] = [
                'numeroquarto' => $numeroQuarto,
                'total_locacoes' => 1
            ];
            $quartosEncontrados[] = $numeroQuarto;
        }
    }

    // 3. Obter o tipo de quarto em uma ÚNICA consulta para todos os quartos encontrados
    $locacoesPorQuartoArray = array_values($locacoesPorQuarto);

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

            // 4. Calcular locações por tipo de quarto (em PHP)
            foreach ($locacoesPorQuartoArray as $quarto) {
                $numeroQuarto = $quarto['numeroquarto'];
                $totalLocacoes = $quarto['total_locacoes'];
                // Usa o mapa para buscar o tipo de quarto
                $tipoQuarto = $tiposQuartosMap[$numeroQuarto] ?? 'Desconhecido';

                if (!isset($locacoesPorTipoQuarto[$tipoQuarto])) {
                    $locacoesPorTipoQuarto[$tipoQuarto] = 0;
                }
                $locacoesPorTipoQuarto[$tipoQuarto] += $totalLocacoes;
            }

        } catch (Exception $e) {
            // Tratar erro
        } finally {
            if ($statementTipos) {
                $statementTipos->close();
            }
        }
    }

    // Ordenar locacoesPorQuarto pelo número do quarto (final)
    usort($locacoesPorQuartoArray, function ($a, $b) {
        return $a['numeroquarto'] - $b['numeroquarto'];
    });

    return [$locacoesPorQuartoArray, $locacoesPorTipoQuarto];
}

// As funções imprimirTabelaLocacoesPorQuarto e imprimirTabelaLocacoesPorTipoQuarto
// foram mantidas aqui, mas não são usadas no fluxo principal que gera o HTML.

function imprimirTabelaLocacoesPorQuarto($locacoesPorQuarto)
{
    // Função de comparação para ordenar por total_locacoes de forma decrescente
    usort($locacoesPorQuarto, function ($a, $b) {
        return $b['total_locacoes'] - $a['total_locacoes'];
    });

    echo "<h3>Locações por Quarto </h3>";
    echo "<table border='1'>";
    echo "<tr><th>Número do Quarto</th><th>Total de Locações</th></tr>";

    foreach ($locacoesPorQuarto as $quarto) {
        if ($quarto['total_locacoes'] > 0) {
            echo "<tr>";
            echo "<td>{$quarto['numeroquarto']}</td>";
            echo "<td>{$quarto['total_locacoes']}</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
}


function imprimirTabelaLocacoesPorTipoQuarto($locacoesPorTipoQuarto)
{
    echo "<h3>Locações por Tipo de Quarto</h3>";
    echo "<table border='1'>";
    echo "<tr><th>Tipo de Quarto</th><th>Total de Locações</th></tr>";
    foreach ($locacoesPorTipoQuarto as $tipoQuarto => $totalLocacoes) {
        echo "<tr>";
        echo "<td>$tipoQuarto</td>";
        echo "<td>$totalLocacoes</td>";
        echo "</tr>";
    }
    echo "</table>";
}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demonstrativo Gráfico</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <style>
        body {
            background-color: #f8f9fa;
            /* Cor de fundo Bootstrap light gray */
            font-family: Arial, sans-serif;
        }

        /* Classes para esconder/mostrar abas (mantidas para compatibilidade com seu JS) */
        .conteudo-aba {
            display: none;
        }

        .conteudo-aba.ativo {
            display: block;
        }

        /* Estilo da aba ativa usa as classes 'active' e 'nav-link' do Bootstrap no JS */

        .card-title {
            font-size: 0.9rem;
            /* Título dos cards de total menor e mais discreto */
            font-weight: 600;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
</head>

<body>
    <div class="container my-4 p-4 bg-white border rounded shadow-lg">
        <h3 class="text-center mb-4 text-primary">Demonstrativo Gráfico</h3>

        <div class="options">
            <form method="post" action="demonstrativo_grafico.php"
                class="row g-3 align-items-center justify-content-center">
                <div class="col-auto">
                    <label for="period" class="col-form-label fw-bold">Selecione o período:</label>
                </div>
                <div class="col-md-4 col-lg-3">
                    <select name="period" id="period" class="form-select">
                        <option value="7" <?php echo (isset($_POST['period']) && $_POST['period'] == '7') ? 'selected' : ''; ?>>Últimos 7 dias</option>
                        <option value="15" <?php echo (isset($_POST['period']) && $_POST['period'] == '15') ? 'selected' : ''; ?>>Últimos 15 dias</option>
                        <option value="30" <?php echo (isset($_POST['period']) && $_POST['period'] == '30') ? 'selected' : ''; ?>>Últimos 30 dias</option>
                        <option value="this_month" <?php echo (!isset($_POST['period']) || $_POST['period'] == 'this_month') ? 'selected' : ''; ?>>Este Mês</option>
                        <option value="last_month" <?php echo (isset($_POST['period']) && $_POST['period'] == 'last_month') ? 'selected' : ''; ?>>Mês Passado</option>
                        <option value="custom" <?php echo (isset($_POST['period']) && $_POST['period'] == 'custom') ? 'selected' : ''; ?>>Definir um Período</option>
                    </select>
                </div>

                <div id="custom_period" class="col-12 row g-3 justify-content-center mt-2"
                    style="display: <?php echo (isset($_POST['period']) && $_POST['period'] == 'custom') ? 'flex' : 'none'; ?>;">
                    <div class="col-md-3">
                        <label for="data_inicio" class="form-label">Data de Início:</label>
                        <input type="text" id="data_inicio" name="data_inicio" class="form-control"
                            value="<?php echo isset($_POST['data_inicio']) ? htmlspecialchars($_POST['data_inicio']) : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="data_fim" class="form-label">Data de Fim:</label>
                        <input type="text" id="data_fim" name="data_fim" class="form-control"
                            value="<?php echo isset($_POST['data_fim']) ? htmlspecialchars($_POST['data_fim']) : ''; ?>">
                    </div>
                </div>

                <div class="col-auto mt-3">
                    <button type="submit" class="btn btn-primary">Enviar</button>
                </div>
            </form>
        </div>

        <hr class="my-4">
        <div class="conteudo">
            <div class="abas d-flex justify-content-center nav nav-pills mb-3">
                <button class="nav-link" id="aba-caixas">Caixas</button>
                <button class="nav-link" id="aba-produtos">Produtos Vendidos</button>
                <button class="nav-link" id="aba-locacoes">Locações</button>
            </div>

            <div class="conteudo-abas card card-body p-3 border-0">

                <div id="conteudo-caixas" class="conteudo-aba ativo">
                    <canvas id="grafico-caixas" style="height: 300px;"></canvas>
                    <h5 class="mt-4 mb-3 text-center text-secondary">Dados de Fechamento de Caixa</h5>

                    <div class="table-responsive mt-3">
                        <table id="tabela-caixas" class="table table-striped table-hover table-sm text-center">
                            <thead class="table-primary">
                                <tr>
                                    <th scope="col">Data</th>
                                    <th scope="col">Valor Fechamento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($caixas as $caixa): ?>
                                    <tr>
                                        <td><?php echo $caixa[0]; ?></td>
                                        <td><?php echo 'R$ ' . number_format((float) $caixa[1], 2, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="conteudo-produtos" class="conteudo-aba">
                    <div class="mb-4" style="height: 400px;">
                        <canvas id="grafico-produtos"></canvas>
                    </div>

                    <div class="row text-center mt-4 mb-4">
                        <div class="col-md-6 mb-3">
                            <div class="card border-primary h-100 shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title text-primary">Total de Produtos Vendidos</h5>
                                    <p class="card-text fs-3 fw-bold"><?php echo $numVendido; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card border-success h-100 shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title text-success">Valor Total de Vendas</h5>
                                    <p class="card-text fs-3 fw-bold">
                                        <?php echo "R$ " . number_format($valorVendido, 2, ',', '.'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="mb-3 text-center text-secondary">Detalhes dos Produtos Vendidos</h5>
                    <div id="Vendidos" class="table-responsive">
                        <table class="table table-striped table-hover table-sm text-center">
                            <thead class="table-success">
                                <tr>
                                    <th scope="col">Produto</th>
                                    <th scope="col">Qntd Vendida</th>
                                    <th scope="col">Valor Und</th>
                                    <th scope="col">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($produtosVendidos as $produto) {
                                    echo "<tr>";
                                    echo "<td>{$produto['descricao']}</td>";
                                    echo "<td>{$produto['quantidade']}</td>";
                                    echo "<td>" . "R$ " . number_format((float) $produto['valorUnd'], 2, ',', '.') . "</td>";
                                    echo "<td>R$ " . number_format((float) $produto['valorUnd'] * (float) $produto['quantidade'], 2, ',', '.') . "</td>";
                                    echo "</tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="conteudo-locacoes" class="conteudo-aba">
                    <div class="mb-4" style="height: 400px;">
                        <canvas id="grafico-locacoes"></canvas>
                    </div>

                    <div class="row text-center mt-4 mb-4">
                        <div class="col-md-6 mb-3">
                            <div class="card border-info h-100 shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title text-info">Total de Dias no Período</h5>
                                    <p class="card-text fs-3 fw-bold"><?php echo $dias; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card border-warning h-100 shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title text-warning">Número Total de Locações</h5>
                                    <p class="card-text fs-3 fw-bold"><?php echo $totalLocacoes; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="mb-3 text-center text-secondary">Locações por Data</h5>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-sm text-center">
                            <thead class="table-info">
                                <tr>
                                    <th scope="col">Data</th>
                                    <th scope="col">Nº Locações</th>
                                    <th scope="col">Data</th>
                                    <th scope="col">Nº Locações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                for ($i = 0; $i < count($locacoes); $i++) {
                                    if ($i % 2 == 0) {
                                        echo "<tr>";
                                        echo "<td>{$locacoes[$i]['data']}</td>";
                                        echo "<td>{$locacoes[$i]['total_locacoes']}</td>";
                                    } else {
                                        echo "<td>{$locacoes[$i]['data']}</td>";
                                        echo "<td>{$locacoes[$i]['total_locacoes']}</td>";
                                        echo "</tr>";
                                    }
                                }
                                if (count($locacoes) % 2 != 0) {
                                    echo "<td></td><td></td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <hr class="my-4">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <h5 class="text-center text-secondary">Locações por Quarto</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm text-center table-hover shadow-sm">
                                    <thead class="table-warning">
                                        <tr>
                                            <th scope="col">Número do Quarto</th>
                                            <th scope="col">Total de Locações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        usort($locacoesPorQuarto, function ($a, $b) {
                                            return $b['total_locacoes'] - $a['total_locacoes'];
                                        });
                                        foreach ($locacoesPorQuarto as $quarto) {
                                            if ($quarto['total_locacoes'] > 0) {
                                                echo "<tr><td>{$quarto['numeroquarto']}</td><td>{$quarto['total_locacoes']}</td></tr>";
                                            }
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <h5 class="text-center text-secondary">Locações por Tipo de Quarto</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm text-center table-hover shadow-sm">
                                    <thead class="table-danger">
                                        <tr>
                                            <th scope="col">Tipo de Quarto</th>
                                            <th scope="col">Total de Locações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($locacoesPorTipoQuarto as $tipoQuarto => $totalLocacoes) {
                                            echo "<tr><td>$tipoQuarto</td><td>$totalLocacoes</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <script>
        $(function () {
            // Lógica para mostrar/esconder o período customizado
            $("#period").change(function () {
                if ($(this).val() == 'custom') {
                    $("#custom_period").show();
                } else {
                    $("#custom_period").hide();
                }
            });

            // Inicializa o estado se a opção "custom" estiver selecionada no carregamento da página
            if ($("#period").val() == 'custom') {
                $("#custom_period").show();
            }

            // Datepicker
            $("#data_inicio, #data_fim").datepicker({
                dateFormat: 'dd/mm/yy' // Formato de exibição da data
            });

            // Lógica das Abas: Adiciona a classe 'active' do Bootstrap e sua classe 'ativo'
            $(".abas button").click(function () {
                // Remove a classe 'active' do Bootstrap e 'aba-ativa' customizada de todos
                $(".abas button").removeClass("active");
                $(".conteudo-aba").removeClass("ativo");

                // Adiciona as classes ativas do Bootstrap e sua classe 'ativo'
                $(this).addClass("active");
                $("#" + $(this).attr("id").replace("aba-", "conteudo-")).addClass("ativo");
            });

            // Inicializa a primeira aba como ativa no carregamento
            $("#aba-caixas").addClass("active");
        });
    </script>

    <script>
        var caixas = <?php echo json_encode($caixas); ?>;
        var locacoes = <?php echo json_encode($locacoes); ?>;

        $(document).ready(function () {
            // Chamar as funções de gráfico
            gerarGraficoLocacoes(locacoes);
            gerarGraficoProdutos(<?php echo json_encode($produtosVendidos); ?>);
            gerarGraficoCaixa();

            // Funções para gerar os gráficos
            function gerarGraficoProdutos(produtosVendidos) {
                var labels = [];
                var dataQuantidade = [];
                var cores = [
                    'rgba(255, 99, 132, 0.8)', 'rgba(54, 162, 235, 0.8)', 'rgba(255, 206, 86, 0.8)',
                    'rgba(75, 192, 192, 0.8)', 'rgba(153, 102, 255, 0.8)', 'rgba(255, 159, 64, 0.8)',
                    'rgba(255, 69, 0, 0.8)', 'rgba(255, 140, 0, 0.8)', 'rgba(255, 0, 255, 0.8)',
                    'rgba(0, 0, 255, 0.8)', 'rgba(0, 255, 0, 0.8)', 'rgba(255, 192, 203, 0.8)',
                    'rgba(255, 182, 193, 0.8)', 'rgba(255, 105, 180, 0.8)', 'rgba(160, 32, 240, 0.8)',
                    'rgba(0, 139, 139, 0.8)', 'rgba(0, 128, 0, 0.8)', 'rgba(0, 255, 255, 0.8)',
                    'rgba(255, 255, 0, 0.8)', 'rgba(255, 255, 224, 0.8)', 'rgba(240, 230, 140, 0.8)',
                    'rgba(255, 215, 0, 0.8)', 'rgba(189, 183, 107, 0.8)', 'rgba(128, 0, 0, 0.8)',
                    'rgba(139, 69, 19, 0.8)'
                ];

                produtosVendidos.forEach(function (produto) {
                    labels.push(produto.descricao);
                    dataQuantidade.push(produto.quantidade);
                });

                var ctx = document.getElementById('grafico-produtos').getContext('2d');
                var graficoProdutos = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: dataQuantidade,
                            backgroundColor: cores
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: 'Quantidade Vendida por Produto'
                            }
                        }
                    }
                });
            }

            function gerarGraficoLocacoes(locacoes) {
                locacoes.sort((a, b) => new Date(a.data_abertura) - new Date(b.data_abertura));

                var labels = locacoes.map(locacao => locacao.data);
                var dataLocacoes = locacoes.map(locacao => locacao.total_locacoes);

                var ctx = document.getElementById('grafico-locacoes').getContext('2d');
                var graficoLocacoes = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Total de Locações',
                            data: dataLocacoes,
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 2,
                            fill: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'Data'
                                }
                            },
                            y: {
                                title: {
                                    display: true,
                                    text: 'Total de Locações'
                                },
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            function gerarGraficoCaixa() {
                var labels = caixas.map(function (item) {
                    return item[0];
                });

                var dataValores = caixas.map(function (item) {
                    return item[1];
                });

                var ctx = document.getElementById('grafico-caixas').getContext('2d');
                var graficoCaixas = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Saldo de Fechamento',
                            data: dataValores,
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 2,
                            fill: false
                        }]
                    },
                    options: {
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'Data'
                                }
                            },
                            y: {
                                title: {
                                    display: true,
                                    text: 'Valor'
                                },
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

    <?php include 'menu.php'; ?>
</body>

</html>