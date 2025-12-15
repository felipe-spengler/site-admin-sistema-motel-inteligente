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
    <title>Seu Aplicativo</title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <style>
        /* Estilo para o menu popup (Dropdown do Bootstrap) */
        .menu-popup {
            position: absolute;
            z-index: 1050;
            display: none;
        }

        /* Estilo para o novo visual: Card branco com borda colorida */
        .status-card {
            border: 1px solid #dee2e6;
            /* Borda padrão suave */
            border-left: 8px solid !important;
            transition: transform 0.2s, box-shadow 0.2s;
            background-color: #fff;
            /* Fundo branco */
            border-radius: .5rem;
            /* Bordas arredondadas */
        }

        .status-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }

        /* Cores das Bordas (ajustando Limpeza) */
        .status-card.ocupado {
            border-color: #F40909 !important;
        }

        .status-card.disponivel {
            border-color: #4CAF50 !important;
        }

        .status-card.reservado {
            border-color: #3771C8 !important;
        }

        .status-card.manutencao {
            border-color: #5C5C5C !important;
        }

        .status-card.limpeza {
            border-color: #D6BD0E !important;
        }

        /* Amarelo mais escuro para Limpeza */
        .status-card.info {
            border-color: #000 !important;
        }

        /* Ajuste no título do caixa */
        .titulo-caixa-custom {
            color: #ffcccc;
        }

        /* Estilo específico para a cor do número da Limpeza (para usar o tom escuro) */
        .text-limpeza {
            color: #C4A600;
            /* Amarelo mais escuro */
        }
    </style>
</head>

<body class="bg-light">

    <div class="container-fluid p-3 p-md-5 pb-5">

        <div class="card shadow-lg mb-5 border-0 rounded-4">
            <div class="card-body bg-dark text-white rounded-4">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="card-title mb-0 titulo-caixa-custom" id="caixaTitulo" style="cursor: pointer;">
                        Caixa Atual
                    </h3>

                    <a href="AtualCaixa.php" class="btn btn-sm btn-outline-light fw-bold">
                        + Info
                    </a>
                </div>

                <div class="menu-popup dropdown-menu shadow p-0" id="menuPopup">
                    <a class="dropdown-item" href="#" id="reproduzirAudio">Reproduzir Áudio</a>
                </div>

                <div class="row pt-2 text-white">
                    <div class="col-12 col-md-4 mb-2">
                        <small class="text-white-50">Data Abertura:</small><br>
                        <span class="fs-5 fw-normal text-white"><?php echo $idCaixa ? $idCaixa['horaabre'] : '--'; ?></span>
                    </div>
                    <div class="col-12 col-md-4 mb-2">
                        <small class="text-white-50">Entradas:</small><br>
                        <span class="fs-4 fw-bold text-white">
                            R$ <?php echo number_format($total_registralocado, 2, ',', '.'); ?>
                        </span>
                    </div>
                    <div class="col-12 col-md-4 mb-2">
                        <small class="text-white-50">Hospedagens:</small><br>
                        <span class="fs-5 fw-normal text-white"><?php echo $locacoes; ?></span>
                    </div>
                </div>

                <?php if (!$idCaixa): ?>
                    <p class="mt-3 mb-0 text-warning text-center fw-bold">
                        <?php echo $mensagemSemCaixa; ?>
                    </p>
                <?php endif; ?>

            </div>
        </div>

        <h2 class="text-center mb-4 text-dark fw-light">Situação Quartos</h2>

        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-3 g-md-4">

            <div class="col">
                <div class="status-card ocupado card text-dark text-center p-3 shadow-sm h-100">
                    <img src="imagens/icone_ocupado.png" alt="Ocupado" class="mx-auto"
                        style="width: 40px; height: 40px;">
                    <h4 class="display-5 mb-0 mt-2 text-danger" id="ocupados"><?php echo $statusContagem['ocupado']; ?>
                    </h4>
                    <p class="mb-0 text-muted">Ocupados</p>
                </div>
            </div>

            <div class="col">
                <div class="status-card disponivel card text-dark text-center p-3 shadow-sm h-100">
                    <img src="imagens/icone_disponivel.png" alt="Disponível" class="mx-auto"
                        style="width: 40px; height: 40px;">
                    <h4 class="display-5 mb-0 mt-2 text-success" id="disponiveis">
                        <?php echo $statusContagem['livre']; ?>
                    </h4>
                    <p class="mb-0 text-muted">Disponíveis</p>
                </div>
            </div>

            <div class="col">
                <div class="status-card reservado card text-dark text-center p-3 shadow-sm h-100">
                    <img src="imagens/icone_reserva.png" alt="Reservado" class="mx-auto"
                        style="width: 40px; height: 40px;">
                    <h4 class="display-5 mb-0 mt-2 text-primary" id="reservados">
                        <?php echo $statusContagem['reservado']; ?>
                    </h4>
                    <p class="mb-0 text-muted">Reservados</p>
                </div>
            </div>

            <div class="col">
                <div class="status-card manutencao card text-dark text-center p-3 shadow-sm h-100">
                    <img src="imagens/icone_manut.png" alt="Manutenção" class="mx-auto"
                        style="width: 40px; height: 40px;">
                    <h4 class="display-5 mb-0 mt-2 text-secondary" id="manutencao">
                        <?php echo $statusContagem['manutencao']; ?>
                    </h4>
                    <p class="mb-0 text-muted">Manutenção</p>
                </div>
            </div>

            <div class="col">
                <div class="status-card limpeza card text-dark text-center p-3 shadow-sm h-100">
                    <img src="imagens/icone_limpeza.png" alt="Limpeza" class="mx-auto"
                        style="width: 40px; height: 40px;">
                    <h4 class="display-5 mb-0 mt-2 text-limpeza" id="limpeza"><?php echo $statusContagem['limpeza']; ?>
                    </h4>
                    <p class="mb-0 text-muted">Limpeza</p>
                </div>
            </div>

            <div class="col">
                <a href="quartos.php" class="text-decoration-none h-100 d-block">
                    <div
                        class="status-card info card text-white text-center p-3 shadow-sm h-100 d-flex flex-column justify-content-center bg-dark">
                        <h4 class="display-5 mb-0 text-white">+</h4>
                        <p class="mb-0">Mais info</p>
                    </div>
                </a>
            </div>
        </div>

    </div>

    <?php include 'menu.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

    <script>
        // Função para enviar a ação e redirecionar
        function enviarAcao() {

            // Defina a ação que você deseja enviar
            var acao = encodeURIComponent('reproduzir 1'); // Ação a ser enviada

            // Lógica para definir a página de requisição
            let paginaRequisicao = 'requisicao.php';

            // Monta a URL completa
            var url = 'http://motelinteligente.com/' + paginaRequisicao + '?dados=' + acao;

            // Cria e exibe um alerta de sucesso
            let alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-dark alert-dismissible fade show fixed-top mx-auto mt-3 shadow-lg';
            alertDiv.style.maxWidth = '300px';
            alertDiv.style.zIndex = '1050';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = '<strong>Sucesso!</strong> Ação de "reproduzir 1" enviada para ' + paginaRequisicao + '!';
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

        // Cria e configura o objeto Dropdown do Bootstrap (necessário para o show/hide)
        const bsDropdown = new bootstrap.Dropdown(menuPopup);

        // Evento para abrir o menu ao clicar no título "Caixa"
        caixaTitulo.addEventListener('click', (event) => {
            // Garante que o menuPopup (Dropdown) será posicionado próximo ao clique
            menuPopup.style.left = event.pageX + 'px';
            menuPopup.style.top = event.pageY + 'px';
            menuPopup.classList.toggle('show'); // Alterna a visibilidade (show/hide)
        });

        // Fechar o menu clicando em outro lugar
        document.addEventListener('click', (event) => {
            // Se o menu estiver visível E o clique não foi no título NEM dentro do menu
            if (menuPopup.classList.contains('show') && !menuPopup.contains(event.target) && event.target !== caixaTitulo) {
                menuPopup.classList.remove('show');
            }
        });

        // Adiciona ação para o link "Reproduzir Audio"
        reproduzirAudio.addEventListener('click', (event) => {
            event.preventDefault();
            menuPopup.classList.remove('show'); // Esconde o menu após o clique
            enviarAcao();
        });
    </script>
</body>

</html>