<?php
$filial = isset($_GET['filial']) ? htmlspecialchars($_GET['filial']) : '';

// URL base do sistema - Troque pelo seu domínio real se não estiver pegando automático
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
    <title>Gerador de QR Code - Cardápio</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #e11d48;
            --dark: #0f172a;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Menu de controle de topo (Não aparece na impressão) */
        .controls {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            width: 100%;
            max-width: 600px;
            text-align: center;
        }

        .controls form {
            display: gap;
            gap: 15px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }

        select, button {
            padding: 10px 15px;
            font-family: 'Outfit';
            font-size: 1rem;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
        }
        
        button {
            background: var(--accent);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: 0.3s;
        }
        
        button:hover {
            background: #be123c;
        }

        /* Folha A4 para Impressão */
        .a4-paper {
            background: #111827; /* Fundo simulando escurinho chic do motel */
            color: #fff;
            width: 210mm;
            min-height: 297mm;
            padding: 0;
            position: relative;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .a4-paper::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: 
                radial-gradient(circle at 10% 20%, rgba(225, 29, 72, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 90% 80%, rgba(99, 102, 241, 0.1) 0%, transparent 50%);
            z-index: 1;
        }

        .content-box {
            z-index: 2;
            width: 80%;
            background: rgba(255, 255, 255, 0.03);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 50px 30px;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .title {
            font-size: 3.5rem;
            font-weight: 700;
            color: #fff;
            margin: 0;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .title span {
            color: var(--accent);
        }

        .subtitle {
            font-size: 1.5rem;
            color: #94a3b8;
            margin-top: 10px;
            margin-bottom: 40px;
            font-weight: 300;
        }

        .qr-wrapper {
            background: white;
            padding: 20px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 40px;
            box-shadow: 0 0 30px rgba(225, 29, 72, 0.2);
        }

        .qr-wrapper img {
            width: 300px;
            height: 300px;
            display: block;
        }

        .footer-text {
            font-size: 1.2rem;
            color: #cbd5e1;
            font-weight: 500;
            margin-top: 20px;
        }

        .url-preview {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.3);
            margin-top: 30px;
            word-break: break-all;
        }

        /* Regras de Impressão */
        @media print {
            body {
                background: none;
                padding: 0;
            }
            .controls {
                display: none;
            }
            .a4-paper {
                box-shadow: none;
                width: 100%;
                height: 100vh;
                margin: 0;
                /* Assegura impressão de graficos pesados (cores de fundo) */
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <div class="controls">
        <h3>Gerador de Placa - Cardápio Digital</h3>
        <p>Selecione a filial para gerar o QR Code correto, depois clique em Imprimir.</p>
        <form method="GET">
            <select name="filial" required>
                <option value="" disabled <?php echo empty($filial) ? 'selected' : ''; ?>>Escolha a Filial...</option>
                <option value="abelardo" <?php echo $filial == 'abelardo' ? 'selected' : ''; ?>>Motel Abelardo Luz</option>
                <option value="toledo" <?php echo $filial == 'toledo' ? 'selected' : ''; ?>>Motel Toledo</option>
                <option value="xanxere" <?php echo $filial == 'xanxere' ? 'selected' : ''; ?>>Motel Xanxerê</option>
            </select>
            <button type="submit">Gerar Placa</button>
            <button type="button" onclick="window.print()" style="background:#0f172a; margin-left:10px;">🖨️ Imprimir Placa</button>
        </form>
    </div>

    <?php if (!empty($filial)): ?>
    <div class="a4-paper" id="placa">
        <div class="content-box">
            <h1 class="title">CARDÁPIO <span>DIGITAL</span></h1>
            <div class="subtitle">Aponte a câmera do seu celular para o QR Code abaixo para visualizar nossos produtos e fazer seu pedido na recepção.</div>
            
            <div class="qr-wrapper">
                <img src="<?php echo $urlQrCode; ?>" alt="QR Code Cardápio">
            </div>
            
            <div class="footer-text">✨ Aberto e servindo 24 Horas ✨</div>
            <div class="url-preview"><?php echo $urlBase; ?></div>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>
