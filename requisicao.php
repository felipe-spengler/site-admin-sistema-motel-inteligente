<?php
// ==========================================================
// SCRIPT EMISSOR DE COMANDOS (VERSÃO FINAL)
// Objetivo: Inserir comando na tabela CORRETA do DB DEDICADO.
// ==========================================================

// Define a página de destino padrão
$pagina_destino = "quartos.php";

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    // Lê o parâmetro 'dados' da URL. Exemplo: 'disponibilizar 2'
    $acaoCompleta = filter_input(INPUT_GET, 'dados', FILTER_SANITIZE_SPECIAL_CHARS);
    
    // 1. TENTA LER A FILIAL DA URL
    $filial = filter_input(INPUT_GET, 'filial', FILTER_SANITIZE_SPECIAL_CHARS);

    // 2. SE NÃO ESTIVER NA URL, TENTA LER DO COOKIE
    if (empty($filial) && isset($_COOKIE["usuario_filial"])) {
        $filial = filter_var($_COOKIE["usuario_filial"], FILTER_SANITIZE_SPECIAL_CHARS);
    }

    // 3. Validação de Parâmetros
    if (!$acaoCompleta || empty($filial)) {
        // Redireciona para o destino padrão se faltar dados ou filial
        error_log("Tentativa de requisicao.php falhou: Acao ({$acaoCompleta}) ou Filial ({$filial}) ausente.");
        header("Location: " . $pagina_destino);
        exit();
    }

    // 4. Determina a página de destino
    if ($acaoCompleta === "reproduzir 1") {
        $pagina_destino = "principal.php";
    }

    // 5. Mapeamento da filial para a tabela
    $tabela = '';
    $filialNormalizada = strtolower(trim($filial));
    switch ($filialNormalizada) {
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
            error_log("Filial inválida recebida: " . $filial);
            header("Location: " . $pagina_destino);
            exit();
    }

    // 6. Inclusão da CONEXÃO DEDICADA (PDO)
    include 'conexao_comando.php'; 

    $pdo = null;
    $stmt = null;    

    try {
        // Usa a função de conexão PDO
        $pdo = conectarAoBancoComandosPDO();
        
        if (!$pdo) {
             // Erro já logado dentro de conectarAoBancoComandosPDO()
             error_log("Erro crítico: Conexão retornou NULL durante a requisicao.");
        } else {
            // Extrai o ID da Unidade
            $partes = explode(' ', $acaoCompleta);
            $id_unidade = 0;
            if (count($partes) === 2 && is_numeric($partes[1])) {
                $id_unidade = (int)$partes[1];
            }
            
            // Prepara a inserção na tabela correta ($tabela)
            $consultaSQL = "INSERT INTO {$tabela} (id_unidade, comando, executado, criado_em) 
                            VALUES (:id_unidade, :comando, 0, NOW())";
            
            $stmt = $pdo->prepare($consultaSQL);
            
            if ($stmt === false) {
                throw new Exception("Erro ao preparar a consulta: " . implode(" ", $pdo->errorInfo()));
            }

            // Liga os parâmetros (PDO com nomeação)
            $parametros = [
                ':id_unidade' => $id_unidade,
                ':comando'    => $acaoCompleta
            ];
            
            if (!$stmt->execute($parametros)) {
                 throw new Exception("Erro ao executar o comando: " . implode(" ", $stmt->errorInfo()));
            }
        }

    } catch (Exception $e) {
        // ERRO. Faz o log do erro no servidor.
        error_log("Erro na inserção de comando em {$tabela}: " . $e->getMessage());
        
    } finally {
        // REDIRECIONA para a página específica (PRINCIPAL ou QUARTOS)
        header("Location: " . $pagina_destino);
        exit();
    }

} else {
    // Se o método não for GET, redireciona para a página padrão (quartos.php)
    header("Location: " . $pagina_destino);
    exit();
}
?>