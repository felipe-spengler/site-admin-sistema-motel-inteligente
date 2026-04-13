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
$query = "SELECT descricao, valorproduto, COALESCE(categoria, 'Diversos') as categoria 
          FROM produtos 
          WHERE descricao NOT LIKE 'Estadia%' 
          ORDER BY categoria ASC, descricao ASC";

$result = $mysqli->query($query);
$produtosPorCategoria = [];
$categorias = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cat = $row['categoria'];
        if (!isset($produtosPorCategoria[$cat])) {
            $produtosPorCategoria[$cat] = [];
            $categorias[] = $cat;
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --surface-color: #1a2235;
            --text-main: #e2e8f0;
            --text-muted: #94a3b8;
            --accent: #e11d48;
            --accent-glow: rgba(225, 29, 72, 0.4);
            --glass-bg: rgba(30, 41, 59, 0.7);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
            background: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            scroll-behavior: smooth;
        }

        header {
            padding: 2.5rem 1.5rem 1rem;
            text-align: center;
        }

        h1 {
            font-size: 2rem;
            margin: 0;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #fff;
        }

        .subtitle {
            color: var(--text-muted);
            margin-top: 5px;
            font-size: 0.9rem;
        }

        .category-nav-wrapper {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(11, 15, 25, 0.9);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding: 10px 0;
        }

        .category-nav {
            display: flex;
            overflow-x: auto;
            white-space: nowrap;
            padding: 5px 15px;
            gap: 12px;
            scrollbar-width: none;
        }
        
        .category-nav::-webkit-scrollbar { display: none; }

        .category-nav a {
            text-decoration: none;
            color: var(--text-muted);
            background: rgba(255,255,255,0.05);
            padding: 8px 18px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: 0.3s;
        }

        .category-nav a:active, .category-nav a:hover {
            color: #fff;
            background: var(--accent);
            box-shadow: 0 0 15px var(--accent-glow);
        }

        main {
            max-width: 900px;
            margin: 0 auto;
            padding: 1.5rem 1rem 5rem;
        }

        .category-section {
            margin-bottom: 3rem;
            scroll-margin-top: 80px;
        }

        .category-title {
            font-size: 1.4rem;
            color: #fff;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .category-title::before {
            content: '';
            width: 4px;
            height: 20px;
            background: var(--accent);
            border-radius: 2px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }

        .product-card {
            background: var(--glass-bg);
            border-radius: 16px;
            padding: 1.2rem;
            border: 1px solid rgba(255,255,255,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: 0.2s;
        }

        .product-name {
            font-size: 1.1rem;
            font-weight: 500;
            color: #f1f5f9;
        }

        .product-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent);
            background: rgba(225, 29, 72, 0.1);
            padding: 5px 12px;
            border-radius: 8px;
            white-space: nowrap;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

    </style>
</head>
<body>

    <header>
        <h1>CATÁLOGO DIGITAL</h1>
        <div class="subtitle">Peça diretamente na recepção informando o número da suite</div>
    </header>

    <?php if (!empty($produtosPorCategoria)): ?>
    <div class="category-nav-wrapper">
        <div class="category-nav">
            <?php foreach ($categorias as $cat): ?>
                <a href="#cat-<?php echo md5($cat); ?>"><?php echo htmlspecialchars($cat); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <main>
        <?php if (empty($produtosPorCategoria)): ?>
            <div class="empty-state">
                <p>Nenhum item disponível no momento.</p>
            </div>
        <?php else: ?>
            <?php foreach ($produtosPorCategoria as $categoria => $produtos): ?>
                <section class="category-section" id="cat-<?php echo md5($categoria); ?>">
                    <h2 class="category-title"><?php echo htmlspecialchars($categoria); ?></h2>
                    <div class="product-grid">
                        <?php foreach ($produtos as $produto): ?>
                            <div class="product-card">
                                <div class="product-name"><?php echo htmlspecialchars($produto['descricao']); ?></div>
                                <div class="product-price">R$ <?php echo number_format($produto['valorproduto'], 2, ',', '.'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <script>
        document.querySelectorAll('.category-nav a').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    </script>

</body>
</html>
