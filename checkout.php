<?php
// checkout.php - Tela de Checkout e Encerramento de Locação pelo Site (Mobile First)

if (!isset($_COOKIE['usuario_cargo'])) {
    header("Location: index.php");
    exit();
}

$cargo = $_COOKIE['usuario_cargo'];
$filial = isset($_COOKIE["usuario_filial"]) ? $_COOKIE["usuario_filial"] : null;
if (!$filial) {
    die("Filial não selecionada no cookie. Faça login novamente.");
}

// 1. Conecta ao banco correto da filial
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
if ($conexao->connect_error) {
    die("Erro ao conectar ao banco de dados: " . $conexao->connect_error);
}

include 'verificar_acesso.php';
verificarCookie($conexao, basename($_SERVER['PHP_SELF']));

$quarto = isset($_GET['quarto']) ? (int)$_GET['quarto'] : 0;
if ($quarto <= 0) {
    die("Quarto inválido ou não especificado.");
}

// 2. Busca a locação ativa para este quarto
$stmtLoc = $conexao->prepare("SELECT * FROM registralocado WHERE numquarto = ? AND horafim IS NULL");
$stmtLoc->bind_param("i", $quarto);
$stmtLoc->execute();
$resLoc = $stmtLoc->get_result();
$locacao = $resLoc->fetch_assoc();
$stmtLoc->close();

if (!$locacao) {
    // Caso não encontre locação ativa, exibe mensagem
    $mensagemErro = "Nenhuma locação ativa encontrada para o Quarto $quarto.";
} else {
    $idLocacao = $locacao['idlocacao'];
    $horaInicio = $locacao['horainicio'];
    $numPessoas = $locacao['numpessoas'];
    $periodoLocado = $locacao['periodo_locado'];

    // 3. Busca valor base do quarto conforme período
    $valorQuarto = 0;
    if (!empty($periodoLocado)) {
        $stmtRate = $conexao->prepare("SELECT valor FROM periodos_quarto WHERE numeroquarto = ? AND descricao = ?");
        $stmtRate->bind_param("is", $quarto, $periodoLocado);
        $stmtRate->execute();
        $resRate = $stmtRate->get_result();
        if ($resRate && $rowRate = $resRate->fetch_assoc()) {
            $valorQuarto = $rowRate['valor'];
        }
        $stmtRate->close();
    }
    if ($valorQuarto == 0) {
        $stmtRate = $conexao->prepare("SELECT valor FROM periodos_quarto WHERE numeroquarto = ? AND is_pernoite = 0 ORDER BY valor ASC LIMIT 1");
        $stmtRate->bind_param("i", $quarto);
        $stmtRate->execute();
        $resRate = $stmtRate->get_result();
        if ($resRate && $rowRate = $resRate->fetch_assoc()) {
            $valorQuarto = $rowRate['valor'];
        }
        $stmtRate->close();
    }
    if ($valorQuarto == 0) {
        $stmtRate = $conexao->prepare("SELECT valorquarto FROM quartos WHERE numeroquarto = ?");
        $stmtRate->bind_param("i", $quarto);
        $stmtRate->execute();
        $resRate = $stmtRate->get_result();
        if ($resRate && $rowRate = $resRate->fetch_assoc()) {
            $valorQuarto = $rowRate['valorquarto'];
        }
        $stmtRate->close();
    }

    // Busca taxa de adicional por pessoa
    $addPessoaTaxa = 0;
    $stmtAdd = $conexao->prepare("SELECT addPessoa FROM quartos WHERE numeroquarto = ?");
    $stmtAdd->bind_param("i", $quarto);
    $stmtAdd->execute();
    $resAdd = $stmtAdd->get_result();
    if ($resAdd && $rowAdd = $resAdd->fetch_assoc()) {
        $addPessoaTaxa = $rowAdd['addPessoa'];
    }
    $stmtAdd->close();

    // 4. Busca produtos pré-vendidos no banco de dados
    $produtosConsumidos = [];
    $stmtProd = $conexao->prepare("SELECT pv.*, p.nomeproduto, p.valorvenda FROM prevendidos pv JOIN produtos p ON pv.idproduto = p.idproduto WHERE pv.idlocacao = ?");
    $stmtProd->bind_param("i", $idLocacao);
    $stmtProd->execute();
    $resProd = $stmtProd->get_result();
    while ($rowProd = $resProd->fetch_assoc()) {
        $produtosConsumidos[] = [
            'idproduto' => (int)$rowProd['idproduto'],
            'nome' => $rowProd['nomeproduto'],
            'quantidade' => (int)$rowProd['quantidade'],
            'valorvenda' => (float)$rowProd['valorvenda']
        ];
    }
    $stmtProd->close();

    // 5. Busca catálogo completo de produtos ativos para a seleção
    $catalogoProdutos = [];
    $resCat = $conexao->query("SELECT idproduto, nomeproduto, valorvenda, estoque FROM produtos ORDER BY nomeproduto ASC");
    if ($resCat) {
        while ($rowCat = $resCat->fetch_assoc()) {
            $catalogoProdutos[] = [
                'id' => (int)$rowCat['idproduto'],
                'nome' => $rowCat['nomeproduto'],
                'preco' => (float)$rowCat['valorvenda'],
                'estoque' => $rowCat['estoque']
            ];
        }
        $resCat->free();
    }
}

// 6. PROCESSAMENTO DO FORMULÁRIO DE ENCERRAMENTO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'encerrar') {
    // Inclusão da conexão dedicada PDO e do helper de MQTT
    include 'conexao_comando.php';
    include_once 'mqtt_helper.php';

    $final_valorquarto = (float)$_POST['valor_quarto'];
    $valor_adicional_pessoa = (float)$_POST['valor_adicional_pessoa'];
    $valor_adicional_periodo = (float)$_POST['valor_adicional_periodo'];
    $desconto = (float)$_POST['desconto'];
    $acrescimo = (float)$_POST['acrescimo'];
    $justificativa = trim($_POST['justificativa']);
    $numpessoas = (int)$_POST['numpessoas'];

    $pagodinheiro = (float)$_POST['pago_dinheiro'];
    $pagopix = (float)$_POST['pago_pix'];
    $pagocartao = (float)$_POST['pago_cartao'];

    $cartao_credito = (float)$_POST['cartao_credito'];
    $cartao_debito = (float)$_POST['cartao_debito'];

    $imprimir = isset($_POST['imprimir']) ? 1 : 0;
    $produtos_finais = json_decode($_POST['produtos_json'], true);

    // Validações no backend
    $valor_produtos = 0;
    foreach ($produtos_finais as $prod) {
        $valor_produtos += (int)$prod['quantidade'] * (float)$prod['valorvenda'];
    }

    $subtotal = $final_valorquarto + $valor_adicional_pessoa + $valor_adicional_periodo + $valor_produtos + $acrescimo;
    $total_geral = $subtotal - $desconto;
    $total_pago = $pagodinheiro + $pagopix + $pagocartao;

    if (abs($total_pago - $total_geral) > 0.05) {
        $mensagemGlobal = "Erro: O total pago (R$ " . number_format($total_pago, 2, ',', '.') . ") deve ser igual ao total geral da conta (R$ " . number_format($total_geral, 2, ',', '.') . ").";
    } else {
        // Inicia Transação
        $conexao->begin_transaction();
        try {
            // Busca o caixa aberto
            $sqlCaixa = "SELECT id FROM caixa WHERE horafecha IS NULL";
            $resCaixa = $conexao->query($sqlCaixa);
            $idCaixa = null;
            if ($resCaixa && $resCaixa->num_rows > 0) {
                $rowCaixa = $resCaixa->fetch_assoc();
                $idCaixa = (int)$rowCaixa['id'];
                $resCaixa->free();
            }

            if ($idCaixa === null) {
                throw new Exception("Não há nenhum Caixa aberto! Abra o Caixa no sistema Java local antes de encerrar pelo site.");
            }

            // 1. Gerencia Estoque e atualiza a tabela prevendidos/registravendido
            // Carrega os pré-vendidos atuais para calcular as diferenças de estoque
            $old_qtys = [];
            $stmtOld = $conexao->prepare("SELECT idproduto, quantidade FROM prevendidos WHERE idlocacao = ?");
            $stmtOld->bind_param("i", $idLocacao);
            $stmtOld->execute();
            $resOld = $stmtOld->get_result();
            while ($rowOld = $resOld->fetch_assoc()) {
                $old_qtys[(int)$rowOld['idproduto']] = (int)$rowOld['quantidade'];
            }
            $stmtOld->close();

            // Limpa os prevendidos anteriores no banco
            $stmtClearPrev = $conexao->prepare("DELETE FROM prevendidos WHERE idlocacao = ?");
            $stmtClearPrev->bind_param("i", $idLocacao);
            $stmtClearPrev->execute();
            $stmtClearPrev->close();

            // Processa cada produto final
            $stmtInsertVendido = $conexao->prepare("INSERT INTO registravendido (idlocacao, idproduto, quantidade, valorunidade, valortotal, idcaixaatual) VALUES (?, ?, ?, ?, ?, ?)");
            $processed_ids = [];

            foreach ($produtos_finais as $prod) {
                $pid = (int)$prod['idproduto'];
                $pqty = (int)$prod['quantidade'];
                $punit = (float)$prod['valorvenda'];
                $ptotal = $pqty * $punit;
                $processed_ids[] = $pid;

                // Insere permanentemente na tabela registravendido
                $stmtInsertVendido->bind_param("iiiddi", $idLocacao, $pid, $pqty, $punit, $ptotal, $idCaixa);
                $stmtInsertVendido->execute();

                // Calcula diferença do estoque
                $old_qty = isset($old_qtys[$pid]) ? $old_qtys[$pid] : 0;
                $diff = $pqty - $old_qty;

                if ($diff !== 0) {
                    $stmtStock = $conexao->prepare("UPDATE produtos SET estoque = CAST(estoque AS SIGNED) - ? WHERE idproduto = ?");
                    $stmtStock->bind_param("ii", $diff, $pid);
                    $stmtStock->execute();
                    $stmtStock->close();
                }
            }
            $stmtInsertVendido->close();

            // Devolve estoque para produtos removidos inteiramente
            foreach ($old_qtys as $pid => $qty) {
                if (!in_array($pid, $processed_ids)) {
                    $stmtStockRestore = $conexao->prepare("UPDATE produtos SET estoque = CAST(estoque AS SIGNED) + ? WHERE idproduto = ?");
                    $stmtStockRestore->bind_param("ii", $qty, $pid);
                    $stmtStockRestore->execute();
                    $stmtStockRestore->close();
                }
            }

            // 2. Insere Justificativa de Desconto / Acréscimo
            if ($desconto > 0) {
                $stmtJust = $conexao->prepare("INSERT INTO justificativa (idlocacao, valor, tipo, justificativa) VALUES (?, ?, 'desconto', ?)");
                $stmtJust->bind_param("ids", $idLocacao, $desconto, $justificativa);
                $stmtJust->execute();
                $stmtJust->close();
            }
            if ($acrescimo > 0) {
                $stmtJust = $conexao->prepare("INSERT INTO justificativa (idlocacao, valor, tipo, justificativa) VALUES (?, ?, 'acrescimo', ?)");
                $stmtJust->bind_param("ids", $idLocacao, $acrescimo, $justificativa);
                $stmtJust->execute();
                $stmtJust->close();
            }

            // 3. Salva divisão de cartão se houver pagamento em cartão
            if ($pagocartao > 0) {
                // Remove divisão de cartão antiga por segurança
                $stmtDelCard = $conexao->prepare("DELETE FROM valorcartao WHERE idlocacao = ?");
                $stmtDelCard->bind_param("i", $idLocacao);
                $stmtDelCard->execute();
                $stmtDelCard->close();

                // Insere nova divisão crédito e débito
                $stmtCard = $conexao->prepare("INSERT INTO valorcartao (valorcredito, valordebito, idlocacao) VALUES (?, ?, ?)");
                $stmtCard->bind_param("ddi", $cartao_credito, $cartao_debito, $idLocacao);
                $stmtCard->execute();
                $stmtCard->close();
            }

            // 4. Finaliza/Fecha a locação
            $final_valor_quarto_total = $final_valorquarto + $valor_adicional_pessoa + $valor_adicional_periodo;
            $stmtFinal = $conexao->prepare("UPDATE registralocado SET horafim = NOW(), valorquarto = ?, valorconsumo = ?, pagodinheiro = ?, pagopix = ?, pagocartao = ?, idcaixaatual = ? WHERE idlocacao = ?");
            $stmtFinal->bind_param("dddddii", $final_valor_quarto_total, $valor_produtos, $pagodinheiro, $pagopix, $pagocartao, $idCaixa, $idLocacao);
            $stmtFinal->execute();
            $stmtFinal->close();

            // 5. Atualiza o status do quarto para Limpeza
            $stmtStatus = $conexao->prepare("UPDATE status SET atualquarto = 'limpeza', horastatus = NOW() WHERE numeroquarto = ?");
            $stmtStatus->bind_param("i", $quarto);
            $stmtStatus->execute();
            $stmtStatus->close();

            // Registra a limpeza no histórico
            $stmtHist = $conexao->prepare("INSERT INTO registramanutencao (numquarto, horainicio, tipo) VALUES (?, NOW(), 'limpeza')");
            $stmtHist->bind_param("i", $quarto);
            $stmtHist->execute();
            $stmtHist->close();

            // Commit
            $conexao->commit();

            // 6. Registra Comando no Banco de Comandos para o Java e Publica via MQTT
            $pdo = conectarAoBancoComandosPDO();
            if ($pdo) {
                $tabela_comando = "comandos_" . strtolower($filial);
                $cmd_string = "encerrar " . $idLocacao . ($imprimir ? " imprimir" : "");

                $stmtCmd = $pdo->prepare("INSERT INTO {$tabela_comando} (id_unidade, comando, executado, criado_em) VALUES (0, :comando, 0, NOW())");
                $stmtCmd->execute([':comando' => $cmd_string]);
                $lastId = $pdo->lastInsertId();

                $mqttPayload = json_encode([
                    "id" => (int)$lastId,
                    "comando" => $cmd_string
                ]);

                publicarComandoMqtt(strtolower($filial), $mqttPayload);
            }

            // Redireciona com sucesso
            header("Location: quartos.php?sucesso_checkout=1&quarto=" . $quarto);
            exit();

        } catch (Exception $e) {
            $conexao->rollback();
            $mensagemGlobal = "Erro durante o processamento do fechamento: " . $e->getMessage();
        }
    }
}

// LÓGICA DE SALVAR PRODUTOS EM TEMPO REAL NA TABELA PREVENDIDOS (CHAMADA AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_draft_products') {
    header('Content-Type: application/json');
    $produtos_draft = json_decode($_POST['produtos_json'], true);
    
    $conexao->begin_transaction();
    try {
        // Limpa os prevendidos atuais
        $stmtClear = $conexao->prepare("DELETE FROM prevendidos WHERE idlocacao = ?");
        $stmtClear->bind_param("i", $idLocacao);
        $stmtClear->execute();
        $stmtClear->close();

        // Insere a nova lista na prevendidos
        $stmtInsert = $conexao->prepare("INSERT INTO prevendidos (idlocacao, idproduto, quantidade) VALUES (?, ?, ?)");
        foreach ($produtos_draft as $p) {
            $pid = (int)$p['idproduto'];
            $pqty = (int)$p['quantidade'];
            $stmtInsert->bind_param("iii", $idLocacao, $pid, $pqty);
            $stmtInsert->execute();
        }
        $stmtInsert->close();
        
        $conexao->commit();
        echo json_encode(['success' => true]);
        exit();
    } catch (Exception $e) {
        $conexao->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encerrar Quarto <?php echo htmlspecialchars($quarto); ?> - Painel Motel</title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        integrity="sha512-SNJ2I4f5H6eJk95a9y2eW4S4+3p1j6f1w5bE6bWzP+l5R6s5wz+L6Gg0F8+e68kQ8l9z6Kq6g1A0qg7sL4tQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            padding-bottom: 60px;
        }

        .premium-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }

        .premium-header {
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            padding: 15px;
        }

        .btn-premium-primary {
            background: linear-gradient(135deg, #4f46e5, #3b82f6);
            border: none;
            color: white;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-premium-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            opacity: 0.95;
        }

        .btn-premium-success {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-premium-success:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            opacity: 0.95;
        }

        .form-control, .form-select {
            background-color: #1e293b !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            color: #f8fafc !important;
            border-radius: 10px;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.5) !important;
            border-color: #6366f1 !important;
        }

        .item-list-row {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 8px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .qty-controls {
            display: flex;
            align-items: center;
            background: #1e293b;
            border-radius: 8px;
            padding: 2px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .qty-btn {
            background: none;
            border: none;
            color: #06b6d4;
            padding: 4px 10px;
            font-size: 1.1rem;
            cursor: pointer;
        }
        .qty-val {
            min-width: 25px;
            text-align: center;
            font-weight: 500;
        }

        .input-group-text {
            background-color: #334155 !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            color: #cbd5e1 !important;
        }

        .badge-info {
            background-color: rgba(6, 182, 212, 0.15);
            color: #06b6d4;
            border: 1px solid rgba(6, 182, 212, 0.3);
        }
    </style>
</head>

<body>
    <div class="container-fluid px-3 pt-3">
        <!-- Cabeçalho Principal -->
        <div class="d-flex align-items-center justify-content-between mb-3">
            <a href="quartos.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fas fa-arrow-left me-1"></i> Voltar
            </a>
            <h5 class="m-0 text-center flex-grow-1 text-cyan fw-bold">Fechar Quarto <?php echo htmlspecialchars($quarto); ?></h5>
            <div style="width: 70px;"></div> <!-- Spacer -->
        </div>

        <?php if (isset($mensagemErro)): ?>
            <div class="alert alert-warning text-center premium-card border-warning text-white p-4">
                <i class="fas fa-exclamation-triangle fa-2x mb-3 text-warning"></i>
                <p><?php echo $mensagemErro; ?></p>
                <a href="quartos.php" class="btn btn-premium-primary rounded-pill px-4 mt-2">Voltar aos Quartos</a>
            </div>
        <?php else: ?>
            
            <?php if (isset($mensagemGlobal)): ?>
                <div class="alert alert-danger alert-dismissible fade show premium-card border-danger text-white mb-3" role="alert">
                    <i class="fas fa-times-circle me-2"></i> <?php echo $mensagemGlobal; ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>

            <form id="checkoutForm" method="POST" action="checkout.php?quarto=<?php echo $quarto; ?>">
                <input type="hidden" name="action" value="encerrar">
                <input type="hidden" name="produtos_json" id="produtos_json" value="[]">

                <!-- CARD 1: INFORMAÇÕES GERAIS E TEMPO -->
                <div class="card premium-card mb-3">
                    <div class="premium-header text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold"><i class="fas fa-clock me-1"></i> Estadia & Tempo</span>
                            <span class="badge badge-info rounded-pill px-3 py-1"><?php echo htmlspecialchars($periodoLocado ?: 'Período Normal'); ?></span>
                        </div>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-2">
                            <div class="col-6">
                                <small class="text-slate-400 text-muted d-block">Entrada</small>
                                <span class="fw-medium"><?php echo date('d/m/Y H:i', strtotime($horaInicio)); ?></span>
                            </div>
                            <div class="col-6">
                                <small class="text-slate-400 text-muted d-block">Duração Atual</small>
                                <span id="duracao_atual" class="fw-bold text-info">Calculando...</span>
                            </div>
                            <div class="col-12 mt-2">
                                <label class="form-label mb-1">Quantidade de Pessoas</label>
                                <div class="input-group">
                                    <button class="btn btn-outline-secondary" type="button" onclick="alterarPessoas(-1)"><i class="fas fa-minus"></i></button>
                                    <input type="number" class="form-control text-center text-white fw-bold" id="numpessoas" name="numpessoas" value="<?php echo $numPessoas; ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" onclick="alterarPessoas(1)"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARD 2: CONSUMO DE PRODUTOS -->
                <div class="card premium-card mb-3">
                    <div class="premium-header text-white py-2">
                        <span class="fw-bold"><i class="fas fa-utensils me-1"></i> Produtos Consumidos</span>
                    </div>
                    <div class="card-body p-3">
                        <!-- Selecionador de Produtos -->
                        <div class="row g-2 mb-3">
                            <div class="col-8">
                                <select class="form-select" id="select_produto">
                                    <option value="">-- Selecione Produto --</option>
                                    <?php foreach ($catalogoProdutos as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" data-nome="<?php echo htmlspecialchars($p['nome']); ?>" data-preco="<?php echo $p['preco']; ?>">
                                            <?php echo htmlspecialchars($p['nome']) . ' (R$ ' . number_format($p['preco'], 2, ',', '.') . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-4">
                                <button type="button" class="btn btn-premium-primary w-100" onclick="adicionarProdutoSelecionado()">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                        </div>

                        <!-- Lista de Itens Consumidos (Mobile First) -->
                        <div id="lista_produtos_container">
                            <!-- Inserido dinamicamente via JS -->
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-secondary">
                            <span class="fw-medium">Total Consumo:</span>
                            <span id="label_total_consumo" class="fw-bold fs-5 text-warning">R$ 0,00</span>
                        </div>
                    </div>
                </div>

                <!-- CARD 3: VALORES E TARIFAS -->
                <div class="card premium-card mb-3">
                    <div class="premium-header text-white py-2">
                        <span class="fw-bold"><i class="fas fa-file-invoice-dollar me-1"></i> Tarifas e Justificativas</span>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label mb-1">Valor Quarto (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white" id="valor_quarto" name="valor_quarto" value="<?php echo $valorQuarto; ?>" oninput="atualizarTotais()">
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Adic. Pessoas (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white" id="valor_adicional_pessoa" name="valor_adicional_pessoa" value="0.00" oninput="atualizarTotais()">
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Adic. Período (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white" id="valor_adicional_periodo" name="valor_adicional_periodo" value="0.00" oninput="atualizarTotais()">
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Desconto (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white text-danger" id="desconto" name="desconto" value="0.00" oninput="atualizarTotais()">
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Acréscimo (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white text-success" id="acrescimo" name="acrescimo" value="0.00" oninput="atualizarTotais()">
                            </div>
                            <div class="col-12 mt-2" id="wrapper_justificativa" style="display: none;">
                                <label class="form-label mb-1 text-warning"><i class="fas fa-edit me-1"></i> Justificativa do Desconto/Acréscimo</label>
                                <textarea class="form-control text-white" id="justificativa" name="justificativa" rows="2" placeholder="Descreva o motivo..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARD 4: PAGAMENTOS -->
                <div class="card premium-card mb-3">
                    <div class="premium-header text-white py-2">
                        <span class="fw-bold"><i class="fas fa-wallet me-1"></i> Formas de Pagamento</span>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="form-label mb-1 text-info">Dinheiro</label>
                                <input type="number" step="0.01" class="form-control text-white" id="pago_dinheiro" name="pago_dinheiro" value="0.00" oninput="validarPagamentos()">
                            </div>
                            <div class="col-4">
                                <label class="form-label mb-1 text-info">Pix</label>
                                <input type="number" step="0.01" class="form-control text-white" id="pago_pix" name="pago_pix" value="0.00" oninput="validarPagamentos()">
                            </div>
                            <div class="col-4">
                                <label class="form-label mb-1 text-info">Cartão</label>
                                <input type="number" step="0.01" class="form-control text-white" id="pago_cartao" name="pago_cartao" value="0.00" oninput="toggleCartaoSplit(); validarPagamentos();">
                            </div>
                        </div>

                        <!-- Detalhamento divisão do Cartão (Crédito vs Débito) -->
                        <div class="row g-2 mt-2 pt-2 border-top border-secondary" id="cartao_split_container" style="display: none;">
                            <div class="col-6">
                                <label class="form-label mb-1">C. Crédito (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white" id="cartao_credito" name="cartao_credito" value="0.00" oninput="validarPagamentos()">
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">C. Débito (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white" id="cartao_debito" name="cartao_debito" value="0.00" oninput="validarPagamentos()">
                            </div>
                            <div class="col-12 mt-1">
                                <span class="text-danger small" id="cartao_split_erro" style="display: none;">
                                    <i class="fas fa-exclamation-circle me-1"></i> A soma (Crédito + Débito) deve ser igual ao valor Cartão.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARD 5: CONFIRMAÇÃO E VALORES GERAIS -->
                <div class="card premium-card mb-4">
                    <div class="card-body p-3">
                        <!-- Demonstrativo Financeiro -->
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-slate-400 text-muted">Subtotal (Quarto + Consumo):</span>
                            <span id="label_subtotal" class="fw-medium">R$ 0,00</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary">
                            <span class="text-slate-400 text-muted">Desconto / Acréscimo:</span>
                            <span id="label_ajuste" class="fw-medium">R$ 0,00</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold fs-5">TOTAL GERAL:</span>
                            <span id="label_total_geral" class="fw-bold fs-4 text-cyan text-info">R$ 0,00</span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-3 text-warning">
                            <span class="fw-medium">Total Pago:</span>
                            <span id="label_total_pago" class="fw-bold fs-5">R$ 0,00</span>
                        </div>

                        <!-- Opção de Impressão -->
                        <div class="form-check form-switch mb-3 p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                            <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="imprimir" name="imprimir" checked value="1">
                            <label class="form-check-label fw-medium text-white" for="imprimir">
                                <i class="fas fa-print me-1"></i> Imprimir Extrato na Recepção
                            </label>
                        </div>

                        <!-- Botão Enviar -->
                        <button type="submit" id="btn_submit" class="btn btn-premium-success w-100 py-3 rounded-pill fs-5" disabled>
                            <i class="fas fa-check-circle me-1"></i> Confirmar Encerramento
                        </button>
                        
                        <div class="text-center mt-2">
                            <span class="text-danger small" id="mensagem_validacao">
                                <i class="fas fa-info-circle me-1"></i> Lance os pagamentos corretamente para liberar o encerramento.
                            </span>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- JS Scripts e Validações -->
    <script>
        // Dados PHP passados para o Javascript
        const horaInicioStr = "<?php echo isset($horaInicio) ? $horaInicio : ''; ?>";
        const addPessoaTaxa = <?php echo isset($addPessoaTaxa) ? (float)$addPessoaTaxa : 0; ?>;
        
        // Estado dinâmico do Consumo de Produtos
        let listaProdutos = <?php echo isset($produtosConsumidos) ? json_encode($produtosConsumidos) : '[]'; ?>;

        // Formatação Dinâmica de Horários
        function atualizarTempoDuracao() {
            if (!horaInicioStr) return;
            const dataInicio = new Date(horaInicioStr.replace(/-/g, "/"));
            const agora = new Date();

            const diff = agora.getTime() - dataInicio.getTime();
            const diffMinutos = Math.floor(diff / (60 * 1000)) % 60;
            const diffHoras = Math.floor(diff / (60 * 60 * 1000)) % 24;
            const diffDias = Math.floor(diff / (24 * 60 * 60 * 1000));

            let texto = '';
            if (diffDias > 0) {
                texto = `${diffDias}d, ${diffHoras}h ${diffMinutos}m`;
            } else if (diffHoras > 0) {
                texto = `${diffHoras}h ${diffMinutos}m`;
            } else {
                texto = `${diffMinutos}m`;
            }
            document.getElementById('duracao_atual').innerText = texto;
        }

        // Altera Quantidade de Pessoas
        function alterarPessoas(direcao) {
            const input = document.getElementById('numpessoas');
            let val = parseInt(input.value) + direcao;
            if (val < 1) val = 1;
            input.value = val;

            // Calcula adicional de pessoas (padrão: acima de 2 pessoas paga a taxa)
            const inputAddPessoas = document.getElementById('valor_adicional_pessoa');
            if (val > 2) {
                inputAddPessoas.value = ((val - 2) * addPessoaTaxa).toFixed(2);
            } else {
                inputAddPessoas.value = "0.00";
            }
            atualizarTotais();
        }

        // Adiciona Produto Selecionado no Select
        function adicionarProdutoSelecionado() {
            const select = document.getElementById('select_produto');
            const selectedOption = select.options[select.selectedIndex];
            
            if (!selectedOption.value) return;

            const id = parseInt(selectedOption.value);
            const nome = selectedOption.getAttribute('data-nome') || selectedOption.text.split(' (')[0];
            const preco = parseFloat(selectedOption.getAttribute('data-preco'));

            // Verifica se já está na lista
            let prod = listaProdutos.find(p => p.idproduto === id);
            if (prod) {
                prod.quantidade += 1;
            } else {
                listaProdutos.push({
                    idproduto: id,
                    nome: nome,
                    quantidade: 1,
                    valorvenda: preco
                });
            }

            renderizarProdutos();
            saveDraftProducts(); // Salva estado atualizado no banco em tempo real via AJAX
        }

        // Altera Quantidade de Produto Consumido
        function alterarQtdProduto(id, direcao) {
            let index = listaProdutos.findIndex(p => p.idproduto === id);
            if (index === -1) return;

            listaProdutos[index].quantidade += direcao;
            if (listaProdutos[index].quantidade <= 0) {
                listaProdutos.splice(index, 1);
            }

            renderizarProdutos();
            saveDraftProducts(); // Salva estado atualizado no banco em tempo real via AJAX
        }

        // Salva lista temporária de produtos no banco via AJAX (Draft)
        function saveDraftProducts() {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", "checkout.php?quarto=<?php echo $quarto; ?>", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.send("action=save_draft_products&produtos_json=" + encodeURIComponent(JSON.stringify(listaProdutos)));
        }

        // Renderiza lista de produtos no container (Mobile First)
        function renderizarProdutos() {
            const container = document.getElementById('lista_produtos_container');
            container.innerHTML = '';

            if (listaProdutos.length === 0) {
                container.innerHTML = '<p class="text-muted text-center py-2 mb-0">Nenhum produto consumido.</p>';
                document.getElementById('label_total_consumo').innerText = "R$ 0,00";
                atualizarTotais();
                return;
            }

            let totalConsumo = 0;

            listaProdutos.forEach(p => {
                const subTotalItem = p.quantidade * p.valorvenda;
                totalConsumo += subTotalItem;

                const row = document.createElement('div');
                row.className = 'item-list-row';
                row.innerHTML = `
                    <div style="max-width: 50%;">
                        <strong class="d-block text-white text-truncate" title="${p.nome}">${p.nome}</strong>
                        <small class="text-muted">Un: R$ ${p.valorvenda.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</small>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="qty-controls me-3">
                            <button type="button" class="qty-btn" onclick="alterarQtdProduto(${p.idproduto}, -1)"><i class="fas fa-minus fa-sm"></i></button>
                            <span class="qty-val text-white">${p.quantidade}</span>
                            <button type="button" class="qty-btn" onclick="alterarQtdProduto(${p.idproduto}, 1)"><i class="fas fa-plus fa-sm"></i></button>
                        </div>
                        <span class="fw-bold text-end" style="min-width: 70px;">R$ ${subTotalItem.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</span>
                    </div>
                `;
                container.appendChild(row);
            });

            document.getElementById('label_total_consumo').innerText = `R$ ${totalConsumo.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
            atualizarTotais();
        }

        // Atualiza os Totais Gerais na Tela
        function atualizarTotais() {
            const valorQuarto = parseFloat(document.getElementById('valor_quarto').value) || 0;
            const valorAdicPessoas = parseFloat(document.getElementById('valor_adicional_pessoa').value) || 0;
            const valorAdicPeriodo = parseFloat(document.getElementById('valor_adicional_periodo').value) || 0;
            const desconto = parseFloat(document.getElementById('desconto').value) || 0;
            const acrescimo = parseFloat(document.getElementById('acrescimo').value) || 0;

            // Calcula total de produtos
            let totalConsumo = 0;
            listaProdutos.forEach(p => {
                totalConsumo += p.quantidade * p.valorvenda;
            });

            const subtotal = valorQuarto + valorAdicPessoas + valorAdicPeriodo + totalConsumo;
            const totalGeral = subtotal + acrescimo - desconto;

            document.getElementById('label_subtotal').innerText = `R$ ${subtotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
            
            const ajusteVal = acrescimo - desconto;
            let ajusteStr = `R$ ${Math.abs(ajusteVal).toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
            if (ajusteVal > 0) {
                document.getElementById('label_ajuste').innerText = `+ ${ajusteStr}`;
                document.getElementById('label_ajuste').className = 'fw-medium text-success';
            } else if (ajusteVal < 0) {
                document.getElementById('label_ajuste').innerText = `- ${ajusteStr}`;
                document.getElementById('label_ajuste').className = 'fw-medium text-danger';
            } else {
                document.getElementById('label_ajuste').innerText = `R$ 0,00`;
                document.getElementById('label_ajuste').className = 'fw-medium';
            }

            document.getElementById('label_total_geral').innerText = `R$ ${totalGeral.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;

            // Exibe justificativa caso haja desconto ou acréscimo
            const wrapperJust = document.getElementById('wrapper_justificativa');
            const inputJust = document.getElementById('justificativa');
            if (desconto > 0 || acrescimo > 0) {
                wrapperJust.style.display = 'block';
                inputJust.setAttribute('required', 'required');
            } else {
                wrapperJust.style.display = 'none';
                inputJust.removeAttribute('required');
            }

            // Para pre-encher a justificativa caso seja vazia e o desconto seja o desconto automatico
            if (desconto > 0 && inputJust.value.trim() === '') {
                inputJust.value = "Desconto aplicado pelo Painel Web";
            } else if (acrescimo > 0 && inputJust.value.trim() === '') {
                inputJust.value = "Acréscimo aplicado pelo Painel Web";
            }

            // Atualiza input hidden com JSON dos produtos finais
            document.getElementById('produtos_json').value = JSON.stringify(listaProdutos);

            validarPagamentos();
        }

        // Mostra / Esconde o split de cartões (crédito vs débito)
        function toggleCartaoSplit() {
            const pagoCartao = parseFloat(document.getElementById('pago_cartao').value) || 0;
            const splitContainer = document.getElementById('cartao_split_container');
            
            if (pagoCartao > 0) {
                splitContainer.style.display = 'flex';
                // Pre-popula tudo em crédito por padrão caso esteja zerado
                const credito = parseFloat(document.getElementById('cartao_credito').value) || 0;
                const debito = parseFloat(document.getElementById('cartao_debito').value) || 0;
                if (credito === 0 && debito === 0) {
                    document.getElementById('cartao_credito').value = pagoCartao.toFixed(2);
                }
            } else {
                splitContainer.style.display = 'none';
                document.getElementById('cartao_credito').value = "0.00";
                document.getElementById('cartao_debito').value = "0.00";
            }
        }

        // Valida se as formas de pagamento somam exatamente o total geral
        function validarPagamentos() {
            const valorQuarto = parseFloat(document.getElementById('valor_quarto').value) || 0;
            const valorAdicPessoas = parseFloat(document.getElementById('valor_adicional_pessoa').value) || 0;
            const valorAdicPeriodo = parseFloat(document.getElementById('valor_adicional_periodo').value) || 0;
            const desconto = parseFloat(document.getElementById('desconto').value) || 0;
            const acrescimo = parseFloat(document.getElementById('acrescimo').value) || 0;

            let totalConsumo = 0;
            listaProdutos.forEach(p => {
                totalConsumo += p.quantidade * p.valorvenda;
            });

            const totalGeral = parseFloat((valorQuarto + valorAdicPessoas + valorAdicPeriodo + totalConsumo + acrescimo - desconto).toFixed(2));

            const pagoDinheiro = parseFloat(document.getElementById('pago_dinheiro').value) || 0;
            const pagoPix = parseFloat(document.getElementById('pago_pix').value) || 0;
            const pagoCartao = parseFloat(document.getElementById('pago_cartao').value) || 0;

            const totalPago = parseFloat((pagoDinheiro + pagoPix + pagoCartao).toFixed(2));

            document.getElementById('label_total_pago').innerText = `R$ ${totalPago.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;

            const btnSubmit = document.getElementById('btn_submit');
            const msgValidacao = document.getElementById('mensagem_validacao');

            // Validação de divisão do cartão
            let cartaoValido = true;
            if (pagoCartao > 0) {
                const cred = parseFloat(document.getElementById('cartao_credito').value) || 0;
                const deb = parseFloat(document.getElementById('cartao_debito').value) || 0;
                const somaSplit = parseFloat((cred + deb).toFixed(2));
                const erroSplit = document.getElementById('cartao_split_erro');

                if (Math.abs(somaSplit - pagoCartao) > 0.01) {
                    erroSplit.style.display = 'block';
                    cartaoValido = false;
                } else {
                    erroSplit.style.display = 'none';
                }
            }

            // Valida se o total bate e a divisão de cartões está correta
            if (Math.abs(totalPago - totalGeral) <= 0.02 && cartaoValido) {
                btnSubmit.removeAttribute('disabled');
                msgValidacao.className = "text-success small";
                msgValidacao.innerHTML = '<i class="fas fa-check-circle me-1"></i> Pagamentos validados! Pronto para encerrar.';
            } else {
                btnSubmit.setAttribute('disabled', 'disabled');
                msgValidacao.className = "text-danger small";
                if (!cartaoValido) {
                    msgValidacao.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> A divisão do cartão (crédito/débito) está incorreta.';
                } else {
                    const diff = totalGeral - totalPago;
                    if (diff > 0) {
                        msgValidacao.innerHTML = `<i class="fas fa-info-circle me-1"></i> Faltam R$ ${diff.toLocaleString('pt-BR', {minimumFractionDigits: 2})} para fechar a conta.`;
                    } else {
                        msgValidacao.innerHTML = `<i class="fas fa-info-circle me-1"></i> Pagamento excedeu em R$ ${Math.abs(diff).toLocaleString('pt-BR', {minimumFractionDigits: 2})} do total geral.`;
                    }
                }
            }
        }

        // Loop de Atualização do tempo e renderização inicial
        window.addEventListener('DOMContentLoaded', () => {
            atualizarTempoDuracao();
            setInterval(atualizarTempoDuracao, 30000); // 30s
            renderizarProdutos();
        });
    </script>
</body>

</html>
