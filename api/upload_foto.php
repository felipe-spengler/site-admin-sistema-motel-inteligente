<?php
header('Content-Type: application/json');

// Define o diretório de destino
$targetDir = "../imagens/produtos/";
if (!file_exists($targetDir)) {
    @mkdir($targetDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'Método não permitido']));
}

if (!isset($_FILES['foto'])) {
    die(json_encode(['success' => false, 'message' => 'Nenhum arquivo enviado']));
}

$file = $_FILES['foto'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'jfif'];

if (!in_array($ext, $allowed)) {
    die(json_encode(['success' => false, 'message' => 'Formato não permitido']));
}

// Nome único para evitar conflito
$newFileName = uniqid('prod_') . '.' . $ext;
$targetPath = $targetDir . $newFileName;
$tempFile = $file['tmp_name'];

$success = false;

// Tenta usar a biblioteca GD para comprimir, se existir
if (function_exists('imagecreatefromjpeg')) {
    try {
        list($width, $height) = getimagesize($tempFile);
        $maxDim = 800;
        
        // Carrega a imagem conforme o tipo
        if ($ext == 'png') $img = @imagecreatefrompng($tempFile);
        elseif ($ext == 'webp') $img = @imagecreatefromwebp($tempFile);
        else $img = @imagecreatefromjpeg($tempFile);

        if ($img) {
            if ($width > $maxDim || $height > $maxDim) {
                // Redimensiona
                $ratio = $maxDim / max($width, $height);
                $newW = (int)($width * $ratio);
                $newH = (int)($height * $ratio);
                $newImg = imagecreatetruecolor($newW, $newH);
                
                // Mantém transparência se for PNG
                if ($ext == 'png') {
                    imagealphablending($newImg, false);
                    imagesavealpha($newImg, true);
                }

                imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newW, $newH, $width, $height);
                
                if ($ext == 'png') imagepng($newImg, $targetPath, 8);
                else imagejpeg($newImg, $targetPath, 80);
                
                imagedestroy($newImg);
            } else {
                // Salva apenas com compressão
                if ($ext == 'png') imagepng($img, $targetPath, 8);
                else imagejpeg($img, $targetPath, 80);
            }
            imagedestroy($img);
            $success = true;
        }
    } catch (Exception $e) {
        $success = false;
    }
}

// Se a compressão falhou ou a lib GD não existe, salva o original
if (!$success) {
    if (move_uploaded_file($tempFile, $targetPath)) {
        $success = true;
    }
}

if ($success) {
    $publicPath = "imagens/produtos/" . $newFileName;
    echo json_encode(['success' => true, 'url' => $publicPath]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar arquivo no servidor']);
}
?>
