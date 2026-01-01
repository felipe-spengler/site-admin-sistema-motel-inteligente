<?php
// --- LÓGICA PHP ORIGINAL ---
if (!isset($_COOKIE['usuario_cargo'])) {
    header("Location: index.php");
    exit();
}

$cargo = $_COOKIE['usuario_cargo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carregar = isset($_POST['carregar']) ? (int) $_POST['carregar'] : null;

    if ($carregar === 0) {
        // Lógica de recarregamento
    }
} else {
    // Exibe a mensagem "Recarregando..." antes de recarregar
    echo '<!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Recarregando...</title>
        <style>
            body { display: flex; justify-content: center; align-items: center; height: 100vh; font-family: Arial, sans-serif; background-color: #f8f9fa; }
            .spinner { border: 4px solid rgba(0, 0, 0, 0.1); width: 36px; height: 36px; border-radius: 50%; border-left-color: #007bff; animation: spin 1s ease infinite; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>
    </head>
    <body>
        <div style="text-align: center;">
            <div class="spinner"></div>
            <p style="margin-top: 15px; font-size: 1.2rem;">Recarregando...</p>
        </div>
        <script>
            setTimeout(function() {
                let form = document.createElement("form");
                form.method = "POST";
                form.action = window.location.href;

                let input = document.createElement("input");
                input.type = "hidden";
                input.name = "carregar";
                input.value = "0";

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }, 1000); // Espera 1 segundo antes de recarregar
        </script>
    </body>
    </html>';
    exit();
}
// --- FIM DA LÓGICA PHP ORIGINAL ---
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quartos - Painel Moderno</title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        integrity="sha512-SNJ2I4f5H6eJk95a9y2eW4S4+3p1j6f1w5bE6bWzP+l5R6s5wz+L6Gg0F8+e68kQ8l9z6Kq6g1A0qg7sL4tQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

    <style>
        /* Cores customizadas baseadas no seu código original */
        .quarto-card.limpeza {
            background-color: #ffc107 !important;
            color: #000 !important;
        }

        .quarto-card.manutencao {
            background-color: #6c757d !important;
            color: #fff !important;
        }

        .quarto-card.livre {
            background-color: #198754 !important;
            color: #fff !important;
        }

        .quarto-card.reservado {
            background-color: #0d6efd !important;
            color: #fff !important;
        }

        .quarto-card.ocupado {
            background-color: #dc3545 !important;
            color: #fff !important;
        }

        .quarto-card.pernoite {
            background-color: #e623c2 !important;
            color: #fff !important;
        }

        .quarto-card {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid rgba(0, 0, 0, 0.125);
        }

        .quarto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .numero-quarto {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .status-quarto {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        /* Otimização visual para o menu de navegação - Garante que ele fique no final */
        .page-footer {
            width: 100%;
            margin-top: 30px;
            /* Espaço entre o conteúdo e o menu */
            padding: 15px 0;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container-fluid p-3">
        <h3 class="text-center my-4 text-primary">Painel de Quartos</h3>

        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-6 g-3 justify-content-center">
            <?php
            // --- INÍCIO DA LÓGICA DE CONEXÃO E EXIBIÇÃO DE QUARTOS ---
            // Código de conexão...
            if (!isset($_COOKIE["usuario_filial"])) {
                header("Location: index.php");
                exit();
            }

            $filial = $_COOKIE["usuario_filial"];

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

            $listaQuartos = array();

            $sql = "SELECT * FROM status ORDER BY numeroquarto";

            // Assume-se que conectarAoBanco() e verificarCookie() estão definidas
            $conexao = conectarAoBanco();
            include 'verificar_acesso.php';

            $paginaAtual = basename($_SERVER['PHP_SELF']);
            verificarCookie($conexao, $paginaAtual);
            $result = mysqli_query($conexao, $sql);

            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $classeQuarto = match ($row['atualquarto']) {
                        'limpeza' => 'limpeza',
                        'manutencao' => 'manutencao',
                        'livre' => 'livre',
                        'reservado' => 'reservado',
                        'ocupado-pernoite' => 'pernoite',
                        default => 'ocupado',
                    };

                    $listaQuartos[] = array(
                        'numeroQuarto' => $row['numeroquarto'],
                        'status' => $row['atualquarto']
                    );

                    // Saída HTML para cada Card de Quarto
                    $onclick = ($cargo !== 'comum') ? "onclick='carregarDadosQuarto({$row['numeroquarto']})'" : "";

                    echo "<div class='col'>";
                    echo "<div class='card quarto-card text-center shadow-sm h-100 $classeQuarto' data-numero-quarto='{$row['numeroquarto']}' $onclick>";
                    echo "<div class='card-body p-2 d-flex flex-column justify-content-center align-items-center'>";

                    $icone = match ($row['atualquarto']) {
                        'livre' => 'fa-door-open',
                        'ocupado', 'ocupado-pernoite' => 'fa-bed',
                        'limpeza' => 'fa-broom',
                        'manutencao' => 'fa-wrench',
                        'reservado' => 'fa-bookmark',
                        default => 'fa-door-closed',
                    };
                    echo "<i class='fas $icone fa-2x mb-2'></i>";

                    echo "<p class='numero-quarto card-title m-0'>" . $row['numeroquarto'] . "</p>";
                    echo "<p class='status-quarto card-text m-0'>" . date('d/m/Y H:i', strtotime($row['horastatus'])) . "</p>";



                    echo "<button type='button' class='btn btn-md btn-dark mt-2' 
                                      data-numero='{$row['numeroquarto']}' 
                                      onclick='event.stopPropagation(); abrirMenuSuspensoModal(this);'
                                      title='Opções do Quarto'>
                                    <i class='fas fa-plus'></i>
                                </button>";

                    echo "</div>";
                    echo "</div>";
                    echo "</div>";
                }

                // Botões de Entrada/Saída
            
                echo "<div class='col d-flex flex-column justify-content-center align-items-center'>";
                echo "<button id='botao-entrada' class='btn btn-lg btn-primary mb-2 shadow-sm w-100 h-50'>Entrada</button>";
                echo "<button id='botao-saida' class='btn btn-lg btn-secondary shadow-sm w-100 h-50'>Saída</button>";
                echo "</div>";


                mysqli_close($conexao);
            } else {
                echo "<div class='col-12'><p class='alert alert-info text-center'>Nenhum quarto encontrado.</p></div>";
                mysqli_close($conexao);
            }
            // --- FIM DA LÓGICA DE CONEXÃO E EXIBIÇÃO DE QUARTOS ---
            ?>
        </div>
    </div>

    <div class="modal fade" id="menuSuspensoModal" tabindex="-1" aria-labelledby="menuSuspensoModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="menuSuspensoModalLabel">Ações para o Quarto <span
                            id="quartoModalNumero"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div id="opcoesContainer" class="d-grid gap-2">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger w-100" data-bs-dismiss="modal">Voltar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="detalhesQuartoModal" tabindex="-1" aria-labelledby="detalhesQuartoModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="detalhesQuartoModalLabel">Detalhes da Última Locação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Fechar"></button>
                </div>
                <div class="modal-body" id="detalhesQuartoBody">
                    <div class="text-center p-5">
                        <div class="spinner-border text-primary" role="status"><span
                                class="visually-hidden">Carregando...</span></div>
                        <p class="mt-2">Carregando...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="page-footer text-center bg-light">
        <?php
        if ($cargo !== 'comum') {
            include 'menu.php'; // Inclui o menu de navegação
        } else {
            echo '<div class="text-center my-4">
                            <button onclick="window.location.href=\'index.php\'" class="btn btn-primary btn-lg shadow">
                                <i class="fas fa-sign-in-alt me-2"></i> Voltar para o Login
                            </button>
                        </div>';
        }
        ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

    <script>
        function carregarDadosQuarto(numeroQuarto) {
            const modalDetalhes = new bootstrap.Modal(document.getElementById('detalhesQuartoModal'));
            const modalBody = document.getElementById('detalhesQuartoBody');

            // 1. Prepara o modal para carregamento
            document.getElementById('detalhesQuartoModalLabel').innerText = `Detalhes do Quarto ${numeroQuarto}`;
            modalBody.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Carregando...</span></div><p class="mt-2">Carregando dados do Quarto...</p></div>';
            modalDetalhes.show();

            // 2. Faz a requisição AJAX
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        // 3. Insere o conteúdo no corpo do modal
                        modalBody.innerHTML = xhr.responseText;

                        // Opcional: Ajustar a visualização dos dados (se vierem em um formato muito bruto)
                        // Você precisará garantir que o obter_dados_quarto.php retorne um HTML mais limpo e com classes Bootstrap (ver próximo passo)

                    } else {
                        modalBody.innerHTML = '<div class="alert alert-danger text-center">Erro ao carregar dados do quarto. Status: ' + xhr.status + '</div>';
                    }
                }
            };
            xhr.open("POST", "obter_dados_quarto.php", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.send("numeroquarto=" + numeroQuarto);
        }
    </script>

    <script>
        var listaQuartos = <?php echo json_encode($listaQuartos); ?>;
        var cargo = "<?php echo $cargo; ?>";
        var filial = "<?php echo $filial; ?>";
        var quartoClicado = null;
        var idClicado = null;
        var enviarAcaoExecutada = false;

        function setarIdClicado(idBotao) {
            idClicado = idBotao;
        }

        function enviarAcao() {
            if (enviarAcaoExecutada) {
                console.log('Ação já foi executada.');
                return;
            }
            if (idClicado === null || quartoClicado === null) {
                console.error('Ação ou quarto não definidos.');
                return;
            }

            enviarAcaoExecutada = true;
            var acao = encodeURIComponent(idClicado + ' ' + quartoClicado);

            // 1. Lógica para definir a página de requisição baseada na filial
            let paginaRequisicao = '';

            paginaRequisicao = 'requisicao.php';
            //if (filial === 'toledo') {

            //} else if (filial === 'xanxere') {
            //    paginaRequisicao = 'requisicaoXanxere.php';
            //} else if (filial === 'abelardo') {
            //    paginaRequisicao = 'requisicaoAbelardo.php';
            //} else {
            //    console.error('Filial desconhecida. Usando página padrão.');

            //}

            // 2. Monta a URL completa
            // ATENÇÃO: Se suas páginas de requisição não estiverem na raiz do domínio, ajuste o caminho.
            var url = 'http://motelinteligente.com/' + paginaRequisicao + '?dados=' + acao;

            let alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-dark alert-dismissible fade show fixed-top mx-auto mt-3 shadow-lg';
            alertDiv.style.maxWidth = '300px';
            alertDiv.style.zIndex = '1050';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = '<strong>Sucesso!</strong> Ação de "' + idClicado + '" no Quarto ' + quartoClicado + ' enviada para ' + paginaRequisicao + '!';
            document.body.appendChild(alertDiv);


            setTimeout(function () {
                window.location.href = url;
            }, 1000);
        }

        function abrirMenuSuspensoModal(botao) {
            quartoClicado = botao.getAttribute('data-numero');
            document.getElementById('quartoModalNumero').innerText = quartoClicado;
            let opcoesContainer = document.getElementById('opcoesContainer');
            opcoesContainer.innerHTML = '';

            function criarBotaoModal(texto, acao, corClass = 'btn-primary') {
                let botao = document.createElement('button');
                botao.innerText = texto;
                botao.className = 'btn btn-lg ' + corClass;
                botao.onclick = function () {
                    setarIdClicado(acao);
                    var modalEl = document.getElementById('menuSuspensoModal');
                    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.hide();
                    enviarAcao();
                };
                return botao;
            }

            var statusAtual = listaQuartos.find(quarto => quarto.numeroQuarto === quartoClicado).status;

            // Lógica de botões
            switch (statusAtual) {
                case 'limpeza':
                    opcoesContainer.appendChild(criarBotaoModal('Abrir/Fechar Portão', 'abrir', 'btn-dark'));
                    opcoesContainer.appendChild(criarBotaoModal('Iniciar Manutenção', 'manutencao', 'btn-secondary'));
                    opcoesContainer.appendChild(criarBotaoModal('Reservar Quarto', 'reservar', 'btn-info'));
                    opcoesContainer.appendChild(criarBotaoModal('Disponibilizar Quarto', 'disponibilizar', 'btn-success'));
                    break;
                case 'manutencao':
                    opcoesContainer.appendChild(criarBotaoModal('Abrir/Fechar Portão', 'abrir', 'btn-dark'));
                    opcoesContainer.appendChild(criarBotaoModal('Disponibilizar Quarto', 'disponibilizar', 'btn-success'));
                    break;
                case 'livre':
                    opcoesContainer.appendChild(criarBotaoModal('Abrir/Fechar Portão', 'abrir', 'btn-dark'));
                    opcoesContainer.appendChild(criarBotaoModal('Iniciar Locação', 'locar', 'btn-success'));
                    opcoesContainer.appendChild(criarBotaoModal('Iniciar Manutenção', 'manutencao', 'btn-secondary'));
                    opcoesContainer.appendChild(criarBotaoModal('Reservar Quarto', 'reservar', 'btn-info'));
                    break;
                case 'reservado':
                    opcoesContainer.appendChild(criarBotaoModal('Abrir/Fechar Portão', 'abrir', 'btn-dark'));
                    opcoesContainer.appendChild(criarBotaoModal('Disponibilizar Quarto', 'disponibilizar', 'btn-success'));
                    break;
                default: // 'ocupado', 'ocupado-pernoite'
                    opcoesContainer.appendChild(criarBotaoModal('Abrir/Fechar Portão', 'abrir', 'btn-dark'));
                    break;
            }

            var myModal = new bootstrap.Modal(document.getElementById('menuSuspensoModal'));
            myModal.show();
        }

        // Eventos dos botões Entrada/Saída
        document.getElementById('botao-entrada').addEventListener('click', function (event) {
            setarIdClicado('abrir');
            quartoClicado = 'entrada';
            enviarAcao();
        });

        document.getElementById('botao-saida').addEventListener('click', function (event) {
            setarIdClicado('abrir');
            quartoClicado = 'saida';
            enviarAcao();
        });
    </script>
</body>

</html>