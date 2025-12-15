<?php
function carregarLista() {
    include_once  'conexao2.php'; // Inclui o arquivo de conexão

    $quartosDisponiveis = array(); // Array para armazenar os quartos disponíveis

    try {
        // Conectar ao banco de dados
        $conexao = conectarAoBanco();
        $consultaSQL = "SELECT numeroquarto, tipoquarto FROM quartos WHERE numeroquarto IN (SELECT numeroquarto FROM status WHERE atualquarto = 'livre')";
        $stmt = $conexao->prepare($consultaSQL);
        $result = $conexao->query($consultaSQL);

		while ($row = $result->fetch_assoc()) {
			$numeroQuarto = $row['numeroquarto'];
			$tipoQuarto = $row['tipoquarto'];

			$quartosDisponiveis[] = array($numeroQuarto, $tipoQuarto);
		}

        $conexao = null;

        return $quartosDisponiveis;
    } catch (PDOException $e) {
        // Em caso de erro, exibir a mensagem de erro
        echo "Erro ao carregar lista de quartos disponíveis: " . $e->getMessage();
        return array(); // Retorna uma lista vazia em caso de erro
    }
}
function obterOpcaoConfig() {
    include_once  'conexao2.php';

    try {
        // Conectar ao banco de dados
        $conexao = conectarAoBanco();
        $consultaSQL = "SELECT sistemaescolhe FROM configuracoes";
        $stmt = $conexao->prepare($consultaSQL);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $dadoBanco = $row['sistemaescolhe'];
        } else {
            $dadoBanco = null; // Defina um valor padrão caso não haja resultados
        }

        $conexao = null;

        return $dadoBanco;
    } catch (PDOException $e) {
        echo "Erro ao obter config por tipo ou numeroQuarto: " . $e->getMessage();
        return null;
    }
}
function obterImagemDoBanco($tipoQuarto) {
    include_once 'conexao2.php'; 

    $imagem = null; // Inicializa a variável de imagem como nula

    try {
        // Conectar ao banco de dados
        $conexao = conectarAoBanco();

        $query = "SELECT imagem FROM imagens WHERE nome_da_imagem = ?";
        $stmt = $conexao->prepare($query);
        $stmt->bind_param("s", $tipoQuarto); // Bind do parâmetro
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows > 0) {
            $row = $resultado->fetch_assoc();
            $imagem = $row['imagem'];
        }

        $stmt->close();
        $conexao->close();
    } catch (Exception $e) {
        // Em caso de erro, exibir a mensagem de erro
        echo "Erro ao obter imagem do banco de dados: " . $e->getMessage();
    }

    return $imagem;
}

$imgCabecalho = obterImagemDoBanco("cabecalho");
$opcao = obterOpcaoConfig();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Page</title>
    <script>
    // Função para sortear o quarto pelo tipo
        var jaSorteou = 0;
        function sortearQuarto(tipoPassado) {
            if(jaSorteou === 0){
                    // Array para armazenar os quartos disponíveis do tipo passado
                var quartosDoTipo = quartosDisponiveis.filter(function(quarto) {
                    return quarto[1] === tipoPassado;
                });
    
                // Sortear um número de quarto até encontrar o tipo passado
                var numeroSorteado;
                do {
                    var indiceSorteado = Math.floor(Math.random() * quartosDoTipo.length);
                    numeroSorteado = quartosDoTipo[indiceSorteado][0];
                } while (quartosDoTipo[indiceSorteado][1] !== tipoPassado);
    
                console.log("Número do quarto sorteado:", numeroSorteado);
                console.log("Tipo do quarto passado:", tipoPassado);
                var modal = document.getElementById("confirmModal");
                var confirmMessage = document.getElementById("confirmMessage");
                var confirmBtn = document.getElementById("confirmBtn");
                var cancelBtn = document.getElementById("cancelBtn");
                var countdownElement = document.getElementById("countdown");
                var countdown = 12;
                
                confirmMessage.textContent = `Você confirma a escolha de ${tipoPassado}?`;

                modal.style.display = "block";
                countdownElement.textContent = `Retorna a tela inicial em ${countdown} segundos...`;

                var countdownInterval = setInterval(function() {
                    countdown--;
                    countdownElement.textContent = `Retorna a tela inicial em  ${countdown} segundos...`;
                    if (countdown === 0) {
                        clearInterval(countdownInterval);
                        modal.style.display = "none";
                        window.location.href = "autoatend.php";
                    }
                }, 1000);

                confirmBtn.onclick = function() {
                    clearInterval(countdownInterval);
                    modal.style.display = "none";
                    enviarRequisicao(numeroSorteado, tipoPassado);
                    jaSorteou = 1;
                }

                cancelBtn.onclick = function() {
                    clearInterval(countdownInterval);
                    modal.style.display = "none";
                    window.location.href = "autoatend.php";
                }

                var span = document.getElementsByClassName("close")[0];
                span.onclick = function() {
                    clearInterval(countdownInterval);
                    modal.style.display = "none";
                    window.location.href = "autoatend.php";
                }

                window.onclick = function(event) {
                    if (event.target == modal) {
                        clearInterval(countdownInterval);
                        modal.style.display = "none";
                        window.location.href = "autoatend.php";
                    }
                }
            }

        }

    </script>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        #cabecalho {
            height: 90px;
            background-size: cover; /* Redimensionar a imagem para cobrir todo o cabeçalho */
            background-position: center; /* Centralizar a imagem */
            text-align: center;
            line-height: 100px;
        }
		#conteudo {
            overflow-x: hidden; /* Adiciona um scroll horizontal */
            white-space: nowrap; /* Evita que os elementos quebrem linha */
        }
        .quarto {
            width: 350px;
            height: calc(100vh - 150px); 
            margin: 0px 10px;
            border: 1px solid #cccccc;
            display: inline-block;
            text-align: center;
            overflow: hidden; /* Para garantir que a imagem não transborde */
        }
        .quarto img {
            width: 100%; /* Largura da imagem igual à largura da div .quarto */
            height: 100%; /* Altura da imagem igual à altura da div .quarto */
        }

        #rodape {
            height: 50px;
            background-color: #6e0d36; /* Cor de fundo do rodapé */
            text-align: right;
            line-height: 50px;
            padding-right: 20px;
            color: #ffffff; /* Cor do texto do rodapé */
        }
        .scroll-button {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 50px;
            height: 300px;
            background-color: rgba(139, 0, 139, 0.5); /* Cor de fundo com opacidade */
            color: white;
            font-size: 24px;
            text-align: center;
            line-height: 300px;
            cursor: pointer;
            z-index: 999;
        }
        #scroll-left {
            left: 0;
        }
        #scroll-right {
            right: 0;
        }
        #botaoVoltar {
            width: 400px;
            height: 50px;
            color: white;
            background-color: #000000; /* Cor do botão */
            font-size: 18px;
            font-family: Helvetica, sans-serif;
            border: none;
            cursor: pointer;
            outline: none;
            margin-right: 10px;
            float: left; /* Adiciona o botão à esquerda do rodapé */
        }
        .modal {
            display: none; 
            position: fixed; 
            z-index: 1; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.4); 
            padding-top: 60px;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto; 
            padding: 40px;
            border: 1px solid #888;
            width: 80%;
            font-size: 24px; /* Aumentando o tamanho da fonte */
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 40px; /* Aumentando o tamanho da fonte do botão de fechar */
            font-weight: bold;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal button {
            font-size: 28px; /* Aumentando o tamanho da fonte dos botões */
            padding: 26px; /* Aumentando o tamanho dos botões */
            margin: 10px;
        }
    </style>
</head>
<body>
    <script>
        const storedToken = localStorage.getItem('authToken');
        if (storedToken !== '121212') {
             Redirecionar ou bloquear acesso
            window.location.href = 'pagina-de-erro-ou-login.html';
        }
    </script>
    <div id="cabecalho" style="background-image: url('data:image/jpeg;base64,<?php echo base64_encode($imgCabecalho); ?>');"></div>
     <div id="confirmModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <p id="confirmMessage"></p>
            <button id="confirmBtn">Sim</button>
            <button id="cancelBtn">Não</button>
            <p id="countdown"></p>
        </div>
    </div>
<div id="conteudo">
    <?php
    // Lógica para carregar e exibir os quartos disponíveis
    $quartosDisponiveis = carregarLista();
    
    if (!empty($quartosDisponiveis)) {
        if ($opcao != null) {
            if ($opcao === 1) {
                // Escolha de quartos feita por tipoQuarto
                $jaAdicionou = array(); // Array para armazenar os quartos já adicionados

                foreach ($quartosDisponiveis as $quarto) {
                    if (is_array($quarto)) {
                        $numeroQuarto = $quarto[0];
                        $tipoQuarto = $quarto[1];

                        if (!in_array($tipoQuarto, $jaAdicionou)) {
                            $imagem = obterImagemDoBanco($tipoQuarto);
                            echo "<div class='quarto'>";
                                echo "<a href='javascript:void(0);' onclick=\"sortearQuarto('$tipoQuarto');\">";
                                    echo "<img src='data:image/jpeg;base64," . base64_encode($imagem) . "' alt='Imagem do quarto'>";
                                echo "</a>";
                            echo "</div>";

                            $jaAdicionou[] = $tipoQuarto;
                        }
                    }
                }
            } else {
                foreach ($quartosDisponiveis as $quarto) {
                    if (is_array($quarto)) {
                        $numeroQuarto = $quarto[0];
                        $tipoQuarto = $quarto[1];

                        $imagem = obterImagemDoBanco($numeroQuarto);
                        echo "<div class='quarto'>";
                            echo "<a href='javascript:void(0);' onclick=\"enviaNumero('$numeroQuarto');\">";
                                echo "<img src='data:image/jpeg;base64," . base64_encode($imagem) . "' alt='Imagem do quarto'>";
                            echo "</a>";
                        echo "</div>";
                    }
                }
            }
        }
    } else {
        echo '<div id="conteudo" style="width: 600px; height: 625px; display: flex; justify-content: center; align-items: center; text-align: center;">';
        echo "<p style='font-size: 18px; font-weight: bold;'>Nenhum quarto disponível nesse momento.</p>";
        echo "</div>";
    }
    ?>
</div>

<div id="scroll-left" class="scroll-button" onclick="scrollContent(-50)">←</div>
<div id="scroll-right" class="scroll-button" onclick="scrollContent(50)">→</div>
   
<script>
    var tempoEspera = 60000;

    // Agende a execução da função de redirecionamento após o tempo de espera
    setTimeout(function() {
        // Redirecione para autoatend.php após 1 minuto
        window.location.href = 'autoatend.php';
    }, tempoEspera);
    var quartosDisponiveis = <?php echo json_encode($quartosDisponiveis); ?>;
    var requisicaoEnviada = false;

        
        function enviarRequisicao(numeroSorteado,tipoPassado) {
            window.location.href = 'TelaExibicao.php?dados=' + encodeURIComponent(numeroSorteado) + '&tipoQuarto=' + encodeURIComponent(tipoPassado);
        }
</script>
<div id="rodape">
    <button onclick="voltar()" id="botaoVoltar" >VOLTAR</button>

    <span>Adicional Pessoa: R$30,00</span>
    <script>
        function voltar() {
            window.location.href = 'autoatend.php'; 
        }
		function scrollContent(amount) {
                var content = document.getElementById('conteudo');
                var scrollAmount = content.scrollLeft + amount;
                content.scrollTo({
                    left: scrollAmount,
                    behavior: 'smooth'
                });
            }
    </script>
</div>
</body>
</html>