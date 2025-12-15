<?php
function obterIdCaixaAberto($conexao) {
    $sql = "SELECT id, horaabre FROM caixa WHERE horafecha IS NULL";
    $result = $conexao->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $result->free(); // Libera o resultado
        return $row;
    } else {
        if ($result) $result->free(); // Libera o resultado, se ele foi obtido
        return null;
    }
}

function somarValorQuartoEConsumo($conexao, $idCaixa) {
     $sql = "
        SELECT 
            SUM(rl.valorquarto + rl.valorconsumo) 
            - SUM(IFNULL(j.descontos, 0)) 
            + SUM(IFNULL(j.acrescimos, 0)) AS total
        FROM 
            registralocado rl
        LEFT JOIN (
            SELECT 
                idlocacao,
                SUM(CASE WHEN tipo = 'desconto' THEN valor ELSE 0 END) AS descontos,
                SUM(CASE WHEN tipo = 'acrescimo' THEN valor ELSE 0 END) AS acrescimos
            FROM 
                justificativa
            GROUP BY 
                idlocacao
        ) j ON rl.idlocacao = j.idlocacao
        WHERE 
            rl.idcaixaatual = $idCaixa";

    
    $result = $conexao->query($sql);

    $total = 0;
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $total += $row['total'];
        }
    }

    if ($result) {
        $result->free(); // Libera o resultado
    }

    return $total;
}


function numeroLocacoes($conexao, $idCaixa) {
    $sql = "SELECT COUNT(*) AS total FROM registralocado WHERE idcaixaatual = $idCaixa";
    $result = $conexao->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $total = $row['total'];
        $result->free(); // Libera o resultado
        return $total;
    } else {
        if ($result) $result->free(); // Libera o resultado, se ele foi obtido
        return 0;
    }
}

function obterSaldoAbre($conexao, $idCaixa) {
    $sql = "SELECT saldoabre FROM caixa WHERE id = $idCaixa";
    $result = $conexao->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $saldoAbre = $row['saldoabre'];
        $result->free(); // Libera o resultado
        return $saldoAbre;
    } else {
        if ($result) $result->free(); // Libera o resultado, se ele foi obtido
        return 0;
    }
}

function obterStatusEContarRegistros($conexao) {
    $statusContagem = array(
        "limpeza" => 0,
        "livre" => 0,
        "manutencao" => 0,
        "reservado" => 0,
        "ocupado" => 0
    );

    $sql = "SELECT atualquarto FROM status";
    $result = $conexao->query($sql);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $atualquarto = $row['atualquarto'];

            switch ($atualquarto) {
                case "limpeza":
                    $statusContagem["limpeza"]++;
                    break;
                case "livre":
                    $statusContagem["livre"]++;
                    break;
                case "manutencao":
                    $statusContagem["manutencao"]++;
                    break;
                case "reservado":
                    $statusContagem["reservado"]++;
                    break;
                case "ocupado-periodo":
                    $statusContagem["ocupado"]++;
                    break;
                case "ocupado-pernoite":
                    $statusContagem["ocupado"]++;
                    break;
                default:
                    // Se algum outro status for encontrado, você pode lidar com ele aqui.
                    break;
            }
        }
        $result->free(); // Libera o resultado
    } else {
        if ($result) $result->free(); // Libera o resultado, se ele foi obtido
    }

    return $statusContagem;
}
?>
