<?php
header('Content-Type: application/json');

// TRAVA DE SEGURANÇA
$api_key = "MotelInteligente_Secret_Key_2024";
$headers = getallheaders();
if (!isset($headers['X-Api-Key']) || $headers['X-Api-Key'] !== $api_key) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Acesso negado: Chave de segurança inválida.']));
}

$targetDir = "../imagens/produtos/";
$files = [];

if (file_exists($targetDir)) {
    $dir = new DirectoryIterator($targetDir);
    foreach ($dir as $fileinfo) {
        if (!$fileinfo->isDot() && $fileinfo->isFile()) {
            $ext = strtolower($fileinfo->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'jfif'])) {
                $files[] = [
                    'nome' => $fileinfo->getFilename(),
                    'url' => "imagens/produtos/" . $fileinfo->getFilename()
                ];
            }
        }
    }
}

// Ordena as mais recentes primeiro
usort($files, function($a, $b) use ($targetDir) {
    return filemtime($targetDir . $b['nome']) - filemtime($targetDir . $a['nome']);
});

echo json_encode(['success' => true, 'fotos' => $files]);
?>
