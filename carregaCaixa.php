<?php
function obterIdCaixaMesAtual($conexao) {
    // Obter o primeiro dia do mês atual
    $primeiroDia = date('Y-m-01');
    // Obter o último dia do mês atual
    $ultimoDia = date('Y-m-t');

    $sql = "SELECT id FROM caixa WHERE horaabre >= '$primeiroDia' AND horaabre <= '$ultimoDia'";
    $result = $conexao->query($sql);

    if ($result) {
        $idCaixas = array();

        while ($row = $result->fetch_assoc()) {
            $idCaixas[] = $row['id'];
        }

        $result->free(); // Liberar o resultado
        return $idCaixas;
    } else {
        return array(); // Retorna um array vazio se não houver resultados
    }
}

function obterTotalLocacoes($conexao, $idCaixasMesAtual) {
    if (empty($idCaixasMesAtual)) {
        return 0; // Retorna 0 se o array estiver vazio
    }

    $ids = implode(",", $idCaixasMesAtual);

    $sql = "SELECT SUM((SELECT COUNT(*) FROM registralocado WHERE idcaixaatual IN ($ids))) as totalLocacoes";
            
    $result = $conexao->query($sql);

    if ($result) {
        $row = $result->fetch_assoc();
        $totalLocacoes = ($row['totalLocacoes'] != null) ? $row['totalLocacoes'] : 0;
        $result->free(); // Liberar o resultado
        return $totalLocacoes;
    } else {
        return 0;
    }
}

function calcularMedias($conexao, $idCaixasMesAtual) {
    if (empty($idCaixasMesAtual)) {
        return array(
            "mediaValorConsumo" => 0,
            "mediaValorQuarto" => 0,
            "ticketMedioLocacoes" => 0,
            "totalFaturado" => 0
        );
    }

    $ids = implode(",", $idCaixasMesAtual);
    $consulta = "SELECT valorconsumo, valorquarto FROM registralocado WHERE idcaixaatual IN ($ids)";
    $resultado = $conexao->query($consulta);

    if ($resultado) {
        $somaValorConsumo = 0;
        $somaValorQuarto = 0;
        $somaLocacoes = 0;
        $numRegistros = 0;

        while ($registro = $resultado->fetch_assoc()) {
            $somaValorConsumo += $registro['valorconsumo'];
            $somaValorQuarto += $registro['valorquarto'];
            $somaLocacoes += $registro['valorconsumo'] + $registro['valorquarto'];
            $numRegistros++;
        }

        $resultado->free(); // Liberar o resultado

        if ($numRegistros > 0) {
            $mediaValorConsumo = $somaValorConsumo / $numRegistros;
            $mediaValorQuarto = $somaValorQuarto / $numRegistros;
            $ticketMedioLocacoes = $somaLocacoes / $numRegistros;
        } else {
            $mediaValorConsumo = 0;
            $mediaValorQuarto = 0;
            $ticketMedioLocacoes = 0;
        }
        
        $totalFaturado = $somaValorConsumo + $somaValorQuarto;
        return array(
            "mediaValorConsumo" => $mediaValorConsumo,
            "mediaValorQuarto" => $mediaValorQuarto,
            "ticketMedioLocacoes" => $ticketMedioLocacoes,
            "totalFaturado" => $totalFaturado
        );
    } else {
        return array(
            "mediaValorConsumo" => 0,
            "mediaValorQuarto" => 0,
            "ticketMedioLocacoes" => 0,
            "totalFaturado" => 0
        );
    }
}

function respostaPadraoErro() {
    return array(
        "mediaValorConsumo" => 0,
        "mediaValorQuarto" => 0,
        "ticketMedioLocacoes" => 0
    );
}

function carregarDadosCaixa() {
    $conexao = conectarAoBanco();
    
    if ($conexao === null) {
        return array(
            "erro" => true,
            "mensagem" => "Erro na conexão com o banco de dados."
        );
    } else {
        $idCaixasMesAtual = obterIdCaixaMesAtual($conexao);
        $quantidadeCaixas = count($idCaixasMesAtual);
        
        $numLocacoes = obterTotalLocacoes($conexao, $idCaixasMesAtual);
        $mediaLocacoes = $numLocacoes / date('j');
        $previsaoLocacoes = $mediaLocacoes * date('t');

        $medias = calcularMedias($conexao, $idCaixasMesAtual);
        $faturamentoAtual = $medias["totalFaturado"];
        $mediaFaturamento = $faturamentoAtual / date('j');
        $previsaoFaturamento = $mediaFaturamento * date('t');

        $conexao->close(); // Fechar a conexão antes de retornar os dados

        return array(
            "mediaConsumo" => $medias["mediaValorConsumo"],
            "mediaQuartos" => $medias["mediaValorQuarto"],
            "ticketMedio" => $medias["ticketMedioLocacoes"],
            "medDiariaValor" => $mediaFaturamento,
            "medDiariaNum" => $mediaLocacoes,
            "prevLoca" => $previsaoLocacoes,
            "prevFatura" => $previsaoFaturamento
        );
    }
}
?>
