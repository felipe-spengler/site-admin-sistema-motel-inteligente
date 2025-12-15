<?php
// --- Lógica de Back-end (Mantida) ---

// Inclui funções de carregamento de caixa
include 'carregaCaixa.php';

// Verifica se o cookie da filial existe
if (!isset($_COOKIE["usuario_filial"])) {
    header("Location: index.php");
    exit();
}

$filial = $_COOKIE["usuario_filial"];

// Inclui a conexão correta e define $conn
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

// Verifica acesso e carrega dados
include 'verificar_acesso.php';
$conn = conectarAoBanco();
$paginaAtual = basename($_SERVER['PHP_SELF']);
verificarCookie($conn, $paginaAtual);
verificarCargoUsuario(['admin', 'gerente']);
$dadosCaixa = carregarDadosCaixa();

// Inicializa variáveis
$mediaConsumo = 0;
$mediaQuartos = 0;
$ticketMedio = 0;
$medDiariaValor = 0;
$medDiariaNum = 0;
$prevLoca = 0;
$prevFatura = 0;
$erro = false;
$mensagemErro = '';

// Verifica se ocorreu algum erro
if (isset($dadosCaixa['erro']) && $dadosCaixa['erro']) {
    $erro = true;
    $mensagemErro = "Erro ao carregar os dados do caixa: " . $dadosCaixa['mensagem'];
} else {
    // Atribui os valores
    $mediaConsumo = $dadosCaixa['mediaConsumo'] ?? 0;
    $mediaQuartos = $dadosCaixa['mediaQuartos'] ?? 0;
    $ticketMedio = $dadosCaixa['ticketMedio'] ?? 0;
    $medDiariaValor = $dadosCaixa['medDiariaValor'] ?? 0;
    $medDiariaNum = $dadosCaixa['medDiariaNum'] ?? 0;
    $prevLoca = $dadosCaixa['prevLoca'] ?? 0;
    $prevFatura = $dadosCaixa['prevFatura'] ?? 0;
}

$conn->close();

function formatarMoeda($valor) {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function formatarNumero($valor) {
    return number_format($valor, 0, ',', '.');
}

// Definição dos dados para o layout de cards (Reintroduzindo as cores originais)
$cards = [
    // --- Dados do Caixa do Mês (Cores reintroduzidas) ---
    ['titulo' => 'Média de Hospedagem', 'subtitulo' => 'Valor', 'valor' => formatarMoeda($mediaQuartos), 'icone' => 'bi-house-door-fill', 'cor' => 'primary'],
    ['titulo' => 'Média de Consumo', 'subtitulo' => 'Valor', 'valor' => formatarMoeda($mediaConsumo), 'icone' => 'bi-bag-fill', 'cor' => 'success'], // Cor diferente
    ['titulo' => 'Ticket Médio', 'subtitulo' => 'Valor', 'valor' => formatarMoeda($ticketMedio), 'icone' => 'bi-ticket-fill', 'cor' => 'warning'], // Cor diferente
    ['titulo' => 'Média Diária', 'subtitulo' => 'Valor', 'valor' => formatarMoeda($medDiariaValor), 'icone' => 'bi-calendar-day-fill', 'cor' => 'info'], // Cor diferente
    ['titulo' => 'Média Diária', 'subtitulo' => 'Quantidade', 'valor' => formatarNumero($medDiariaNum), 'icone' => 'bi-person-lines-fill', 'cor' => 'secondary'],
    // --- Previsão de Faturamento (Cores reintroduzidas) ---
    ['titulo' => 'Previsão: Valor Total', 'subtitulo' => 'Valor', 'valor' => formatarMoeda($prevFatura), 'icone' => 'bi-cash-coin', 'cor' => 'danger'], // Cor diferente
    ['titulo' => 'Previsão: Quantidade', 'subtitulo' => 'Hospedagens', 'valor' => formatarNumero($prevLoca), 'icone' => 'bi-graph-up', 'cor' => 'dark'],
];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caixa - <?php echo ucfirst($filial); ?></title>
    <link rel="icon" href="imagens/iconeMI.png" type="image/png" sizes="32x32">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        /* Estilos Globais e de Layout */
        body {
            background-color: #f8f9fa;
        }
        .main-content {
            padding-top: 15px; /* Menos padding */
            padding-bottom: 80px;
        }

        /* Estilo para Títulos de Seção (Maior Destaque Mantido) */
        .section-title {
            font-size: 1.5rem; /* Título um pouco menor que antes */
            font-weight: 700;
            color: #343a40;
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 5px;
            margin-bottom: 15px !important; /* Margem reduzida */
        }
        
        /* --- ESTILOS DO CARD PARA SER MAIS COMPACTO --- */
        .card-metric {
            border-radius: 8px; /* Canto um pouco menor */
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05); /* Sombra ainda mais sutil */
            border: 1px solid #dee2e6;
            transition: transform 0.2s;
            height: 100%;
            padding: 10px !important; /* **CHAVE**: Reduz o padding interno do card */
        }
        .card-metric:hover {
            transform: translateY(-1px); /* Menos "lift" no hover */
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.08);
        }

        /* Ícone coloridos (como solicitado) */
        .card-icon {
            font-size: 1.8rem; /* Ícone menor */
            opacity: 1; /* Força a opacidade total para a cor do Bootstrap */
        }
        
        /* Texto do Card */
        .card-title {
            font-size: 0.9rem; /* Título do card menor */
            font-weight: 500;
            color: #495057;
            margin-bottom: 0;
        }
        .card-subtitle-text {
            font-size: 0.75rem; /* Subtítulo menor */
            color: #adb5bd;
            margin-bottom: 0px;
        }
        .card-text-value {
            font-size: 1.2rem; /* **CHAVE**: Valor principal menor (era 1.4rem) */
            font-weight: 700;
            /* Remove a cor escura forçada e usa a cor do Bootstrap (como estava na V1), 
               que é a cor do ícone, dando o toque colorido que você pediu. */
            /* color: #212529; */ 
            margin-top: 3px;
        }
        
        /* Ajuste do grid para telas pequenas para 2 colunas, se for o caso. */
        .row-cols-lg-3 > * {
            max-width: 33.333333%;
        }
        @media (max-width: 991.98px) {
             .row-cols-md-2 > * {
                max-width: 50%;
            }
        }
    </style>
</head>
<body>
    <div class="container main-content">
        <h1 class="text-center mb-4 text-secondary">Painel de Caixa</h1>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $mensagemErro; ?>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Métricas do Mês</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mb-4"> <?php 
            for ($i = 0; $i < 5; $i++): 
                $card = $cards[$i];
            ?>
                <div class="col">
                    <div class="card card-metric shadow-none">
                        <div class="d-flex align-items-center justify-content-between">
                            <i class="bi <?php echo $card['icone']; ?> text-<?php echo $card['cor']; ?> card-icon"></i> 
                            <div class="text-end">
                                <p class="card-subtitle-text mb-0"><?php echo $card['subtitulo']; ?></p>
                                <h5 class="card-title"><?php echo $card['titulo']; ?></h5>
                                <p class="card-text-value text-<?php echo $card['cor']; ?>"><?php echo $card['valor']; ?></p> 
                            </div>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        
        <h2 class="section-title">Previsão de Faturamento</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-2 g-3"> <?php 
            for ($i = 5; $i < count($cards); $i++): 
                $card = $cards[$i];
            ?>
                <div class="col">
                    <div class="card card-metric shadow-none">
                        <div class="d-flex align-items-center justify-content-between">
                            <i class="bi <?php echo $card['icone']; ?> text-<?php echo $card['cor']; ?> card-icon"></i>
                            <div class="text-end">
                                <p class="card-subtitle-text mb-0"><?php echo $card['subtitulo']; ?></p>
                                <h5 class="card-title"><?php echo $card['titulo']; ?></h5>
                                <p class="card-text-value text-<?php echo $card['cor']; ?>"><?php echo $card['valor']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

    </div>
    
    <?php include 'menu.php'; ?> 

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>