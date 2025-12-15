<?php
// Verifica se o cookie da filial existe
if (!isset($_COOKIE["usuario_filial"])) {
    // Evita redirecionamento dentro de uma requisição AJAX
    // Em produção, isso pode ser um retorno de erro JSON ou HTML simples
    http_response_code(401);
    die("Acesso não autorizado. Cookie de filial ausente.");
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
        http_response_code(500);
        die("Filial inválida.");
}

// Assume que a função 'conectarAoBanco()' está definida nos arquivos de conexão incluídos.
// Se esta função não existir, você terá que reescrevê-la aqui ou garantir sua inclusão.

function getDescricao($idPassado) {
    // Conexão com o banco de dados
    $conexao = conectarAoBanco();
    
    // Consulta SQL para obter a descrição do produto
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
            return "Produto Desconhecido";
        }
    } catch (Exception $e) {
        // Logar o erro, mas retornar uma mensagem simples para o usuário
        return "Erro de BD";
    } finally {
        if (isset($statement)) $statement->close();
        if (isset($conexao)) $conexao->close();
    }
}

function formatarData($data) {
    // Verifica se a data é válida antes de formatar
    return $data ? date('d/m/Y H:i', strtotime($data)) : 'N/A';
}

// Verifica se o número do quarto foi enviado por POST
if (isset($_POST['numeroquarto'])) {
    $numeroQuarto = $_POST['numeroquarto'];

    // Consulta SQL: Pega a última locação encerrada (horafim is not null)
    $consultaSQL = "SELECT * FROM registralocado WHERE idlocacao = (SELECT MAX(idlocacao) FROM registralocado WHERE numquarto = ? AND horafim IS NOT NULL)";
    
    // Conexão com o banco de dados
    $conexao = conectarAoBanco();

    try {
        // 1. Obter Dados da Locação
        $statement = $conexao->prepare($consultaSQL);
        $statement->bind_param("i", $numeroQuarto);
        $statement->execute();
        $resultado = $statement->get_result();
        
        if ($resultado && $resultado->num_rows > 0) {
            $dadosQuarto = $resultado->fetch_assoc();
            
            // Variáveis
            $idLocacao = $dadosQuarto['idlocacao'];
            $horaInicio = formatarData($dadosQuarto['horainicio']);
            $horaFim = formatarData($dadosQuarto['horafim']);
            $valorQuarto = number_format($dadosQuarto['valorquarto'], 2, ',', '.');
            $valorConsumo = number_format($dadosQuarto['valorconsumo'], 2, ',', '.');

            // 2. Obter Justificativa (Desconto/Acréscimo) - CORRIGIDO PARA SOMAR TODOS
            $desconto = 0;
            $acrescimo = 0;
            $justificativa = "Nenhuma";
            $motivos = array();
            
            $consultaJustificativa = "SELECT tipo, valor, justificativa FROM justificativa WHERE idlocacao = ?";
            $statementJustificativa = $conexao->prepare($consultaJustificativa);
            $statementJustificativa->bind_param("i", $idLocacao);
            $statementJustificativa->execute();
            $resultadoJustificativa = $statementJustificativa->get_result();
            
            if ($resultadoJustificativa && $resultadoJustificativa->num_rows > 0) {
                while ($j = $resultadoJustificativa->fetch_assoc()) {
                    if ($j['tipo'] === 'desconto') {
                        $desconto += $j['valor'];
                    } else {
                        $acrescimo += $j['valor'];
                    }
                    if (!empty($j['justificativa'])) {
                        $motivos[] = $j['justificativa'];
                    }
                }
                if (!empty($motivos)) {
                    $justificativa = implode(' | ', $motivos);
                }
            }
            $desconto = number_format($desconto, 2, ',', '.');
            $acrescimo = number_format($acrescimo, 2, ',', '.');
            $statementJustificativa->close();


            // 3. Obter Produtos Vendidos (com descrição direto do banco - evita reconexão)
            $produtos = array();
            $consultaProdutos = "SELECT p.descricao, rv.idproduto, SUM(rv.quantidade) AS quantidade, rv.valorunidade
                                 FROM registravendido rv
                                 JOIN produtos p ON rv.idproduto = p.idproduto
                                 WHERE rv.idlocacao = ?
                                 GROUP BY rv.idproduto, rv.valorunidade, p.descricao";
            $statementProdutos = $conexao->prepare($consultaProdutos);
            $statementProdutos->bind_param("i", $idLocacao);
            $statementProdutos->execute();
            $resultadoProdutos = $statementProdutos->get_result();
            
            if ($resultadoProdutos && $resultadoProdutos->num_rows > 0) {
                while ($produto = $resultadoProdutos->fetch_assoc()) {
                    $produtos[] = $produto;
                }
            }
            $statementProdutos->close();

            // =========================================================
            // INÍCIO DA SAÍDA HTML OTIMIZADA COM BOOTSTRAP
            // =========================================================
            
            // 1. Seção de Detalhes e Valores (duas colunas)
            echo '<div class="row mb-4">';
                
                // Coluna 1: Datas e Valores Principais
                echo '<div class="col-md-6">';
                    echo '<h5 class="text-primary mb-3"><i class="far fa-calendar-alt me-2"></i> Detalhes da Locação</h5>';
                    // Lista de Definição (dl) para layout compacto
                    echo '<dl class="row">';
                        echo '<dt class="col-sm-5">Entrada:</dt>';
                        echo '<dd class="col-sm-7 fw-bold">' . $horaInicio . '</dd>';
                        
                        echo '<dt class="col-sm-5">Saída:</dt>';
                        echo '<dd class="col-sm-7 fw-bold">' . $horaFim . '</dd>';
                        
                        echo '<dt class="col-sm-5">Valor Quarto:</dt>';
                        echo '<dd class="col-sm-7 text-success">R$ ' . $valorQuarto . '</dd>';
                        
                        echo '<dt class="col-sm-5">Valor Consumo:</dt>';
                        echo '<dd class="col-sm-7 text-success">R$ ' . $valorConsumo . '</dd>';
                    echo '</dl>';
                echo '</div>'; // Fim da col-md-6
                
                // Coluna 2: Descontos e Acréscimos
                echo '<div class="col-md-6">';
                    echo '<h5 class="text-info mb-3"><i class="fas fa-tags me-2"></i> Ajustes e Recebimento</h5>';
                    echo '<dl class="row">';
                        echo '<dt class="col-sm-5">Desconto:</dt>';
                        echo '<dd class="col-sm-7 text-danger">R$ ' . $desconto . '</dd>';
                        
                        echo '<dt class="col-sm-5">Acréscimo:</dt>';
                        echo '<dd class="col-sm-7 text-warning">R$ ' . $acrescimo . '</dd>';

                        echo '<dt class="col-sm-5">Motivo:</dt>';
                        echo '<dd class="col-sm-7 fst-italic">' . $justificativa . '</dd>';
                    echo '</dl>';
                    
                    // Recebimento - Destacado
                    echo '<div class="mt-3 p-2 bg-light border rounded">';
                        echo '<h6 class="mb-2 text-dark">Formas de Pagamento:</h6>';
                        echo '<div class="d-flex justify-content-between small">';
                            echo '<span><i class="fas fa-money-bill-wave me-1"></i> Dinheiro: <strong class="text-success">R$ ' . number_format($dadosQuarto['pagodinheiro'], 2, ',', '.') . '</strong></span>';
                            echo '<span><i class="fas fa-qrcode me-1"></i> Pix: <strong class="text-success">R$ ' . number_format($dadosQuarto['pagopix'], 2, ',', '.') . '</strong></span>';
                            echo '<span><i class="far fa-credit-card me-1"></i> Cartão: <strong class="text-success">R$ ' . number_format($dadosQuarto['pagocartao'], 2, ',', '.') . '</strong></span>';
                        echo '</div>';
                    echo '</div>';
                echo '</div>'; // Fim da col-md-6
                
            echo '</div>'; // Fim da row de Detalhes

            // 2. Seção de Produtos Vendidos (Tabela)
            if (!empty($produtos)) {
                echo '<h4 class="text-secondary mt-3 mb-3 border-top pt-3"><i class="fas fa-utensils me-2"></i> Produtos Consumidos</h4>';
                
                // table-responsive garante a visualização correta em telas pequenas
                echo '<div class="table-responsive">';
                // table-sm, table-striped e table-bordered para visualização compacta e clara
                echo '<table class="table table-sm table-striped table-bordered">';
                echo '<thead class="table-dark">';
                echo '<tr>';
                echo '<th scope="col">Produto</th>';
                echo '<th scope="col" class="text-center">Qtd</th>';
                echo '<th scope="col" class="text-end">Vl. Unit.</th>';
                echo '<th scope="col" class="text-end">Vl. Total</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';
                
                foreach ($produtos as $produto) {
                    $valorTotalProduto = $produto['quantidade'] * $produto['valorunidade'];
                    
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($produto['descricao']) . '</td>';
                    echo '<td class="text-center">' . $produto['quantidade'] . '</td>';
                    echo '<td class="text-end">R$ ' . number_format($produto['valorunidade'], 2, ',', '.') . '</td>';
                    echo '<td class="text-end">R$ ' . number_format($valorTotalProduto, 2, ',', '.') . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
                echo '</div>'; // Fim do table-responsive
            } else {
                echo '<div class="alert alert-light border text-center mt-3"><i class="fas fa-info-circle me-2"></i> Nenhum produto registrado nesta locação.</div>';
            }
            
            // =========================================================
            // FIM DA SAÍDA HTML OTIMIZADA COM BOOTSTRAP
            // =========================================================
            
        } else {
            // Se não houver resultados (locação encerrada)
            echo '<div class="alert alert-info text-center">';
            echo '<p class="mb-0"><i class="fas fa-exclamation-circle me-2"></i> Nenhuma locação **encerrada** encontrada para o Quarto #' . $numeroQuarto . '.</p>';
            echo '</div>';
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo '<div class="alert alert-danger text-center"><p>Ocorreu um erro ao obter os dados do quarto: ' . $e->getMessage() . '</p></div>';
    } finally {
        if (isset($statement)) $statement->close();
        if (isset($conexao)) $conexao->close();
    }
} else {
    http_response_code(400);
    echo '<div class="alert alert-warning text-center">Número do quarto não especificado.</div>';
}
?>