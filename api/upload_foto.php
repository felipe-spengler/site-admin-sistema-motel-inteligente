<?php
header('Content-Type: application/json');

// Log de depuração (opcional, pode ver no log do servidor)
error_reporting(E_ALL);
ini_set('display_errors', 0);

$targetDir = "../imagens/produtos/";

// 1. Verificações de Pasta
if (!file_exists($targetDir)) {
    if (!mkdir($targetDir, 0777, true)) {
        die(json_encode(['success' => false, 'message' => "ERRO: Não foi possível criar a pasta de destino em $targetDir. Verifique as permissões da pasta 'imagens'."]));
    }
}

if (!is_writable($targetDir)) {
    die(json_encode(['success' => false, 'message' => "ERRO: O servidor não tem permissão de ESCRITA na pasta $targetDir. Use chmod 777 nessa pasta."]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'Método não permitido. Use POST.']));
}

if (!isset($_FILES['foto'])) {
    die(json_encode(['success' => false, 'message' => 'Nenhum arquivo enviado no campo "foto".']));
}

$file = $_FILES['foto'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    die(json_encode(['success' => false, 'message' => 'Erro interno do PHP no upload: Cod ' . $file['error']]));
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'jfif'];

if (!in_array($ext, $allowed)) {
    die(json_encode(['success' => false, 'message' => "Formato $ext não permitido."]));
}

$newFileName = uniqid('prod_') . '.' . $ext;
$targetPath = $targetDir . $newFileName;
$tempFile = $file['tmp_name'];

$success = false;
$msg = "";

// Tenta comprimir
if (function_exists('imagecreatefromjpeg')) {
    try {
        list($width, $height) = getimagesize($tempFile);
        $maxDim = 800;
        
        if ($ext == 'png') $img = @imagecreatefrompng($tempFile);
        elseif ($ext == 'webp') $img = @imagecreatefromwebp($tempFile);
        else $img = @imagecreatefromjpeg($tempFile);

        if ($img) {
            if ($width > $maxDim || $height > $maxDim) {
                $ratio = $maxDim / max($width, $height);
                $newW = (int)($width * $ratio);
                $newH = (int)($height * $ratio);
                $newImg = imagecreatetruecolor($newW, $newH);
                if ($ext == 'png') {
                    imagealphablending($newImg, false);
                    imagesavealpha($newImg, true);
                }
                imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newW, $newH, $width, $height);
                if ($ext == 'png') $res = imagepng($newImg, $targetPath, 8);
                else $res = imagejpeg($newImg, $targetPath, 80);
                imagedestroy($newImg);
            } else {
                if ($ext == 'png') $res = imagepng($img, $targetPath, 8);
                else $res = imagejpeg($img, $targetPath, 80);
            }
            imagedestroy($img);
            if ($res) $success = true;
            else $msg = "Houve erro ao salvar o arquivo comprimido em $targetPath";
        }
    } catch (Exception $e) {
        $msg = "Erro ao processar imagem: " . $e->getMessage();
    }
}

if (!$success) {
    if (move_uploaded_file($tempFile, $targetPath)) {
        $success = true;
    } else {
        $msg = "ERRO FATAL: move_uploaded_file falhou. Verifique permissões de $targetPath ou se a cota de disco estourou.";
    }
}

if ($success) {
    @chmod($targetPath, 0644);
    $publicPath = "imagens/produtos/" . $newFileName;
    echo json_encode(['success' => true, 'url' => $publicPath]);
} else {
    echo json_encode(['success' => false, 'message' => $msg]);
}
?>
