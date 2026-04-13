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

// Verifica se o motel aceita pedidos online
$checkSettings = $mysqli->query("SELECT pedidos_online FROM configuracoes LIMIT 1");
$aceitaPedidos = false;
if($checkSettings && $row = $checkSettings->fetch_assoc()) {
    $aceitaPedidos = ($row['pedidos_online'] == 1);
}

// Buscar todos os produtos ordenados por categoria
$query = "SELECT idproduto, descricao, valorproduto, COALESCE(categoria, 'Diversos') as categoria 
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
            padding: 1.5rem 1rem 100px;
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

        .product-details {
            flex-grow: 1;
        }

        .product-name {
            font-size: 1.1rem;
            font-weight: 500;
            color: #f1f5f9;
        }

        .product-price {
            font-size: 0.9rem;
            display: block;
            margin-top: 4px;
            color: var(--text-muted);
        }

        /* Controles de Quantidade */
        .qty-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0,0,0,0.2);
            padding: 4px;
            border-radius: 12px;
        }

        .btn-qty {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: none;
            background: var(--surface-color);
            color: #fff;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .qty-value {
            font-weight: 700;
            min-width: 20px;
            text-align: center;
            color: var(--accent);
        }

        /* Floating Cart Bar */
        #cart-bar {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            max-width: 860px;
            margin: 0 auto;
            background: var(--accent);
            color: white;
            padding: 16px 24px;
            border-radius: 20px;
            display: none; /* hidden by default */
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(225, 29, 72, 0.4);
            z-index: 1000;
            cursor: pointer;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Modal Pedido */
        #modal-pedido {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(10px);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: var(--surface-color);
            width: 100%;
            max-width: 450px;
            border-radius: 24px;
            padding: 30px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .modal-header { font-size: 1.5rem; font-weight: 700; margin-bottom: 20px; text-align: center; }
        
        input {
            width: 100%;
            background: rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.1);
            color: #fff;
            padding: 15px;
            border-radius: 12px;
            font-size: 1.1rem;
            margin-bottom: 20px;
            outline: none;
            text-align: center;
        }

        .btn-finalizar {
            width: 100%;
            background: var(--accent);
            color: white;
            padding: 15px;
            border-radius: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
        }

        .summary-list {
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 20px;
            background: rgba(0,0,0,0.2);
            padding: 15px;
            border-radius: 12px;
        }
        .summary-item { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; }
        
    </style>
</head>
<body>

    <header>
        <h1>CATÁLOGO DIGITAL</h1>
        <div class="subtitle">Peça pelo celular e receba na sua suíte</div>
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
        <?php if (!$aceitaPedidos): ?>
            <div style="text-align: center; padding: 20px; color: var(--accent); background: rgba(225, 29, 72, 0.1); border-radius: 12px; margin-bottom: 20px;">
                ⚠️ Pedidos online não disponíveis temporariamente. Use o telefone da suíte.
            </div>
        <?php endif; ?>

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
                                <div class="product-details">
                                    <div class="product-name"><?php echo htmlspecialchars($produto['descricao']); ?></div>
                                    <div class="product-price">R$ <?php echo number_format($produto['valorproduto'], 2, ',', '.'); ?></div>
                                </div>
                                
                                <?php if ($aceitaPedidos): ?>
                                <div class="qty-controls">
                                    <button class="btn-qty" onclick="changeQty(<?php echo $produto['idproduto']; ?>, -1, '<?php echo addslashes($produto['descricao']); ?>', <?php echo $produto['valorproduto']; ?>)">-</button>
                                    <div class="qty-value" id="qty-<?php echo $produto['idproduto']; ?>">0</div>
                                    <button class="btn-qty" onclick="changeQty(<?php echo $produto['idproduto']; ?>, 1, '<?php echo addslashes($produto['descricao']); ?>', <?php echo $produto['valorproduto']; ?>)">+</button>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <div id="cart-bar" onclick="openModal()">
        <div><span id="cart-qty">0</span> itens no carrinho</div>
        <div style="font-weight: 700;">Ver Pedido ➔</div>
    </div>

    <!-- Modal Finalização -->
    <div id="modal-pedido">
        <div class="modal-content">
            <div class="modal-header">Finalizar Pedido</div>
            <div class="summary-list" id="cart-summary">
                <!-- Itens aqui -->
            </div>
            
            <label style="display:block; margin-bottom: 8px; color: var(--text-muted); font-size: 0.8rem;">Número da sua Suíte:</label>
            <input type="number" id="room-number" placeholder="Digite o número do quarto">
            
            <button class="btn-finalizar" onclick="submitOrder()">ENVIAR PEDIDO</button>
            <button style="width:100%; background:transparent; border:none; color: var(--text-muted); margin-top: 15px; cursor:pointer;" onclick="closeModal()">Cancelar</button>
        </div>
    </div>

    <script>
        let cart = {};

        function changeQty(id, delta, name, price) {
            if (!cart[id]) {
                cart[id] = { name: name, price: price, qty: 0 };
            }
            
            cart[id].qty += delta;
            if (cart[id].qty < 0) cart[id].qty = 0;
            
            document.getElementById('qty-' + id).innerText = cart[id].qty;
            updateCartBar();
        }

        function updateCartBar() {
            let totalQty = 0;
            for (let id in cart) { totalQty += cart[id].qty; }
            
            const bar = document.getElementById('cart-bar');
            if (totalQty > 0) {
                bar.style.display = 'flex';
                document.getElementById('cart-qty').innerText = totalQty;
            } else {
                bar.style.display = 'none';
            }
        }

        function openModal() {
            const summary = document.getElementById('cart-summary');
            summary.innerHTML = '';
            for (let id in cart) {
                if (cart[id].qty > 0) {
                    summary.innerHTML += `
                        <div class="summary-item">
                            <span>${cart[id].qty}x ${cart[id].name}</span>
                            <span>R$ ${(cart[id].qty * cart[id].price).toFixed(2).replace('.', ',')}</span>
                        </div>
                    `;
                }
            }
            document.getElementById('modal-pedido').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('modal-pedido').style.display = 'none';
        }

        function submitOrder() {
            const room = document.getElementById('room-number').value;
            if (!room) {
                alert("Por favor, informe o número do quarto.");
                return;
            }

            let orderItems = [];
            let total = 0;
            for (let id in cart) {
                if (cart[id].qty > 0) {
                    orderItems.push(`${cart[id].qty}x ${cart[id].name}`);
                    total += (cart[id].qty * cart[id].price);
                }
            }

            const formData = new FormData();
            formData.append('filial', '<?php echo $filial; ?>');
            formData.append('quarto', room);
            formData.append('itens', orderItems.join(', '));
            formData.append('total', total);

            fetch('api/fazer_pedido.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    alert("Pedido enviado com sucesso! Aguarde na suíte.");
                    location.reload();
                } else {
                    alert("Erro ao enviar pedido: " + data.message);
                }
            });
        }

        // Smooth scroll
        document.querySelectorAll('.category-nav a').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) { target.scrollIntoView({ behavior: 'smooth' }); }
            });
        });
    </script>

</body>
</html>
