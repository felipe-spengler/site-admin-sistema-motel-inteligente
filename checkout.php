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
    $mensagemErro = "Nenhuma locação ativa encontrada para o Quarto $quarto na filial '" . htmlspecialchars($filial) . "'.";
} else {
    $idLocacao = $locacao['idlocacao'];
    $horaInicio = $locacao['horainicio'];
    $numPessoas = $locacao['numpessoas'];
    $periodoLocado = $locacao['periodo_locado'];

    // 3. Calcula os valores do quarto de forma automática idêntica ao sistema Java
    $valorQuarto = 0;
    $valorAdicionalPeriodo = 0;
    $valorAdicionalPessoa = 0;
    $periodoFinalStr = "Periodo Padrão";

    // Busca taxa de adicional por pessoa
    $addPessoaTaxa = 0;
    $stmtAdd = $conexao->prepare("SELECT addPessoa FROM quartos WHERE numeroquarto = ?");
    $stmtAdd->bind_param("i", $quarto);
    $stmtAdd->execute();
    $resAdd = $stmtAdd->get_result();
    if ($resAdd && $rowAdd = $resAdd->fetch_assoc()) {
        $addPessoaTaxa = (float)$rowAdd['addPessoa'];
    }
    $stmtAdd->close();

    // Adicional por Pessoa
    $adPessoaTaxaCalc = $addPessoaTaxa;
    if ($adPessoaTaxaCalc == 0) {
        $adPessoaTaxaCalc = 1;
    }
    $adicionalPessoas = ($numPessoas - 2) * $adPessoaTaxaCalc;
    $valorAdicionalPessoa = ($adicionalPessoas <= 0) ? 0 : $adicionalPessoas;

    // Busca taxa de adicional por hora (adicional em status)
    $valorAdicionalHora = 0;
    $stmtAdicHora = $conexao->prepare("SELECT adicional, atualquarto FROM status WHERE numeroquarto = ?");
    $stmtAdicHora->bind_param("i", $quarto);
    $stmtAdicHora->execute();
    $resAdicHora = $stmtAdicHora->get_result();
    $statusQuarto = "";
    if ($resAdicHora && $rowAdicHora = $resAdicHora->fetch_assoc()) {
        $valorAdicionalHora = (float)$rowAdicHora['adicional'];
        $statusQuarto = $rowAdicHora['atualquarto'];
    }
    $stmtAdicHora->close();

    // Busca todos os períodos cadastrados
    $periodos = [];
    $stmtP = $conexao->prepare("SELECT * FROM periodos_quarto WHERE numeroquarto = ? ORDER BY ordem");
    $stmtP->bind_param("i", $quarto);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    while ($rowP = $resP->fetch_assoc()) {
        $periodos[] = [
            'descricao' => $rowP['descricao'],
            'tempo_minutos' => (int)$rowP['tempo_minutos'],
            'valor' => (float)$rowP['valor'],
            'is_pernoite' => (int)$rowP['is_pernoite']
        ];
    }
    $stmtP->close();

    // Diferença de tempo
    $dateInicio = new DateTime($horaInicio);
    $dateAgora = new DateTime();
    $diffSecs = $dateAgora->getTimestamp() - $dateInicio->getTimestamp();
    $totalMinutosPassados = (int)floor($diffSecs / 60);

    $periodoEncontrado = null;

    // 1. Tenta localizar pelo período gravado na locação (Totem)
    if (!empty($periodoLocado)) {
        foreach ($periodos as $p) {
            if (strcasecmp($p['descricao'], $periodoLocado) === 0) {
                $statusEhPernoite = (strpos($statusQuarto, 'pernoite') !== false);
                if ($statusEhPernoite != $p['is_pernoite']) {
                    continue;
                }
                $periodoEncontrado = $p;
                break;
            }
        }
    }

    // 2. Lógica de Upgrade/Recalculo se não tiver ou se o totem não bater
    if (!$periodoEncontrado) {
        $isPern = (strpos($statusQuarto, 'pernoite') !== false);
        if ($isPern) {
            foreach ($periodos as $p) {
                if ($p['is_pernoite']) {
                    $periodoEncontrado = $p;
                    break;
                }
            }
        } else {
            foreach ($periodos as $p) {
                if (!$p['is_pernoite']) {
                    if ($totalMinutosPassados <= $p['tempo_minutos'] + 10) {
                        $periodoEncontrado = $p;
                        break;
                    }
                }
            }
        }
    }

    // Se passou do maior período normal
    if (!$periodoEncontrado && !empty($periodos)) {
        for ($i = count($periodos) - 1; $i >= 0; $i--) {
            if (!$periodos[$i]['is_pernoite']) {
                $periodoEncontrado = $periodos[$i];
                break;
            }
        }
    }

    // Se ainda nulo
    if (!$periodoEncontrado && !empty($periodos)) {
        $periodoEncontrado = $periodos[0];
    }

    if ($periodoEncontrado) {
        $valorQuarto = $periodoEncontrado['valor'];
        $periodoFinalStr = $periodoEncontrado['descricao'];
        
        if ($totalMinutosPassados > $periodoEncontrado['tempo_minutos'] + 10) {
            $sobraMinutos = $totalMinutosPassados - $periodoEncontrado['tempo_minutos'];
            $add = (int)ceil($sobraMinutos / 60.0);
            $valorAdicionalPeriodo = $add * $valorAdicionalHora;
        }
    }

    // Dispara comando remoto para o Java abrir a tela de encerramento localmente
    // E grava a linha de ping inicial para evitar falso positivo do timer
    include_once 'conexao_comando.php';
    include_once 'mqtt_helper.php';
    $pdo = conectarAoBancoComandosPDO();
    if ($pdo) {
        // Cria tabela de pings na inicialização se não existir (garantia)
        $pdo->exec("CREATE TABLE IF NOT EXISTS checkout_session_ping (numeroquarto INT PRIMARY KEY, ultima_atividade TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        
        // Insere o ping inicial
        $stmtPinger = $pdo->prepare("INSERT INTO checkout_session_ping (numeroquarto, ultima_atividade) VALUES (:quarto, NOW()) ON DUPLICATE KEY UPDATE ultima_atividade = NOW()");
        $stmtPinger->execute([':quarto' => $quarto]);

        // Envia o comando abrir_checkout
        $tabela_comando = "comandos_" . strtolower($filial);
        $comandoAbrir = "abrir_checkout $quarto";

        $stmtCmd = $pdo->prepare("INSERT INTO {$tabela_comando} (id_unidade, comando, executado, criado_em) VALUES (0, :comando, 0, NOW())");
        $stmtCmd->execute([':comando' => $comandoAbrir]);
        $lastId = $pdo->lastInsertId();

        $mqttPayload = json_encode([
            "id" => (int)$lastId,
            "comando" => $comandoAbrir
        ]);

        publicarComandoMqtt(strtolower($filial), $mqttPayload);
    }

    // 4. Busca produtos pré-vendidos no banco de dados
    $produtosConsumidos = [];
    $stmtProd = $conexao->prepare("SELECT pv.*, p.descricao AS nomeproduto, p.valorproduto AS valorvenda FROM prevendidos pv JOIN produtos p ON pv.idproduto = p.idproduto WHERE pv.idlocacao = ?");
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
    $resCat = $conexao->query("SELECT idproduto, descricao AS nomeproduto, valorproduto AS valorvenda, estoque FROM produtos ORDER BY descricao ASC");
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

// 5.5 PROCESSAMENTO DE IMPRESSÃO DE PRÉ-VIA (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'imprimir_previa') {
    header('Content-Type: application/json');
    include 'conexao_comando.php';
    include_once 'mqtt_helper.php';

    $final_valorquarto = (float)$_POST['valor_quarto'];
    $valor_adicional_pessoa = (float)$_POST['valor_adicional_pessoa'];
    $valor_adicional_periodo = (float)$_POST['valor_adicional_periodo'];
    $desconto = (float)$_POST['desconto'];
    $acrescimo = (float)$_POST['acrescimo'];
    $justificativa = trim($_POST['justificativa']);
    $numpessoas = (int)$_POST['numpessoas'];
    $produtos_finais = json_decode($_POST['produtos_json'], true);

    $valor_produtos = 0;
    foreach ($produtos_finais as $prod) {
        $valor_produtos += (int)$prod['quantidade'] * (float)$prod['valorvenda'];
    }

    $conexao->begin_transaction();
    try {
        // 1. Atualiza dados principais na registralocado (para que o Java leia atualizado)
        $stmtUp = $conexao->prepare("UPDATE registralocado SET numpessoas = ?, valorquarto = ?, valorconsumo = ? WHERE idlocacao = ?");
        $stmtUp->bind_param("iddi", $numpessoas, $final_valorquarto, $valor_produtos, $idLocacao);
        $stmtUp->execute();
        $stmtUp->close();

        // 2. Limpa e reinsere os produtos em prevendidos (para garantir que a pré-via esteja sincronizada com o que está na tela)
        $stmtClearPrev = $conexao->prepare("DELETE FROM prevendidos WHERE idlocacao = ?");
        $stmtClearPrev->bind_param("i", $idLocacao);
        $stmtClearPrev->execute();
        $stmtClearPrev->close();

        $stmtInsert = $conexao->prepare("INSERT INTO prevendidos (idlocacao, idproduto, quantidade) VALUES (?, ?, ?)");
        foreach ($produtos_finais as $p) {
            $pid = (int)$p['idproduto'];
            $pqty = (int)$p['quantidade'];
            $stmtInsert->bind_param("iii", $idLocacao, $pid, $pqty);
            $stmtInsert->execute();
        }
        $stmtInsert->close();

        // 3. Gerencia justificativas de desconto/acréscimo temporárias
        $stmtDelJust = $conexao->prepare("DELETE FROM justificativa WHERE idlocacao = ?");
        $stmtDelJust->bind_param("i", $idLocacao);
        $stmtDelJust->execute();
        $stmtDelJust->close();

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

        // 4. Grava o comando de impressão remota na fila de comandos do banco
        $comandoStr = "imprimir_previa $idLocacao";
        $tabela_comando = "comandos_" . strtolower($filial);

        $pdoCmd = conectarAoBancoComandosPDO();
        if ($pdoCmd) {
            $stmtCmd = $pdoCmd->prepare("INSERT INTO {$tabela_comando} (id_unidade, comando, executado, criado_em) VALUES (0, :comando, 0, NOW())");
            $stmtCmd->execute([':comando' => $comandoStr]);
            $lastId = $pdoCmd->lastInsertId();

            $mqttPayload = json_encode([
                "id" => (int)$lastId,
                "comando" => $comandoStr
            ]);

            // 5. Dispara a notificação via MQTT
            publicarComandoMqtt(strtolower($filial), $mqttPayload);
        }

        $conexao->commit();
        echo json_encode(['success' => true]);
        exit();
    } catch (Exception $e) {
        $conexao->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
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
                $cmd_string = "encerrar " . $idLocacao;

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

// PING DE ATIVIDADE PARA MANTER A TELA DO JAVA ABERTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ping_checkout') {
    header('Content-Type: application/json');
    $qNum = isset($_POST['quarto']) ? (int)$_POST['quarto'] : 0;
    if ($qNum > 0) {
        include_once 'conexao_comando.php';
        $pdoPing = conectarAoBancoComandosPDO();
        if ($pdoPing) {
            $stmtPing = $pdoPing->prepare("INSERT INTO checkout_session_ping (numeroquarto, ultima_atividade) VALUES (:quarto, NOW()) ON DUPLICATE KEY UPDATE ultima_atividade = NOW()");
            $stmtPing->execute([':quarto' => $qNum]);
        }
    }
    echo json_encode(["status" => "ok"]);
    exit();
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

        // Dispara comando remoto para atualizar os produtos no Java em tempo real
        include_once 'conexao_comando.php';
        include_once 'mqtt_helper.php';
        $pdoCmd = conectarAoBancoComandosPDO();
        if ($pdoCmd) {
            $tabela_comando = "comandos_" . strtolower($filial);
            $cmd_string = "atualizar_produtos " . $idLocacao;

            $stmtCmd = $pdoCmd->prepare("INSERT INTO {$tabela_comando} (id_unidade, comando, executado, criado_em) VALUES (0, :comando, 0, NOW())");
            $stmtCmd->execute([':comando' => $cmd_string]);
            $lastId = $pdoCmd->lastInsertId();

            $mqttPayload = json_encode([
                "id" => (int)$lastId,
                "comando" => $cmd_string
            ]);

            publicarComandoMqtt(strtolower($filial), $mqttPayload);
        }

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
        referrerpolicy="no-referrer" />

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            padding-bottom: 60px;
        }

        label, .form-label {
            color: #94a3b8 !important;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .text-muted {
            color: #94a3b8 !important;
        }
        .text-slate-400 {
            color: #94a3b8 !important;
        }
        span.fw-medium {
            color: #f1f5f9 !important;
        }
        .text-cyan {
            color: #06b6d4 !important;
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
                        <!-- Botão para abrir o Modal de Busca -->
                        <div class="mb-3">
                            <button type="button" class="btn btn-premium-primary w-100 py-2 rounded-pill" data-bs-toggle="modal" data-bs-target="#modalBuscaProduto" onclick="setTimeout(() => document.getElementById('input_busca_produto').focus(), 500)">
                                <i class="fas fa-search me-2"></i> Lançar Consumo / Produto
                            </button>
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
                                <input type="number" step="0.01" class="form-control text-white" id="valor_quarto" name="valor_quarto" value="<?php echo number_format($valorQuarto, 2, '.', ''); ?>" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Adic. Pessoas (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white" id="valor_adicional_pessoa" name="valor_adicional_pessoa" value="<?php echo number_format($valorAdicionalPessoa, 2, '.', ''); ?>" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Adic. Período (R$)</label>
                                <input type="number" step="0.01" class="form-control text-white" id="valor_adicional_periodo" name="valor_adicional_periodo" value="<?php echo number_format($valorAdicionalPeriodo, 2, '.', ''); ?>" readonly>
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



                        <!-- Botão Pré-via e Enviar -->
                        <button type="button" id="btn_previa" class="btn btn-premium-primary w-100 py-2 rounded-pill fs-6 mb-2" onclick="imprimirPrevia()">
                            <i class="fas fa-print me-1"></i> Imprimir Pré-via (Sem Fechar)
                        </button>
                        
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
            
            <!-- Modal de Busca de Produtos -->
            <div class="modal fade" id="modalBuscaProduto" tabindex="-1" aria-labelledby="modalBuscaProdutoLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-sm">
                    <div class="modal-content premium-card border-secondary text-white">
                        <div class="modal-header border-secondary">
                            <h6 class="modal-title fw-bold text-cyan" id="modalBuscaProdutoLabel"><i class="fas fa-search me-1"></i> Lançar Produto</h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-3">
                            <div class="mb-3">
                                <input type="text" id="input_busca_produto" class="form-control text-white" placeholder="Digite para buscar..." oninput="filtrarProdutosCatalogo()">
                            </div>
                            <div class="list-group list-group-flush" id="lista_busca_resultados" style="max-height: 250px; overflow-y: auto;">
                                <!-- Resultados renderizados via JavaScript -->
                            </div>
                        </div>
                        <div class="modal-footer border-secondary py-1">
                            <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3" data-bs-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- JS Scripts e Validações -->
    <script>
        // Dados PHP passados para o Javascript
        const horaInicioStr = "<?php echo isset($horaInicio) ? $horaInicio : ''; ?>";
        const addPessoaTaxa = <?php echo isset($addPessoaTaxa) ? (float)$addPessoaTaxa : 0; ?>;
        
        // Estado dinâmico do Consumo de Produtos
        let listaProdutos = <?php echo isset($produtosConsumidos) ? json_encode($produtosConsumidos) : '[]'; ?>;
        const catalogoProdutos = <?php echo isset($catalogoProdutos) ? json_encode($catalogoProdutos) : '[]'; ?>;

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

        // Filtra e exibe produtos no modal de busca
        function filtrarProdutosCatalogo() {
            const query = document.getElementById('input_busca_produto').value.toLowerCase().trim();
            const container = document.getElementById('lista_busca_resultados');
            container.innerHTML = '';

            const filtrados = catalogoProdutos.filter(p => p.nome.toLowerCase().includes(query));

            if (filtrados.length === 0) {
                container.innerHTML = '<p class="text-muted text-center py-3 mb-0">Nenhum produto encontrado.</p>';
                return;
            }

            filtrados.forEach(p => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action bg-transparent text-white border-secondary py-2 px-1 d-flex justify-content-between align-items-center';
                btn.onclick = () => adicionarProdutoPorId(p.id);
                btn.innerHTML = `
                    <div class="text-start" style="max-width: 70%;">
                        <strong class="d-block text-white text-truncate">${p.nome}</strong>
                        <small class="text-muted">Estoque: ${p.estoque}</small>
                    </div>
                    <span class="text-cyan fw-bold">R$ ${p.preco.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</span>
                `;
                container.appendChild(btn);
            });
        }

        // Adiciona produto ao consumo por ID
        function adicionarProdutoPorId(id) {
            const p = catalogoProdutos.find(item => item.id === id);
            if (!p) return;

            let prod = listaProdutos.find(item => item.idproduto === id);
            if (prod) {
                prod.quantidade += 1;
            } else {
                listaProdutos.push({
                    idproduto: id,
                    nome: p.nome,
                    quantidade: 1,
                    valorvenda: p.preco
                });
            }

            renderizarProdutos();
            saveDraftProducts(); // Salva rascunho no banco via AJAX
            
            // Fecha o modal
            const modalEl = document.getElementById('modalBuscaProduto');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) {
                modal.hide();
            }
            
            // Limpa busca
            document.getElementById('input_busca_produto').value = '';
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

        // Envia comando para imprimir pré-via de consumo
        function imprimirPrevia() {
            const desconto = parseFloat(document.getElementById('desconto').value) || 0;
            const acrescimo = parseFloat(document.getElementById('acrescimo').value) || 0;
            const justificativa = document.getElementById('justificativa').value.trim();
            
            if ((desconto > 0 || acrescimo > 0) && !justificativa) {
                alert("Por favor, preencha a justificativa do desconto/acréscimo antes de imprimir a pré-via.");
                document.getElementById('justificativa').focus();
                return;
            }

            const btnPrevia = document.getElementById('btn_previa');
            btnPrevia.disabled = true;
            btnPrevia.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Enviando para a Impressora...';

            const formData = new FormData();
            formData.append('action', 'imprimir_previa');
            formData.append('valor_quarto', document.getElementById('valor_quarto').value);
            formData.append('valor_adicional_pessoa', document.getElementById('valor_adicional_pessoa').value);
            formData.append('valor_adicional_periodo', document.getElementById('valor_adicional_periodo').value);
            formData.append('desconto', document.getElementById('desconto').value);
            formData.append('acrescimo', document.getElementById('acrescimo').value);
            formData.append('justificativa', document.getElementById('justificativa').value);
            formData.append('numpessoas', document.getElementById('numpessoas').value);
            formData.append('produtos_json', JSON.stringify(listaProdutos));

            fetch("checkout.php?quarto=<?php echo $quarto; ?>", {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btnPrevia.disabled = false;
                btnPrevia.innerHTML = '<i class="fas fa-print me-1"></i> Imprimir Pré-via (Sem Fechar)';
                if (data.success) {
                    alert("Pré-via de consumo enviada para a impressora física com sucesso!");
                } else {
                    alert("Erro ao enviar pré-via: " + data.error);
                }
            })
            .catch(err => {
                btnPrevia.disabled = false;
                btnPrevia.innerHTML = '<i class="fas fa-print me-1"></i> Imprimir Pré-via (Sem Fechar)';
                alert("Erro na conexão ao enviar pré-via.");
            });
        }

        // Loop de Atualização do tempo e renderização inicial
        window.addEventListener('DOMContentLoaded', () => {
            if (!horaInicioStr) return; // Guard clause para quando não houver locação ativa
            atualizarTempoDuracao();
            setInterval(atualizarTempoDuracao, 30000); // 30s
            renderizarProdutos();

            // Roda filtragem inicial ao abrir o modal
            const modalEl = document.getElementById('modalBuscaProduto');
            if (modalEl) {
                modalEl.addEventListener('show.bs.modal', () => {
                    document.getElementById('input_busca_produto').value = '';
                    filtrarProdutosCatalogo();
                });
            }

            // Ping a cada 10s para manter tela do Java aberta
            function enviarPingAtividade() {
                fetch('checkout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'ping_checkout',
                        quarto: <?php echo $quarto; ?>
                    })
                }).catch(err => console.error("Erro no ping de atividade:", err));
            }
            setInterval(enviarPingAtividade, 10000);
        });
    </script>
</body>

</html>
