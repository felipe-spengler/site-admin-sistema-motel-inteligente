<?php
// Define qual filial deve ser conectada
$filial = isset($_GET['filial']) ? strtolower($_GET['filial']) : (isset($_COOKIE["usuario_filial"]) ? $_COOKIE["usuario_filial"] : null);

// Inclui a conexão correta
switch ($filial) {
    case "abelardo":
        require_once 'conexaoAbelardo.php';
        break;
    case "toledo":
        require_once 'conexao2.php';
        break;
    case "xanxere":
        require_once 'conexaoXanxere.php';
        break;
    default:
        die("Filial inválida ou não informada. Ex de acesso válido: /cardapio.php?filial=xanxere");
}

$mysqli = conectarAoBanco();

// Buscar todos os produtos ordenados por categoria
// Se categoria for null envia para "Diversos"
$query = "SELECT descricao, valorproduto, COALESCE(categoria, 'Diversos') as categoria 
          FROM produtos 
          WHERE descricao NOT LIKE 'Estadia%' -- opcional: pular serviços que não sejam produtos de vitrine
          ORDER BY categoria ASC, descricao ASC";

$result = $mysqli->query($query);
$produtosPorCategoria = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cat = $row['categoria'];
        if (!isset($produtosPorCategoria[$cat])) {
            $produtosPorCategoria[$cat] = [];
        }
        $produtosPorCategoria[$cat][] = $row;
    }
}
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cardápio Digital - Suíte</title>
    <!-- Modern Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --surface-color: #1a2235;
            --text-main: #e2e8f0;
            --text-muted: #94a3b8;
            --accent: #e11d48;
            --accent-glow: rgba(225, 29, 72, 0.3);
            --glass-bg: rgba(26, 34, 53, 0.8);
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
            background: var(--bg-color);
            color: var(--text-main);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(225, 29, 72, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(99, 102, 241, 0.05) 0%, transparent 40%);
            min-height: 100vh;
        }

        header {
            text-align: center;
            padding: 3rem 1.5rem 1.5rem;
            position: relative;
        }

        h1 {
            font-size: 2.2rem;
            margin: 0;
            font-weight: 700;
            letter-spacing: 1px;
            background: linear-gradient(135deg, #fff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle {
            color: var(--text-muted);
            margin-top: 0.5rem;
            font-size: 1rem;
            font-weight: 300;
        }

        main {
            max-width: 800px;
            margin: 0 auto;
            padding: 1rem 1.5rem 4rem;
        }

        .category-block {
            margin-bottom: 2.5rem;
            animation: fadeIn 0.6s ease-out forwards;
            opacity: 0;
        }

        /* Stagger animation for categories */
        <?php 
        $i = 1;
        foreach($produtosPorCategoria as $cat => $items): ?>
        .category-block:nth-child(<?php echo $i++; ?>) {
            animation-delay: <?php echo $i * 0.1; ?>s;
        }
        <?php endforeach; ?>

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .category-title {
            font-size: 1.4rem;
            margin-bottom: 1.2rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--accent);
            display: inline-block;
            font-weight: 500;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            position: relative;
        }

        .category-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 50%;
            height: 2px;
            background: #fff;
            box-shadow: 0 0 10px var(--accent);
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.2rem;
        }

        .product-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 1.2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2), 0 0 15px var(--accent-glow);
            border-color: rgba(225, 29, 72, 0.2);
        }

        .product-card:hover::before {
            opacity: 1;
        }

        .product-info {
            flex-grow: 1;
            padding-right: 1rem;
        }

        .product-name {
            font-size: 1.1rem;
            font-weight: 500;
            color: #f8fafc;
            margin-bottom: 0.3rem;
        }

        .product-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--accent);
            background: rgba(225, 29, 72, 0.1);
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            white-space: nowrap;
        }
        
        .empty-message {
            text-align: center;
            color: var(--text-muted);
            padding: 3rem;
            font-style: italic;
        }

    </style>
</head>
<body>

    <header>
        <h1>CARDÁPIO E CATÁLOGO</h1>
        <div class="subtitle">Peça diretamente na recepção informando seu número da suíte</div>
    </header>

    <main>
        <?php if (empty($produtosPorCategoria)): ?>
            <div class="empty-message">Nenhum produto cadastrado no momento.</div>
        <?php else: ?>
            <?php foreach ($produtosPorCategoria as $categoria => $produtos): ?>
                <div class="category-block">
                    <div class="category-title"><?php echo htmlspecialchars($categoria); ?></div>
                    <div class="product-grid">
                        <?php foreach ($produtos as $produto): ?>
                            <div class="product-card">
                                <div class="product-info">
                                    <div class="product-name"><?php echo htmlspecialchars($produto['descricao']); ?></div>
                                </div>
                                <div class="product-price">
                                    R$ <?php echo number_format($produto['valorproduto'], 2, ',', '.'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

</body>
</html>
