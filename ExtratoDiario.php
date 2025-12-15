<?php
// Função para formatar a data no formato MySQL (Y-m-d H:i:s)
function formatarData($data, $horaInicial = true) {
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

function obterIdCaixa($conexao, $dataInicio, $dataFim) {
    
    $sql = "SELECT id FROM caixa WHERE horaabre >= '$dataInicio' AND horaabre <= '$dataFim'";
    $result = $conexao->query($sql);

    if ($result) {
        $idCaixas = array();

        while ($row = $result->fetch_assoc()) {
            $idCaixas[] = $row['id'];
        }

        return $idCaixas;
    } else {
        return []; // Retorna um array vazio em caso de erro/falha
    }
}

function calcularValorELocacoes($conexao, $idCaixas) {
    $resultados = array();

    foreach ($idCaixas as $idCaixa) {
        // Obter o valor de fechamento da caixa
        $sqlValor = "SELECT * FROM caixa WHERE id = $idCaixa";
        $resultValor = $conexao->query($sqlValor);
        
        if ($resultValor && $resultValor->num_rows > 0) {
            $rowValor = $resultValor->fetch_assoc();
            $valorFechamento = $rowValor['saldofecha'] !== null ? $rowValor['saldofecha'] : null;
            $horaAbertura = $rowValor['horaabre'];
            
            // Formatando a data de abertura
            $dataAbre = date("d/m/y", strtotime($horaAbertura));
    
            // Contar o número de locações e somar os valores de pagamento
            $sqlLocacoes = "SELECT COUNT(*) AS total, SUM(pagodinheiro) AS somaDinheiro, SUM(pagopix) AS somaPix, SUM(pagocartao) AS somaCartao 
                            FROM registralocado WHERE idcaixaatual = $idCaixa";
            $resultLocacoes = $conexao->query($sqlLocacoes);
    
            if ($resultLocacoes && $resultLocacoes->num_rows > 0) {
                $rowLocacoes = $resultLocacoes->fetch_assoc();
                $totalLocacoes = $rowLocacoes['total'];
                if ($valorFechamento === null) {
                    $valorFechamento = $rowLocacoes['somaDinheiro'] + $rowLocacoes['somaPix'] + $rowLocacoes['somaCartao'];
                }
            } else {
                $totalLocacoes = 0;
            }
    
            // Adicionar os resultados à matriz
            $resultados[] = array(
                'id_caixa' => $idCaixa,
                'valor_fechamento' => (float)$valorFechamento, // Garantir que é um float
                'total_locacoes' => (int)$totalLocacoes, // Garantir que é um inteiro
                'data' => $dataAbre,
            );
        }
    }

    return $resultados;
}


function carregarLocacoes($conexao, $idCaixas) {
    $locacoes = [];
    foreach ($idCaixas as $idCaixa) {
        $consultaSQL = "SELECT rl.*, j.valor AS valor_justificativa, j.tipo 
                        FROM registralocado rl 
                        LEFT JOIN justificativa j ON rl.idlocacao = j.idlocacao 
                        WHERE rl.idcaixaatual = ? ORDER BY rl.horainicio";

        $statement = $conexao->prepare($consultaSQL);
        $statement->bind_param("i", $idCaixa);
        $statement->execute();
        $resultadoLocacoes = $statement->get_result();

        while ($row = $resultadoLocacoes->fetch_assoc()) {
            // Calculando valores
            $acrescimo = $row['tipo'] === 'acrescimo' ? (float)$row['valor_justificativa'] : 0.00;
            $desconto = $row['tipo'] === 'desconto' ? (float)$row['valor_justificativa'] : 0.00;
            
            $valorQuarto = (float)$row['valorquarto'];
            $valorConsumo = (float)$row['valorconsumo'];

            $total = $valorQuarto + $valorConsumo + $acrescimo - $desconto;

            $locacoes[$idCaixa][] = [
                'horainicio' => $row['horainicio'],
                'horafim' => $row['horafim'],
                'numquarto' => $row['numquarto'],
                'valorquarto' => $valorQuarto,
                'valorconsumo' => $valorConsumo,
                'desconto' => $desconto,
                'acrescimo' => $acrescimo,
                'total' => $total
            ];
        }
        $statement->close();
    }
    return $locacoes;
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
                $dataFim = date('Y-m-t') . ' 23:59:59';
            }
            break;
        default:
            // Se nenhum dos casos corresponder, assume-se "Este Mês"
            $dataInicio = date('Y-m-01') . ' 00:00:00';
            $dataFim = date('Y-m-t') . ' 23:59:59';
            break;
    }
} else {
    // Se não foi recebido nenhum valor, assume-se "Este Mês"
    $dataInicio = date('Y-m-01') . ' 00:00:00';
    $dataFim = date('Y-m-t') . ' 23:59:59';
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
    default:
        die("Filial inválida.");
}
    
    
    $conexao = conectarAoBanco();
    
    $paginaAtual = basename($_SERVER['PHP_SELF']);
    verificarCookie($conexao, $paginaAtual);
    verificarCargoUsuario(['admin']);
if ($conexao === null) {
    // Você pode querer exibir uma mensagem de erro mais amigável aqui
    $resultado = []; 
    $locacoes = []; 
} else {
    $idCaixas = obterIdCaixa($conexao, $dataInicio, $dataFim);
    $resultado = calcularValorELocacoes($conexao, $idCaixas) ;
    $locacoes = carregarLocacoes($conexao, $idCaixas); // Carrega as locações

}
// A conexão só deve ser fechada se ela foi aberta com sucesso
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

    <link rel="stylesheet" href="//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>

    <style>
        body {
            background-color: #f8f9fa; /* Fundo light do Bootstrap */
        }
        .container {
            padding-top: 20px;
        }
        /* Estilo para o Card de resumo diário */
        .month-content {
            margin-bottom: 15px;
        }
        /* Ajuste para o título do extrato */
        .page-title {
            color: #0d6efd; /* Azul primário do Bootstrap */
            margin-bottom: 25px;
        }
    </style>
</head>
<body>
    <script>
        // Variável JS com os dados de locação (mantida)
        const locacoes = <?php echo json_encode($locacoes); ?>;
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
                    <div class="card shadow-sm month-content h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-primary text-center"><?php echo $res['data']; ?></h5>
                            <p class="card-text text-center fw-bold">
                                <span class="text-muted">R$:</span>
                                <?php echo number_format($res['valor_fechamento'], 2, ',', '.'); ?> 
                                | 
                                <span class="text-muted">Locações:</span>
                                <?php echo $res['total_locacoes']; ?>
                            </p>
                            
                            <button onclick="exibirLocacoesNoModal(<?php echo $res['id_caixa']; ?>, '<?php echo $res['data']; ?>')" class="btn btn-outline-primary btn-sm w-100 mt-auto">
                                Ver Locações
                            </button>
                            </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div> 
    </div> 

    <div class="modal fade" id="locacoesModal" tabindex="-1" aria-labelledby="locacoesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="locacoesModalLabel">Detalhes das Locações - <span id="modalData"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(function() {
            // Lógica para mostrar/esconder campos de período customizado (Mantida)
            $("#period").change(function() {
                if ($(this).val() == 'custom') {
                    $("#custom_period").removeClass('d-none');
                } else {
                    $("#custom_period").addClass('d-none');
                }
            });

            // Inicialização do DatePicker (Mantida)
            $("#data_inicio, #data_fim").datepicker({
                dateFormat: 'dd/mm/yy',
                dayNames: ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'],
                dayNamesMin: ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'],
                monthNames: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
                monthNamesShort: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                nextText: 'Próximo',
                prevText: 'Anterior'
            });
            
            // Função formatarData (Mantida para fins de referência)
            function formatarData(data) {
                var partes = data.split("/");
                return partes[2] + "-" + partes[1] + "-" + partes[0] + " 00:00:00";
            }
        });

        // Funções de formatação (Mantidas)
        function formatarMoeda(valor) {
            return "R$ " + parseFloat(valor).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ".");
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
        
        /**
         * Preenche o Modal com os detalhes das locações de um caixa e o exibe.
         * @param {number} idCaixa O ID do caixa para buscar as locações na variável 'locacoes'.
         * @param {string} dataCaixa A data do caixa para exibir no título do modal.
         */
        function exibirLocacoesNoModal(idCaixa, dataCaixa) {
            const modalTitleSpan = document.getElementById('modalData');
            const tabelaBody = document.getElementById('modalLocacoesBody');
            
            // Limpa o corpo da tabela antes de preencher
            tabelaBody.innerHTML = '';
            
            // Atualiza o título do modal
            modalTitleSpan.textContent = dataCaixa;

            // Preenche a tabela
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
                        <td>${formatarMoeda(locacao.total)}</td>`;
                });
            } else {
                const row = tabelaBody.insertRow();
                row.innerHTML = `<td colspan="8" class="text-center text-muted">Nenhuma locação encontrada para este caixa.</td>`;
            }

            // Exibe o modal
            const locacoesModal = new bootstrap.Modal(document.getElementById('locacoesModal'));
            locacoesModal.show();
        }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <?php include 'menu.php'; ?> 
</body>
</html>