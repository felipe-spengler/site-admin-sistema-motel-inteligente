<?php

// ** Manter as suas funções PHP originais **

function obterIdCaixaAberto($conexao) {
    $sql = "SELECT id FROM caixa WHERE horafecha IS NULL";
    $result = $conexao->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $result->close(); // Liberando o resultado
        return $row['id'];
    } else {
        return null;
    }
}
function getDescricao($idPassado) {
    // É recomendado que 'conectarAoBanco()' esteja definido em algum 'include'
    // Mas para o exemplo funcionar, vou assumir que está definido.
    $conexao = conectarAoBanco(); 
    $consultaSQL = "SELECT descricao FROM produtos WHERE idproduto = ?";
    
    try {
        $statement = $conexao->prepare($consultaSQL);
        $statement->bind_param("i", $idPassado);
        
        $statement->execute();
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
        // Verifica se $statement foi inicializado antes de fechar
        if (isset($statement) && $statement instanceof mysqli_stmt) {
             $statement->close();
        }
        // Fechamento da conexão para a função
        $conexao->close();
    }
}
function dataSemAno($data) {
    // Cria um objeto DateTime a partir da string de data
    $dataObj = new DateTime($data);

    // Formata a data no formato desejado
    return $dataObj->format('d/m H:i');
}
function carregaProdutosVendidos($conexao, $idCaixa) {
    $listaProdutos = array();

    $consultaSQL = "SELECT idproduto, SUM(quantidade) AS quantidade_total, valorunidade FROM registravendido WHERE idcaixaatual = ? GROUP BY idproduto, valorunidade";

    try {
        $statement = $conexao->prepare($consultaSQL);
        $statement->bind_param("i", $idCaixa);
        $statement->execute();
        $resultado = $statement->get_result();

        while ($row = $resultado->fetch_assoc()) {
            $produto = array(
                'idProduto' => $row['idproduto'],
                'quantidade' => $row['quantidade_total'],
                'valorUnd' => $row['valorunidade'],
            );

            $listaProdutos[] = $produto;
        }

        $statement->close(); // Liberando o statement
    } catch (Exception $e) {
        // Logar o erro
    }

    return $listaProdutos;
}

function formatarMoeda($valor) {
    return "R$ " . number_format($valor, 2, ',', '.');
}

// ** Manter a lógica de includes e verificação de acesso **
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
    default:
        die("Filial inválida.");
}

// Abre conexão (necessária para as próximas verificações)
$conexao = conectarAoBanco();

// (Opcional) também pode verificar se o usuário está logado
if (!isset($_COOKIE["usuario_nome"])) {
    header("Location: index.php");
    exit();
}


$paginaAtual = basename($_SERVER['PHP_SELF']);
// verificarCookie($conexao, $paginaAtual); 
// verificarCargoUsuario(['admin', 'gerente']); 

$idCaixa = obterIdCaixaAberto($conexao);
$valQ = 0;
$valC = 0;
$numVendido = 0;
$valorVendido = 0;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dados do Caixa - <?php echo ucfirst($filial); ?></title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        /* Estilo para garantir que o contêiner de rolagem seja visível em telas pequenas */
        .table-responsive {
            margin-bottom: 1rem;
            border: 1px solid #dee2e6;
        }
        /* Ajuste fino para os cards de total */
        .card-total-valor {
            border: 1px solid #ccc;
            background-color: #fff; 
        }
        .card-total-valor h6 {
            color: #6c757d; 
        }
        /* Custom font size for small screens to fit more data */
        .table-sm-responsive td, .table-sm-responsive th {
            font-size: 0.75rem; /* ~12px, menor que o padrão */
        }
        /* Garantir que a seta fique pequena e centralizada */
        .indicator-icon {
            font-size: 0.9rem;
            vertical-align: middle;
        }
        /* Estilo para o Nav-Pills como container */
        .nav-pills-container {
            background-color: #fff;
            padding: 10px;
            border-radius: 0.25rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
    </style>
</head>
<body class="bg-light">

<div class="container mt-4 mb-5">
    <h3 class="text-center mb-4 text-primary border-bottom pb-2">Dados do Caixa</h3>

    <div class="nav-pills-container mb-3">
        <ul class="nav nav-pills nav-justified" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="locacoes-tab" data-bs-toggle="pill" data-bs-target="#locacoes-pane" type="button" role="tab" aria-controls="locacoes-pane" aria-selected="true">Locações</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="vendidos-tab" data-bs-toggle="pill" data-bs-target="#vendidos-pane" type="button" role="tab" aria-controls="vendidos-pane" aria-selected="false">Produtos Vendidos</button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="myTabContent">
        
        <div class="tab-pane fade show active" id="locacoes-pane" role="tabpanel" aria-labelledby="locacoes-tab" tabindex="0">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white text-dark fw-bold">
                    Detalhes das Locações
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover table-bordered mb-0 table-sm-responsive">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="text-nowrap">Início</th>
                                    <th scope="col" class="text-nowrap">Fim</th> 
                                    <th scope="col">Quarto</th>
                                    <th scope="col" class="text-end text-nowrap">V. Qto</th>
                                    <th scope="col" class="text-end text-nowrap">V. Cons.</th>
                                    <th scope="col" class="text-center">A/D</th> 
                                    <th scope="col" class="text-end text-nowrap">V. Total</th>
                                    <th scope="col" class="text-end text-danger d-none d-md-table-cell text-nowrap">Desc. (R$)</th>
                                    <th scope="col" class="text-end text-success d-none d-md-table-cell text-nowrap">Acrés. (R$)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    // NOVA QUERY MAIS SIMPLES E CONFIÁVEL
                                    $consultaSQL = "
                                        SELECT 
                                            rl.idlocacao,
                                            rl.horainicio,
                                            rl.horafim,
                                            rl.numquarto,
                                            rl.valorquarto,
                                            rl.valorconsumo,
                                            COALESCE(SUM(CASE WHEN j.tipo = 'acrescimo' THEN j.valor ELSE 0 END), 0) AS total_acrescimo,
                                            COALESCE(SUM(CASE WHEN j.tipo = 'desconto' THEN j.valor ELSE 0 END), 0) AS total_desconto
                                        FROM registralocado rl
                                        LEFT JOIN justificativa j ON rl.idlocacao = j.idlocacao
                                        WHERE rl.idcaixaatual = ?
                                        GROUP BY rl.idlocacao, rl.horainicio, rl.horafim, rl.numquarto, rl.valorquarto, rl.valorconsumo
                                        ORDER BY rl.horainicio
                                    ";
                                    
                                    $totalDesconto = 0;
                                    $totalAcrescimo = 0;
                                    
                                    try {
                                        $statement = $conexao->prepare($consultaSQL);
                                        $statement->bind_param("i", $idCaixa);
                                        $statement->execute();
                                        $resultado = $statement->get_result();
                                    
                                        while ($row = $resultado->fetch_assoc()) {
                                            $valQ += $row['valorquarto'];
                                            $valC += $row['valorconsumo'];
                                    
                                            $acrescimo = $row['total_acrescimo'];
                                            $desconto = $row['total_desconto'];
                                    
                                            $totalAcrescimo += $acrescimo;
                                            $totalDesconto += $desconto;
                                    
                                            // Indicador A/D
                                            if ($acrescimo > 0 && $desconto > 0) {
                                                $indicador_html = '<i class="bi bi-arrow-up-down text-warning indicator-icon" title="Acréscimo: '.formatarMoeda($acrescimo).', Desconto: '.formatarMoeda($desconto).'"></i>';
                                            } elseif ($acrescimo > 0) {
                                                $indicador_html = '<i class="bi bi-arrow-up-circle-fill text-success indicator-icon" title="Acréscimo: '.formatarMoeda($acrescimo).'"></i>';
                                            } elseif ($desconto > 0) {
                                                $indicador_html = '<i class="bi bi-arrow-down-circle-fill text-danger indicator-icon" title="Desconto: '.formatarMoeda($desconto).'"></i>';
                                            } else {
                                                $indicador_html = '';
                                            }
                                    
                                            $total = $row['valorquarto'] + $row['valorconsumo'] + $acrescimo - $desconto;
                                    
                                            echo "<tr>";
                                            echo "<td class='text-nowrap'>" . dataSemAno($row['horainicio']) . "</td>";
                                            echo "<td class='text-nowrap'>" . ($row['horafim'] ? dataSemAno($row['horafim']) : '-') . "</td>";
                                            echo "<td>{$row['numquarto']}</td>";
                                            echo "<td class='text-end text-nowrap'>" . formatarMoeda($row['valorquarto']) . "</td>";
                                            echo "<td class='text-end text-nowrap'>" . formatarMoeda($row['valorconsumo']) . "</td>";
                                            echo "<td class='text-center'>{$indicador_html}</td>";
                                            echo "<td class='text-end fw-bold text-primary text-nowrap'>" . formatarMoeda($total) . "</td>";
                                            echo "<td class='text-end text-danger d-none d-md-table-cell text-nowrap'>" . formatarMoeda($desconto) . "</td>";
                                            echo "<td class='text-end text-success d-none d-md-table-cell text-nowrap'>" . formatarMoeda($acrescimo) . "</td>";
                                            echo "</tr>";
                                        }
                                        $statement->close();
                                    } catch (Exception $e) {
                                        echo "<tr><td colspan='9' class='text-center text-danger'>Erro ao carregar locações: " . $e->getMessage() . "</td></tr>";
                                    }
                                    ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <h5 class="mt-4 mb-3 text-secondary border-bottom pb-1">Resumo Financeiro</h5>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="card card-total-valor text-center shadow-sm">
                        <div class="card-body p-2">
                            <h6 class="card-title mb-0">Quartos</h6>
                            <p class="card-text fs-6 fw-bold text-dark mb-0"><?php echo formatarMoeda($valQ); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-total-valor text-center shadow-sm">
                        <div class="card-body p-2">
                            <h6 class="card-title mb-0">Consumo</h6>
                            <p class="card-text fs-6 fw-bold text-dark mb-0"><?php echo formatarMoeda($valC); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-total-valor text-center shadow-sm">
                        <div class="card-body p-2">
                            <h6 class="card-title mb-0">Desc. (-)</h6>
                            <p class="card-text fs-6 fw-bold text-danger mb-0"><?php echo formatarMoeda($totalDesconto); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-total-valor text-center shadow-sm">
                        <div class="card-body p-2">
                            <h6 class="card-title mb-0">Acrés. (+)</h6>
                            <p class="card-text fs-6 fw-bold text-success mb-0"><?php echo formatarMoeda($totalAcrescimo); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-12 text-center mt-3">
                    <div class="card bg-primary text-white shadow-lg">
                        <div class="card-body py-2">
                            <h5 class="card-title mb-1">VALOR TOTAL DO CAIXA</h5>
                            <p class="card-text fs-3 fw-bolder mb-0"><?php echo formatarMoeda($valQ + $valC + $totalAcrescimo - $totalDesconto); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="vendidos-pane" role="tabpanel" aria-labelledby="vendidos-tab" tabindex="0">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white text-dark fw-bold">
                    Produtos Vendidos
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover table-bordered mb-0 table-sm-responsive">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Produto</th>
                                    <th scope="col" class="text-center">Qtd</th>
                                    <th scope="col" class="text-end d-none d-md-table-cell text-nowrap">Valor Unitário</th> 
                                    <th scope="col" class="text-end text-nowrap">Valor Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $produtosVendidos = carregaProdutosVendidos($conexao, $idCaixa);

                                foreach ($produtosVendidos as $produto) {
                                    $totalItem = $produto['valorUnd'] * $produto['quantidade'];
                                    echo "<tr>";
                                    echo "<td>" . getDescricao($produto['idProduto']) . "</td>";
                                    echo "<td class='text-center'>" . $produto['quantidade'] . "</td>";
                                    echo "<td class='text-end d-none d-md-table-cell text-nowrap'>" . formatarMoeda($produto['valorUnd']) . "</td>";
                                    echo "<td class='text-end fw-bold text-nowrap'>" . formatarMoeda($totalItem) . "</td>";
                                    echo "</tr>";
                                    $numVendido += $produto['quantidade'];
                                    $valorVendido += $totalItem;
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 justify-content-center">
                <div class="col-6 col-md-4">
                    <div class="card card-total-valor text-center shadow-sm">
                        <div class="card-body p-2">
                            <h6 class="card-title mb-0">Itens Vendidos</h6>
                            <p class="card-text fs-6 fw-bold text-dark mb-0"><?php echo $numVendido; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="card bg-success text-white text-center shadow-sm">
                        <div class="card-body p-2">
                            <h6 class="card-title mb-0">TOTAL VENDIDO</h6>
                            <p class="card-text fs-6 fw-bolder mb-0"><?php echo formatarMoeda($valorVendido); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<?php 
// Fecha a conexão com o banco de dados se não for mais utilizada
if (isset($conexao) && $conexao instanceof mysqli) {
    $conexao->close();
}
include 'menu.php'; 
?> 

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

</body>
</html>