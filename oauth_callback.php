<?php
header('Content-Type: text/html; charset=utf-8');

$code = isset($_GET['code']) ? trim($_GET['code']) : null;
$filial = isset($_GET['state']) ? strtolower(trim($_GET['state'])) : null;

if (!$code || !$filial) {
    echo "<h3>Erro: Parâmetros de autenticação inválidos ou ausentes (code/state).</h3>";
    exit;
}

// 1. Mapear filial e incluir conexão correspondente
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
        echo "<h3>Erro: Filial '{$filial}' não encontrada ou inválida.</h3>";
        exit;
}

$conexao = conectarAoBanco();
if (!$conexao) {
    echo "<h3>Erro de Conexão com o Banco de Dados da Filial {$filial}.</h3>";
    exit;
}

// 2. Buscar Client ID e Client Secret do Bling cadastrados
$sql = "SELECT bling_client_id, bling_client_secret FROM configuracoes LIMIT 1";
$result = $conexao->query($sql);
$config = $result ? $result->fetch_assoc() : null;

$client_id = isset($config['bling_client_id']) ? trim($config['bling_client_id']) : null;
$client_secret = isset($config['bling_client_secret']) ? trim($config['bling_client_secret']) : null;

if (!$client_id || !$client_secret) {
    echo "<h3>Erro: Bling Client ID e Client Secret não estão cadastrados nas configurações do sistema local para a filial {$filial}.</h3>";
    $conexao->close();
    exit;
}

// 3. Fazer requisição POST para o Bling trocar o Authorization Code pelo Access/Refresh Token
$token_url = "https://www.bling.com.br/Api/v3/oauth/token";

$post_data = http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code
]);

$auth_header = "Authorization: Basic " . base64_encode($client_id . ":" . $client_secret);

$options = [
    'http' => [
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
                    "Accept: application/json\r\n" .
                    $auth_header . "\r\n",
        'method' => 'POST',
        'content' => $post_data,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($token_url, false, $context);
$http_status = $http_response_header[0];

if (strpos($http_status, '200') === false) {
    echo "<h3>Erro ao autenticar com a API do Bling. Verifique as credenciais.</h3>";
    echo "<p>Resposta do Bling: " . htmlspecialchars($response) . "</p>";
    $conexao->close();
    exit;
}

$data = json_decode($response, true);
$access_token = isset($data['access_token']) ? $data['access_token'] : null;
$refresh_token = isset($data['refresh_token']) ? $data['refresh_token'] : null;
$expires_in = isset($data['expires_in']) ? intval($data['expires_in']) : 21600; // default 6h

if (!$access_token || !$refresh_token) {
    echo "<h3>Erro: Tokens de acesso não foram retornados na resposta do Bling.</h3>";
    $conexao->close();
    exit;
}

// 4. Calcular expiração do token e gravar no banco
$expires_at = date('Y-m-d H:i:s', time() + $expires_in);

$update_stmt = $conexao->prepare("UPDATE configuracoes SET bling_access_token = ?, bling_refresh_token = ?, bling_token_expires_at = ?");
$update_stmt->bind_param("sss", $access_token, $refresh_token, $expires_at);

if ($update_stmt->execute()) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Integração Bling - Sucesso</title>
        <style>
            body { font-family: sans-serif; background-color: #f4f6f9; text-align: center; padding: 50px; }
            .card { background-color: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 30px; max-width: 500px; margin: 0 auto; }
            h2 { color: #2e7d32; }
            p { color: #555; line-height: 1.6; }
            .btn { background-color: #2e7d32; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>🎉 Integração Bling Concluída!</h2>
            <p>Os tokens de autorização da filial <strong><?php echo strtoupper($filial); ?></strong> foram atualizados com sucesso no banco de dados.</p>
            <p>O sistema Java já está autorizado a emitir notas fiscais e renovará o acesso automaticamente.</p>
            <br>
            <p><a href="javascript:window.close();" class="btn">Fechar Janela</a></p>
        </div>
    </body>
    </html>
    <?php
} else {
    echo "<h3>Erro ao salvar os tokens de acesso no banco de dados da filial.</h3>";
}

$update_stmt->close();
$conexao->close();
?>
