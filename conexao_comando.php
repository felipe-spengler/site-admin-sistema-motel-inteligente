<?php
// ==========================================================
// ARQUIVO DE CONEXÃO DEDICADO FINAL E SEGURO (PDO PERSISTENTE)
// OBJETIVO: Garante a reutilização de conexões para evitar o limite de conexões/hora.
// ==========================================================

// Configurações do novo Banco de Dados (Comandos)
$host_comandos = getenv('COMANDOS_DB_HOST') ?: "";
$user_comandos = getenv('COMANDOS_DB_USER') ?: "";
$pass_comandos = getenv('COMANDOS_DB_PASS') ?: "";
$db_comandos = getenv('COMANDOS_DB_NAME') ?: "";

/**
 * Função para estabelecer a conexão PERSISTENTE com o banco de comandos usando PDO.
 *
 * @return PDO|null Retorna o objeto PDO em caso de sucesso, ou NULL em caso de falha.
 */
function conectarAoBancoComandosPDO()
{
    global $host_comandos, $user_comandos, $pass_comandos, $db_comandos;

    $dsn = "mysql:host={$host_comandos};dbname={$db_comandos};charset=utf8mb4";

    $options = [
        // >>> ESSA LINHA ATIVA A CONEXÃO PERSISTENTE <<<
        PDO::ATTR_PERSISTENT => true,

        // Configurações de segurança e tratamento de erros
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Lança exceções em caso de erro SQL
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna resultados como array associativo
        PDO::ATTR_EMULATE_PREPARES => false,                  // Desabilita emulação (melhor segurança/performance)
    ];

    try {
        // Suprime warnings do MySQL que podem causar output
        $pdo = @new PDO($dsn, $user_comandos, $pass_comandos, $options);
        return $pdo;
    } catch (\PDOException $e) {
        // Ação de debug: Registra o erro, mas NÃO imprime no output!
        error_log("Erro Crítico de Conexão PDO (Comandos): " . $e->getMessage());
        return null; // Retorna null para que o script principal trate o erro
    }
}
?>