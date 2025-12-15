<?php
// ==========================================================
// ARQUIVO DE TESTE DE CONEXÃO E INSERÇÃO (u876938716_comandos)
// OBJETIVO: Testar se a conexão e a inserção do comando 'reservar 4' em 'comandos_toledo' funcionam.
// ==========================================================

// Configura o cabeçalho para texto puro e codificação (melhor visualização de debug)
header('Content-Type: text/plain; charset=utf-8');
echo "--- INICIANDO TESTE DE INSERÇÃO DE COMANDO (DEBUG) ---\n\n";

// 1. Incluir o arquivo de conexão
// Ele deve conter a função conectarAoBancoComandosPDO()
echo "1. Incluindo 'conexao_comando.php'...\n";
include 'conexao_comando.php'; 
echo "   -> Inclusão BEM-SUCEDIDA.\n\n";

// Variáveis de Teste
$filial_teste = "toledo";
$tabela_teste = "comandos_toledo";
$acaoCompleta_teste = "reservar 4";

// Extrai o ID da Unidade (deve ser 4)
$partes = explode(' ', $acaoCompleta_teste);
$id_unidade_teste = 0;
if (count($partes) === 2 && is_numeric($partes[1])) {
    $id_unidade_teste = (int)$partes[1];
}

echo "PARÂMETROS DE TESTE:\n";
echo "   -> Filial de Teste: " . $filial_teste . "\n";
echo "   -> Tabela de Destino: " . $tabela_teste . "\n";
echo "   -> Comando a Inserir: " . $acaoCompleta_teste . "\n";
echo "   -> ID da Unidade (esperado 4): " . $id_unidade_teste . "\n\n";

$pdo = null;

try {
    // 2. Tenta obter a conexão PDO Persistente
    echo "2. Tentando obter a conexão PDO...\n";
    $pdo = conectarAoBancoComandosPDO();
    
    if (!$pdo) {
         // Se retornar null, o erro já deve ter sido registrado pelo conexao_comandos.php
         die("\n!! ERRO CRÍTICO (Fase 2) !!: Falha ao obter a conexão PDO. A função 'conectarAoBancoComandosPDO()' retornou NULL.\n");
    }
    echo "   -> Conexão PDO estabelecida.\n\n";

    // 3. Prepara o SQL de Inserção
    $consultaSQL = "INSERT INTO {$tabela_teste} (id_unidade, comando, executado, criado_em) 
                    VALUES (:id_unidade, :comando, 0, NOW())";
    
    echo "3. Preparando e Executando a consulta SQL:\n";
    echo "   -> SQL: " . $consultaSQL . "\n";
    
    $stmt = $pdo->prepare($consultaSQL);
    
    if ($stmt === false) {
        $errorInfo = $pdo->errorInfo();
        die("\n!! ERRO PDO PREPARE !!: Falha ao preparar SQL. Mensagem: {$errorInfo[2]}\n");
    }

    // 4. Liga os Parâmetros e Executa
    $parametros = [
        ':id_unidade' => $id_unidade_teste,
        ':comando'    => $acaoCompleta_teste
    ];

    echo "   -> Parâmetros a serem ligados:\n";
    print_r($parametros);
    
    if ($stmt->execute($parametros)) {
        $lastId = $pdo->lastInsertId();
        echo "\n   -> EXECUÇÃO BEM-SUCEDIDA. Linhas afetadas: " . $stmt->rowCount() . "\n";
        echo "   -> ID INSERIDO: " . $lastId . "\n";
        
        // 5. Confirmação Lendo o Registro Inserido
        echo "\n5. Verificação: Lendo o registro inserido (ID: {$lastId})...\n";
        $check_sql = "SELECT id, id_unidade, comando, executado, criado_em FROM {$tabela_teste} WHERE id = :id";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([':id' => $lastId]);
        
        if ($registro = $check_stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "   -> REGISTRO ENCONTRADO E CONFIRMADO:\n";
            print_r($registro);
        } else {
             echo "   -> AVISO: Não foi possível recuperar o registro (ID: {$lastId}) para confirmação.\n";
        }
        
    } else {
        $errorInfo = $stmt->errorInfo();
        die("\n!! ERRO PDO EXECUTE !!: Falha na execução. Mensagem: {$errorInfo[2]}\n");
    }

} catch (Exception $e) {
    // Captura qualquer exceção geral (incluindo PDOException se não for tratada no arquivo de conexão)
    die("\n!! ERRO GERAL NO TESTE !!: " . $e->getMessage() . "\n");
} finally {
    echo "\n--- FIM DO TESTE ---\n";
}
?>