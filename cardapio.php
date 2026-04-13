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

// 1. Verifica se o motel aceita pedidos online (com proteção para colunas ausentes)
$aceitaPedidos = false;
$res_config = @$mysqli->query("SELECT * FROM configuracoes LIMIT 1");
if ($res_config && $row = $res_config->fetch_assoc()) {
    $aceitaPedidos = isset($row['pedidos_online']) ? ($row['pedidos_online'] == 1) : false;
}

// 2. Tenta buscar produtos com campos novos, senão usa o modo legado (sem travar)
$query = "SELECT idproduto, descricao, valorproduto, categoria, imagem, detalhes, estoque 
          FROM produtos 
          WHERE descricao NOT LIKE 'Estadia%' 
          AND (categoria IS NULL OR categoria <> 'Sistema')
          ORDER BY categoria ASC, descricao ASC";

$result = @$mysqli->query($query);

// Se falhou, tenta sem as colunas novas
if (!$result) {
    $query = "SELECT idproduto, descricao, valorproduto, 'Diversos' as categoria, NULL as imagem, NULL as detalhes, estoque 
              FROM produtos 
              WHERE descricao NOT LIKE 'Estadia%' 
              ORDER BY descricao ASC";
    $result = $mysqli->query($query);
}

$produtosPorCategoria = [];
$categorias = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cat = isset($row['categoria']) && $row['categoria'] ? $row['categoria'] : 'Diversos';
        $produtosPorCategoria[$cat][] = $row;
        if (!in_array($cat, $categorias)) {
            $categorias[] = $cat;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cardápio Digital - Motel Inteligente</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #FF2E63;
            --secondary: #08D9D6;
            --dark: #252A34;
            --light: #EAEAEA;
            --bg: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --gradient: linear-gradient(135deg, #FF2E63 0%, #ff6b6b 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--light);
            line-height: 1.6;
            padding-bottom: 80px;
        }

        header {
            background: var(--gradient);
            padding: 40px 20px;
            text-align: center;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            box-shadow: 0 10px 30px rgba(255, 46, 99, 0.3);
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        header::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            position: relative;
            z-index: 1;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        header p {
            font-weight: 300;
            opacity: 0.9;
            margin-top: 5px;
            position: relative;
            z-index: 1;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 15px;
        }

        /* Categorias Navigation */
        .category-nav {
            display: flex;
            overflow-x: auto;
            gap: 10px;
            padding: 10px 0 20px;
            margin-bottom: 20px;
            scrollbar-width: none;
            -ms-overflow-style: none;
            position: sticky;
            top: 0;
            background: var(--bg);
            z-index: 10;
        }

        .category-nav::-webkit-scrollbar { display: none; }

        .category-item {
            background: var(--card-bg);
            padding: 10px 20px;
            border-radius: 50px;
            white-space: nowrap;
            color: var(--light);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.1);
            transition: all 0.3s ease;
        }

        .category-item:hover, .category-item.active {
            background: var(--primary);
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(255, 46, 99, 0.4);
        }

        /* Grid */
        .category-section {
            margin-bottom: 40px;
            scroll-margin-top: 80px;
        }

        .category-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            border-left: 5px solid var(--primary);
            padding-left: 15px;
        }

        .category-header h2 {
            font-size: 1.5rem;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }

        .product-card {
            background: var(--card-bg);
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255,255,255,0.05);
            display: flex;
            flex-direction: column;
            backdrop-filter: blur(10px);
        }

        .product-card:hover {
            transform: translateY(-5px);
            border-color: rgba(255, 46, 99, 0.3);
            box-shadow: 0 10px 20px rgba(0,0,0,0.4);
        }

        .product-img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            background: #1e293b;
        }

        .product-content {
            padding: 12px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .product-name {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 4px;
            color: #fff;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 2.8em; /* Garante alinhamento mesmo com nomes curtos */
        }

        .product-desc {
            display: none; /* Esconde descrição na grade para ficar mais compacto */
        }

        .product-bottom {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            margin-top: 10px;
        }

        .product-price {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .order-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            width: 100%;
        }

        .order-btn:hover {
            background: #ff4d7d;
            transform: scale(1.05);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            backdrop-filter: blur(5px);
            overflow-y: auto;
        }

        .modal-content {
            background: #1e293b;
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 30px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .modal-img {
            width: 100%;
            height: 300px;
            object-fit: cover;
        }

        .modal-body {
            padding: 30px;
        }

        .close {
            position: absolute;
            right: 20px;
            top: 20px;
            color: #fff;
            font-size: 30px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
            background: rgba(255, 46, 99, 0.8);
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        /* Cart Footer */
        .cart-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: var(--dark);
            padding: 15px 20px;
            display: none;
            justify-content: space-between;
            align-items: center;
            z-index: 900;
            border-top: 2px solid var(--primary);
            box-shadow: 0 -10px 20px rgba(0,0,0,0.5);
        }

        .product-card.out-of-stock {
            opacity: 0.6;
            filter: grayscale(1);
            pointer-events: none;
        }

        .out-of-stock-badge {
            background: #64748b;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.7rem;
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 5;
        }
            header h1 { font-size: 1.6rem; }
            .products-grid { grid-template-columns: 1fr; }
            .modal-content { width: 95%; margin: 10% auto; }
        }
    </style>
</head>
<body>

    <header>
        <h1>Cardápio Digital</h1>
        <p>Filial: <?php echo ucfirst($filial); ?></p>
    </header>

    <div class="container">
        <!-- Navegação de Categorias -->
        <nav class="category-nav">
            <?php foreach ($categorias as $cat): ?>
                <a href="#cat-<?php echo md5($cat); ?>" class="category-item">
                    <?php echo htmlspecialchars($cat); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (empty($produtosPorCategoria)): ?>
            <div style="text-align: center; padding: 50px;">
                <i class="fas fa-utensils" style="font-size: 3rem; color: var(--card-bg); margin-bottom: 20px;"></i>
                <p>Nenhum produto disponível no momento.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($produtosPorCategoria as $categoria => $produtos): ?>
            <section class="category-section" id="cat-<?php echo md5($categoria); ?>">
                <div class="category-header">
                    <h2><?php echo htmlspecialchars($categoria); ?></h2>
                </div>
                
                <div class="products-grid">
                    <?php foreach ($produtos as $p): 
                        $semEstoque = ($p['estoque'] === '0');
                    ?>
                        <div class="product-card <?php echo $semEstoque ? 'out-of-stock' : ''; ?>" onclick="openDetails(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                            <?php if ($semEstoque): ?>
                                <div class="out-of-stock-badge">INDISPONÍVEL</div>
                            <?php endif; ?>

                            <?php if (isset($p['imagem']) && $p['imagem']): ?>
                                <img src="<?php echo htmlspecialchars($p['imagem']); ?>" class="product-img" loading="lazy">
                            <?php else: ?>
                                <div class="product-img" style="display:flex; align-items:center; justify-content:center; background:#1e293b;">
                                    <i class="fas fa-image" style="font-size: 2.5rem; color: #334155;"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="product-content">
                                <h3 class="product-name"><?php echo htmlspecialchars($p['descricao']); ?></h3>
                                <p class="product-desc"><?php echo htmlspecialchars($p['detalhes'] ?? 'Clique para ver mais detalhes.'); ?></p>
                                
                                <div class="product-bottom">
                                    <span class="product-price">R$ <?php echo number_format($p['valorproduto'], 2, ',', '.'); ?></span>
                                    <?php if ($aceitaPedidos && !$semEstoque): ?>
                                        <button class="order-btn" onclick="event.stopPropagation(); addToCart(<?php echo $p['idproduto']; ?>, '<?php echo addslashes($p['descricao']); ?>', <?php echo $p['valorproduto']; ?>)">
                                            <i class="fas fa-plus"></i> Pedir
                                        </button>
                                    <?php elseif ($semEstoque): ?>
                                        <span style="font-size: 0.8rem; color: #94a3b8;"><i class="fas fa-clock"></i> Esgotado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <!-- Modal de Detalhes -->
    <div id="productModal" class="modal">
        <span class="close" onclick="closeModal()">&times;</span>
        <div class="modal-content">
            <img id="modalImg" src="" class="modal-img">
            <div class="modal-body">
                <h2 id="modalName" style="margin-bottom: 10px; color: var(--primary);"></h2>
                <div id="modalPrice" style="font-size: 1.8rem; font-weight: 700; color: var(--secondary); margin-bottom: 20px;"></div>
                <div style="height: 2px; background: rgba(255,255,255,0.1); margin-bottom: 20px;"></div>
                <h4 style="color: #94a3b8; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px; margin-bottom: 10px;">Descrição do Produto</h4>
                <p id="modalDesc" style="color: var(--light); line-height: 1.8; font-size: 1.1rem;"></p>
                
                <div id="modalAction" style="margin-top: 30px; text-align: center;">
                    <!-- Botões de pedido serão inseridos via JS se $aceitaPedidos -->
                </div>
            </div>
        </div>
    </div>

    <!-- Script de Funcionalidades -->
    <script>
        const aceitaPedidosSite = <?php echo $aceitaPedidos ? 'true' : 'false'; ?>;
        
        function openDetails(p) {
            document.getElementById('modalName').innerText = p.descricao;
            document.getElementById('modalPrice').innerText = 'R$ ' + parseFloat(p.valorproduto).toLocaleString('pt-br', {minimumFractionDigits: 2});
            document.getElementById('modalDesc').innerText = p.detalhes || 'Nenhuma descrição detalhada disponível.';
            
            const modalImg = document.getElementById('modalImg');
            if (p.imagem) {
                modalImg.src = p.imagem;
                modalImg.style.display = 'block';
            } else {
                modalImg.style.display = 'none';
            }
            
            if (aceitaPedidosSite) {
                document.getElementById('modalAction').innerHTML = `<button class="order-btn" style="width:100%; justify-content:center; font-size:1.2rem; padding:15px;" onclick="addToCart(${p.idproduto}, '${p.descricao.replace(/'/g, "\\'")}', ${p.valorproduto})"><i class="fas fa-shopping-basket"></i> ADICIONAR AO PEDIDO</button>`;
            }

            document.getElementById('productModal').style.display = "block";
        }

        function closeModal() {
            document.getElementById('productModal').style.display = "none";
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('productModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>
