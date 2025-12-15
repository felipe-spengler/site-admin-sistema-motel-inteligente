<?php
// 1. Inclui o arquivo de conexão PDO persistente para comandos
include 'conexao_comando.php'; 

header('Content-Type: application/json; charset=utf-8');

// 2. Tenta obter a conexão (PDO persistente)
$pdo = conectarAoBancoComandosPDO();

// Se a conexão falhar, o script retorna um erro 503 e encerra
if (!$pdo) {
    header('HTTP/1.1 503 Service Unavailable');
    echo json_encode(["erro" => "Serviço de comandos temporariamente indisponível"]);
    exit;
}

// 3. Captura e normaliza o parâmetro da filial
$filial = isset($_GET['filial']) ? strtolower(trim($_GET['filial'])) : null;

// 4. Mapeamento da filial para o nome da tabela
$tabela = '';
switch ($filial) {
    case "abelardo":
        $tabela = "comandos_abelardo";
        break;
    case "toledo":
        $tabela = "comandos_toledo";
        break;
    case "xanxere":
        $tabela = "comandos_xanxere";
        break;
    default:
        // Filial inválida
        echo json_encode(null);
        exit;
}

// 5. Busca o comando mais recente não executado na tabela DINÂMICA
// Usamos PDO::prepare, mas a variável $tabela não pode ser parametrizada 
// (por ser o nome de uma tabela), então ela é injetada após validação (switch/case).
$sql = "SELECT id, comando, id_unidade FROM {$tabela}
        WHERE executado = 0
        ORDER BY id ASC LIMIT 1";

// 6. Prepara e executa a query
// NOTA: 'query' é usado aqui porque a injeção da variável $tabela foi validada pelo switch/case
// e não contém entrada direta do usuário.
$stmt = $pdo->query($sql);

if ($stmt) {
    $row = $stmt->fetch(); // $stmt->fetch() para PDO
    
    // 7. Retorna o resultado
    if ($row) {
        echo json_encode($row);
    } else {
        echo json_encode(null);
    }
} else {
    // Erro na execução da SQL (pode ser problema na tabela)
    error_log("Erro SQL em {$tabela}: " . $pdo->errorInfo()[2]);
    echo json_encode(null);
}

// Em conexões PDO persistentes, não chamamos close() nem setamos $pdo = null.
// O PHP/Servidor Web gerencia a reutilização.
?>