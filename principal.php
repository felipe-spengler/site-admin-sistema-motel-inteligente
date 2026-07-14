<?php
// Verifica se o cookie da filial existe
if (!isset($_COOKIE["usuario_filial"])) {
    header("Location: index.php");
    exit();
}

$filial = $_COOKIE["usuario_filial"];

// Inclui a conexão correta
switch ($filial) {
    case "abelardo":
        include 'conexaoAbelardo.php';
        break;
    case "toledo":
        include 'conexao2.php';
        break;
    case "xanxere":
        include 'conexaoXanxere.php';
        break;
    case "venus":
        include 'conexaoVenus.php';
        break;
    default:
        die("Filial inválida.");
}
include 'carregaPrincipal.php';
include 'verificar_acesso.php';

$conexao = conectarAoBanco();
$paginaAtual = basename($_SERVER['PHP_SELF']);
verificarCookie($conexao, $paginaAtual);

$idCaixa = obterIdCaixaAberto($conexao);

if ($idCaixa) {
    $total_registralocado = somarValorQuartoEConsumo($conexao, $idCaixa['id']);
    $saldoabre = obterSaldoAbre($conexao, $idCaixa['id']);
    $locacoes = numeroLocacoes($conexao, $idCaixa['id']);
} else {
    $mensagemSemCaixa = "Nenhum caixa está aberto no momento.";
    $total_registralocado = 0;
    $locacoes = 0;
}

$statusContagem = obterStatusEContarRegistros($conexao);

$conexao->close(); // Fechar a conexão
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Motel Inteligente</title>
    <link class="favicon" rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <!-- Google Fonts - Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FontAwesome for Premium Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --card-border: rgba(0, 0, 0, 0.08);
            
            --color-ocupado: #ef4444;
            --color-disponivel: #10b981;
            --color-reservado: #3b82f6;
            --color-manutencao: #64748b;
            --color-limpeza: #eab308;
            
            --font-primary: 'Inter', sans-serif;
            --font-title: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: #1e293b;
            font-family: var(--font-primary);
            min-height: 100vh;
            padding-bottom: 80px; /* Space for the bottom navbar */
            overflow-x: hidden;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Modern White Card style */
        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 1.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .glass-card.dark-version {
            background: #0f172a;
            border-color: rgba(255, 255, 255, 0.05);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.3);
        }

        .glass-card:hover {
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.12);
        }

        .glass-card.dark-version:hover {
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.4);
            border-color: rgba(255, 255, 255, 0.1);
        }

        /* Status Cards Style */
        .status-grid-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 1.25rem;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            z-index: 1;
            box-shadow: 0 4px 15px -3px rgba(0,0,0,0.04);
        }

        .status-grid-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            transition: all 0.3s ease;
        }

        .status-grid-card.ocupado::before { background: var(--color-ocupado); }
        .status-grid-card.disponivel::before { background: var(--color-disponivel); }
        .status-grid-card.reservado::before { background: var(--color-reservado); }
        .status-grid-card.manutencao::before { background: var(--color-manutencao); }
        .status-grid-card.limpeza::before { background: var(--color-limpeza); }
        .status-grid-card.info::before { background: linear-gradient(90deg, #3b82f6, #8b5cf6); }

        .status-grid-card:hover {
            transform: translateY(-5px);
            border-color: rgba(0, 0, 0, 0.15);
        }

        /* Accent glow on hover (subtle on white theme) */
        .status-grid-card.ocupado:hover { box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.15); }
        .status-grid-card.disponivel:hover { box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.15); }
        .status-grid-card.reservado:hover { box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.15); }
        .status-grid-card.manutencao:hover { box-shadow: 0 10px 25px -5px rgba(100, 116, 139, 0.15); }
        .status-grid-card.limpeza:hover { box-shadow: 0 10px 25px -5px rgba(234, 179, 8, 0.15); }

        /* Highlighted Info Card (Dark inverted version for contrast) */
        .status-grid-card.info {
            background: #0f172a;
            border-color: #0f172a;
            color: #ffffff !important;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2);
        }
        
        .status-grid-card.info .status-number {
            color: #ffffff !important;
        }

        .status-grid-card.info .status-label {
            color: #94a3b8;
        }

        .status-grid-card.info:hover {
            background: #1e293b;
            border-color: #1e293b;
            box-shadow: 0 15px 30px -5px rgba(15, 23, 42, 0.35);
        }

        .icon-wrapper {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
        }

        .status-grid-card.info .icon-wrapper {
            background: rgba(255, 255, 255, 0.06);
        }

        .status-grid-card:hover .icon-wrapper {
            transform: scale(1.1);
            background: rgba(0, 0, 0, 0.06);
        }

        .status-grid-card.info:hover .icon-wrapper {
            background: rgba(255, 255, 255, 0.12);
        }

        .status-number {
            font-family: var(--font-title);
            font-size: 2.25rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .status-label {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Title styling */
        .page-title {
            font-family: var(--font-title);
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .section-title {
            font-family: var(--font-title);
            font-weight: 600;
            position: relative;
            display: inline-block;
            margin-bottom: 1.5rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 3px;
            background: #3b82f6;
            border-radius: 2px;
        }

        /* Menu Popup */
        .menu-popup {
            position: absolute;
            z-index: 1050;
            display: none;
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .menu-popup .dropdown-item {
            color: #1e293b;
            transition: all 0.2s;
        }
        
        .menu-popup .dropdown-item:hover {
            background: #f1f5f9;
        }
    </style>
</head>

<body>

    <div class="container-fluid p-3 p-md-5 main-container">
        
        <!-- Header / Logo Area -->
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-3">
                <img src="imagens/iconeMI.png" alt="Logo" style="width: 42px; height: 42px; object-fit: contain;">
                <h1 class="page-title fs-3 mb-0 text-dark">Motel <span class="text-primary">Inteligente</span></h1>
            </div>
            <div class="badge bg-primary px-3 py-2 rounded-pill font-monospace text-uppercase" style="font-size: 0.75rem;">
                Filial: <?php echo htmlspecialchars($filial); ?>
            </div>
        </div>

        <!-- Glassmorphism Caixa Card (Dark Version for highlight) -->
        <div class="card glass-card dark-version mb-5 border-0">
            <div class="card-body p-4 text-white">

                <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary-subtle" style="--bs-border-opacity: .15;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-cash-register text-primary fs-4"></i>
                        <h3 class="card-title mb-0 fs-4 fw-semibold text-white" id="caixaTitulo" style="cursor: pointer; user-select: none;">
                            Caixa Atual <i class="fa-solid fa-chevron-down fs-6 ms-1 text-muted"></i>
                        </h3>
                    </div>

                    <a href="AtualCaixa.php" class="btn btn-sm btn-outline-light px-3 rounded-pill fw-semibold d-flex align-items-center gap-1">
                        <i class="fa-solid fa-circle-info"></i> Detalhes
                    </a>
                </div>

                <!-- Dropdown Menu -->
                <div class="menu-popup dropdown-menu shadow p-0" id="menuPopup">
                    <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" href="#" id="reproduzirAudio">
                        <i class="fa-solid fa-volume-high text-primary"></i> Reproduzir Áudio
                    </a>
                </div>

                <div class="row g-4">
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.05);">
                            <small class="text-secondary text-uppercase fw-semibold font-monospace" style="font-size: 0.75rem; letter-spacing: 0.5px;">Data Abertura</small>
                            <div class="fs-5 fw-medium text-white mt-1">
                                <i class="fa-regular fa-clock text-primary me-2"></i><?php echo $idCaixa ? htmlspecialchars($idCaixa['horaabre']) : '--'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.05);">
                            <small class="text-secondary text-uppercase fw-semibold font-monospace" style="font-size: 0.75rem; letter-spacing: 0.5px;">Total Entradas</small>
                            <div class="fs-4 fw-bold text-success mt-1">
                                R$ <?php echo number_format($total_registralocado, 2, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.05);">
                            <small class="text-secondary text-uppercase fw-semibold font-monospace" style="font-size: 0.75rem; letter-spacing: 0.5px;">Hospedagens</small>
                            <div class="fs-5 fw-semibold text-info mt-1">
                                <i class="fa-solid fa-bed text-info me-2"></i><?php echo $locacoes; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!$idCaixa): ?>
                    <div class="mt-4 p-3 rounded-3 bg-warning-subtle text-warning border border-warning border-opacity-10 text-center fw-semibold">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($mensagemSemCaixa); ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Quartos Section -->
        <div class="text-center mb-4">
            <h2 class="section-title text-dark fs-3">Situação dos Quartos</h2>
        </div>

        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-4">

            <!-- Ocupado -->
            <div class="col">
                <div class="status-grid-card ocupado">
                    <div class="icon-wrapper">
                        <img src="imagens/icone_ocupado.png" alt="Ocupado" style="width: 32px; height: 32px; object-fit: contain;">
                    </div>
                    <div>
                        <div class="status-number text-danger" id="ocupados"><?php echo (int)$statusContagem['ocupado']; ?></div>
                        <div class="status-label">Ocupados</div>
                    </div>
                </div>
            </div>

            <!-- Disponível -->
            <div class="col">
                <div class="status-grid-card disponivel">
                    <div class="icon-wrapper">
                        <img src="imagens/icone_disponivel.png" alt="Disponível" style="width: 32px; height: 32px; object-fit: contain;">
                    </div>
                    <div>
                        <div class="status-number text-success" id="disponiveis"><?php echo (int)$statusContagem['livre']; ?></div>
                        <div class="status-label">Disponíveis</div>
                    </div>
                </div>
            </div>

            <!-- Reservado -->
            <div class="col">
                <div class="status-grid-card reservado">
                    <div class="icon-wrapper">
                        <img src="imagens/icone_reserva.png" alt="Reservado" style="width: 32px; height: 32px; object-fit: contain;">
                    </div>
                    <div>
                        <div class="status-number text-primary" id="reservados"><?php echo (int)$statusContagem['reservado']; ?></div>
                        <div class="status-label">Reservados</div>
                    </div>
                </div>
            </div>

            <!-- Manutenção -->
            <div class="col">
                <div class="status-grid-card manutencao">
                    <div class="icon-wrapper">
                        <img src="imagens/icone_manut.png" alt="Manutenção" style="width: 32px; height: 32px; object-fit: contain;">
                    </div>
                    <div>
                        <div class="status-number text-secondary" id="manutencao"><?php echo (int)$statusContagem['manutencao']; ?></div>
                        <div class="status-label">Manutenção</div>
                    </div>
                </div>
            </div>

            <!-- Limpeza -->
            <div class="col">
                <div class="status-grid-card limpeza">
                    <div class="icon-wrapper">
                        <img src="imagens/icone_limpeza.png" alt="Limpeza" style="width: 32px; height: 32px; object-fit: contain;">
                    </div>
                    <div>
                        <div class="status-number text-warning" id="limpeza"><?php echo (int)$statusContagem['limpeza']; ?></div>
                        <div class="status-label">Limpeza</div>
                    </div>
                </div>
            </div>

            <!-- Mais info (Destacado escuro) -->
            <div class="col">
                <a href="quartos.php" class="text-decoration-none h-100 d-block">
                    <div class="status-grid-card info">
                        <div class="icon-wrapper">
                            <i class="fa-solid fa-ellipsis text-white fs-4"></i>
                        </div>
                        <div>
                            <div class="status-number text-white">+</div>
                            <div class="status-label">Ver Detalhes</div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

    </div>

    <?php include 'menu.php'; ?>
    
    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

    <script>
        // Função para enviar a ação e redirecionar
        function enviarAcao() {
            var acao = encodeURIComponent('reproduzir 1'); // Ação a ser enviada
            let paginaRequisicao = 'requisicao.php';
            var url = 'http://motelinteligente.com/' + paginaRequisicao + '?dados=' + acao;

            // Cria e exibe um alerta de sucesso moderno (versão light)
            let alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-primary alert-dismissible fade show fixed-top mx-auto mt-3 shadow-lg border border-primary border-opacity-15';
            alertDiv.style.maxWidth = '320px';
            alertDiv.style.zIndex = '1060';
            alertDiv.style.background = '#ffffff';
            alertDiv.style.color = '#1e293b';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-check text-success fs-5"></i>
                    <div>
                        <strong>Sucesso!</strong><br>
                        <span style="font-size: 0.85rem; color: #64748b;">Ação enviada para ${paginaRequisicao}!</span>
                    </div>
                </div>
            `;
            document.body.appendChild(alertDiv);

            // Redireciona após um delay
            setTimeout(function () {
                window.location.href = url;
            }, 1000);
        }

        // Referências
        const caixaTitulo = document.getElementById('caixaTitulo');
        const menuPopup = document.getElementById('menuPopup');
        const reproduzirAudio = document.getElementById('reproduzirAudio');

        // Evento para abrir o menu ao clicar no título "Caixa"
        caixaTitulo.addEventListener('click', (event) => {
            event.stopPropagation();
            menuPopup.style.left = event.currentTarget.getBoundingClientRect().left + 'px';
            menuPopup.style.top = (event.currentTarget.getBoundingClientRect().bottom + window.scrollY) + 'px';
            menuPopup.classList.toggle('show');
        });

        // Fechar o menu clicando em outro lugar
        document.addEventListener('click', (event) => {
            if (menuPopup.classList.contains('show') && !menuPopup.contains(event.target) && event.target !== caixaTitulo) {
                menuPopup.classList.remove('show');
            }
        });

        // Adiciona ação para o link "Reproduzir Audio"
        reproduzirAudio.addEventListener('click', (event) => {
            event.preventDefault();
            menuPopup.classList.remove('show');
            enviarAcao();
        });
    </script>
</body>

</html>
