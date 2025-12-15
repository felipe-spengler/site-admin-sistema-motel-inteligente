<?php
// 1. Inclui o arquivo de conexão PDO persistente para comandos
// O arquivo contém a função conectarAoBancoComandosPDO()
include 'conexao_comando.php'; 

header('Content-Type: text/plain; charset=utf-8');

// 2. Tenta obter a conexão (PDO persistente)
$pdo = conectarAoBancoComandosPDO();

// Se a conexão falhar, retorna erro e encerra
if (!$pdo) {
    // É crucial retornar um erro HTTP adequado para que o cliente Java saiba que falhou
    header('HTTP/1.1 503 Service Unavailable');
    echo "Erro Crítico: Serviço de confirmação de comandos indisponível.";
    exit;
}

// 3. Captura e normaliza os parâmetros
$filial = isset($_GET['filial']) ? strtolower(trim($_GET['filial'])) : null;
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$filial || $id <= 0) {
    echo "Parâmetros inválidos (Filial ou ID).";
    exit;
}

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
        echo "Filial inválida.";
        exit;
}

// 5. Prepara a query de UPDATE usando Prepared Statement (PDO)
// O nome da tabela é injetado, mas é seguro pois foi validado pelo switch/case.
$sql = "UPDATE {$tabela} SET executado = 1 WHERE id = :id";
$stmt = $pdo->prepare($sql);

try {
    // 6. Executa o UPDATE, passando o ID como parâmetro seguro
    if ($stmt->execute([':id' => $id])) {
        // Verifica se alguma linha foi realmente afetada
        if ($stmt->rowCount() > 0) {
            echo "Comando {$id} confirmado com sucesso na filial {$filial}!";
        } else {
            echo "Aviso: Comando {$id} já estava confirmado ou não existe.";
        }
    } else {
        // Se a execução falhou por outro motivo (ex: erro SQL)
        echo "Erro ao confirmar comando (SQL): " . $pdo->errorInfo()[2];
    }
} catch (PDOException $e) {
    error_log("Erro PDO na confirmação do comando: " . $e->getMessage());
    echo "Erro interno do servidor ao confirmar comando.";
}

// Em conexões PDO persistentes, não chamamos close() nem setamos $pdo = null.
// O PHP/Servidor Web gerencia a reutilização.
?>