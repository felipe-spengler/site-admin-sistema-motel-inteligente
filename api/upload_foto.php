<?php
header('Content-Type: application/json');

// Define the target directory relative to this file
$targetDir = "../imagens/produtos/";
if (!file_exists($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'Método não permitido']));
}

if (!isset($_FILES['foto'])) {
    die(json_encode(['success' => false, 'message' => 'Nenhum arquivo enviado']));
}

$file = $_FILES['foto'];
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$allowed = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array(strtolower($ext), $allowed)) {
    die(json_encode(['success' => false, 'message' => 'Formato não permitido. Use JPG, PNG ou WEBP.']));
}

// Generate unique filename
$newFileName = uniqid('prod_') . '.jpg';
$targetFile = $targetDir . $newFileName;

// Compress and resize image
$sourcePath = $file['tmp_name'];
list($width, $height, $type) = getimagesize($sourcePath);

// Max width/height to keep it light
$maxDim = 800;
$newWidth = $width;
$newHeight = $height;

if ($width > $maxDim || $height > $maxDim) {
    if ($width > $height) {
        $newWidth = $maxDim;
        $newHeight = ($height / $width) * $maxDim;
    } else {
        $newHeight = $maxDim;
        $newWidth = ($width / $height) * $maxDim;
    }
}

$imageOut = imagecreatetruecolor($newWidth, $newHeight);
switch ($type) {
    case IMAGETYPE_JPEG:
        $imageIn = imagecreatefromjpeg($sourcePath);
        break;
    case IMAGETYPE_PNG:
        $imageIn = imagecreatefrompng($sourcePath);
        // Preserve transparency if needed, but we output as JPG for size
        imagefill($imageOut, 0, 0, imagecolorallocate($imageOut, 255, 255, 255));
        break;
    case IMAGETYPE_WEBP:
        $imageIn = imagecreatefromwebp($sourcePath);
        break;
    default:
        die(json_encode(['success' => false, 'message' => 'Tipo de imagem não suportado']));
}

imagecopyresampled($imageOut, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

// Save with 70% quality to be VERY light
if (imagejpeg($imageOut, $targetFile, 70)) {
    // Return the PUBLIC URL
    // We assume the site is at the root of the domain provided
    // Let's return a relative path that the cardapio.php can use
    $publicPath = "imagens/produtos/" . $newFileName;
    echo json_encode(['success' => true, 'url' => $publicPath]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar arquivo']);
}

imagedestroy($imageIn);
imagedestroy($imageOut);
?>
