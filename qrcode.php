<?php
$filial = isset($_GET['filial']) ? htmlspecialchars($_GET['filial']) : '';
$modo = isset($_GET['modo']) ? $_GET['modo'] : 'claro'; // claro ou escuro

// URL base do sistema
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$dominioLocal = $_SERVER['HTTP_HOST'];
$urlBase = $protocolo . "://" . $dominioLocal . "/cardapio.php?filial=" . $filial;

$urlQrCode = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($urlBase);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Código QR Cardápio - <?php echo ucfirst($filial); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #e11d48;
            --dark: #0f172a;
            --bg-escuro: #111827;
            --bg-claro: #ffffff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: #f1f5f9;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Controles (Escondidos na Impressão) */
        .controls {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            width: 100%;
            max-width: 800px;
            text-align: center;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        select, button {
            padding: 12px 20px;
            font-family: 'Outfit';
            font-size: 1rem;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            cursor: pointer;
        }
        
        button.primary { background: var(--accent); color: white; border: none; font-weight: bold; }
        button.secondary { background: #334155; color: white; border: none; }
        
        /* Layout da Placa (A4 Responsivo) */
        .a4-container {
            width: 100%;
            max-width: 210mm; /* Tamanho A4 Real */
            position: relative;
            margin: 0 auto;
        }

        .a4-paper {
            width: 100%;
            min-height: 297mm; /* Proporção A4 */
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-around;
            text-align: center;
            position: relative;
            transition: 0.3s;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        /* Temas */
        .tema-escuro { background: var(--bg-escuro); color: white; }
        .tema-claro { background: var(--bg-claro); color: #111827; border: 1px solid #e2e8f0; }

        .content-box {
            width: 100%;
            max-width: 600px;
            padding: 40px;
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 30px;
            background: rgba(255,255,255,0.02);
            backdrop-filter: blur(5px);
        }

        .tema-claro .content-box { border-color: #e2e8f0; }

        .title {
            font-size: clamp(2rem, 8vw, 4rem);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }

        .title span { color: var(--accent); }

        .subtitle {
            font-size: clamp(1rem, 4vw, 1.4rem);
            opacity: 0.7;
            margin-bottom: 50px;
            max-width: 80%;
            margin-left: auto;
            margin-right: auto;
        }

        .qr-wrapper {
            background: white;
            padding: 25px;
            border-radius: 25px;
            display: inline-block;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 40px;
        }

        .qr-wrapper img {
            width: clamp(200px, 60vw, 350px);
            height: auto;
            display: block;
        }

        .footer-text {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .url-preview {
            font-size: 0.9rem;
            opacity: 0.4;
            word-break: break-all;
        }

        /* Regras de Impressão */
        @media print {
            body { background: white; padding: 0; }
            .controls { display: none; }
            .a4-container { max-width: 100%; }
            .a4-paper { 
                box-shadow: none; 
                border: none;
                width: 100%;
                height: 100vh;
                background: white !important;
                color: black !important;
            }
            .content-box { border-color: #000; }
            .tema-escuro { background: white !important; color: black !important; }
            .tema-escuro .title, .tema-escuro .subtitle { color: black !important; }
        }

        /* Ajuste Mobile */
        @media (max-width: 600px) {
            body { padding: 5px; }
            .a4-paper { min-height: auto; padding: 40px 10px; }
            .content-box { padding: 20px 15px; }
        }
    </style>
</head>
<body class="<?php echo $modo == 'escuro' ? 'body-escuro' : ''; ?>">

    <div class="controls">
        <h2 style="margin-bottom:10px">Gerador de Placa A4</h2>
        <form method="GET">
            <select name="filial" required onchange="this.form.submit()">
                <option value="" disabled <?php echo empty($filial) ? 'selected' : ''; ?>>Escolha a Filial...</option>
                <option value="abelardo" <?php echo $filial == 'abelardo' ? 'selected' : ''; ?>>Abelardo Luz</option>
                <option value="toledo" <?php echo $filial == 'toledo' ? 'selected' : ''; ?>>Toledo</option>
                <option value="xanxere" <?php echo $filial == 'xanxere' ? 'selected' : ''; ?>>Xanxerê</option>
            </select>
            
            <select name="modo" onchange="this.form.submit()">
                <option value="claro" <?php echo $modo == 'claro' ? 'selected' : ''; ?>>Modo Impressão (P&B)</option>
                <option value="escuro" <?php echo $modo == 'escuro' ? 'selected' : ''; ?>>Modo Premium (Escuro)</option>
            </select>

            <div class="btn-group">
                <button type="button" class="primary" onclick="window.print()">🖨️ Imprimir Agora</button>
            </div>
        </form>
    </div>

    <?php if (!empty($filial)): ?>
    <div class="a4-container">
        <div class="a4-paper <?php echo $modo == 'escuro' ? 'tema-escuro' : 'tema-claro'; ?>">
            <div class="content-box">
                <h1 class="title">CARDÁPIO <span>DIGITAL</span></h1>
                <p class="subtitle">Escaneie o código abaixo para ver nossos produtos e ofertas direto no seu celular.</p>
                
                <div class="qr-wrapper">
                    <img src="<?php echo $urlQrCode; ?>" alt="QR Code">
                </div>
                
                <div class="footer-text">Atendimento 24 Horas</div>
                <div class="url-preview"><?php echo $urlBase; ?></div>
            </div>
            
            <div style="margin-top: 40px; font-weight: 700; font-size: 1.2rem; opacity: 0.6;">
                <?php echo strtoupper($filial); ?>
            </div>
        </div>
    </div>
    <?php else: ?>
        <div style="padding: 40px; text-align: center; color: #64748b;">
            <i class="fas fa-arrow-up" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
            Selecione uma filial para gerar a placa.
        </div>
    <?php endif; ?>

</body>
</html>
