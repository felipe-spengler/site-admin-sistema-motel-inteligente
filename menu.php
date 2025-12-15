<?php
// Verifica qual é a página ativa
$pagina_ativa = basename($_SERVER['PHP_SELF']);

// Função para adicionar a classe 'ativo' ao link da página ativa
function paginaAtiva($pagina, $pagina_ativa) {
    if ($pagina == $pagina_ativa) {
        return 'ativo'; 
    } else {
        return '';
    }
}

// Função para deixar o menu 'Relatórios' ativo se qualquer página de relatório estiver ativa
function paginaRelatorioAtiva($pagina_ativa) {
    $relatorios = array('EvolucaoVendas.php', 'DRE.php', 'ExtratoDiario.php', 'Hospedagens.php', 'demonstrativo_grafico.php');
    if (in_array($pagina_ativa, $relatorios)) {
        return 'ativo';
    } else {
        return '';
    }
}

// Seus estilos customizados DEVEM ser movidos para o <style> do arquivo principal (caixa.php)
?>

        
     <nav class="navbar bottom-navbar navbar-expand bg-primary p-0 fixed-bottom shadow-lg">
        <div class="container-fluid">
            <ul class="navbar-nav nav-fill w-100">
                
                <li class="nav-item">
                    <a href="principal.php" class="nav-link text-white <?php echo paginaAtiva('principal.php', $pagina_ativa); ?>">
                        Home
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="Caixa.php" class="nav-link text-white <?php echo paginaAtiva('Caixa.php', $pagina_ativa); ?>">
                        Caixa
                    </a>
                </li>
                
                <li class="nav-item dropdown dropup">
                    
                    <a href="#" 
                       class="nav-link dropdown-toggle text-white <?php echo paginaRelatorioAtiva($pagina_ativa); ?>" 
                       id="dropdownRelatorios" 
                       role="button" 
                       data-bs-toggle="dropdown" 
                       aria-expanded="false">
                        Relatórios
                    </a>
                    
                    <ul class="dropdown-menu dropdown-menu-up" aria-labelledby="dropdownRelatorios">
                        <li><a class="dropdown-item" href="EvolucaoVendas.php">Evolução de Vendas</a></li>
                        <li><a class="dropdown-item" href="Dre.php">DRE</a></li>
                        <li><a class="dropdown-item" href="ExtratoDiario.php">Extrato Diário</a></li>
                        <li><a class="dropdown-item" href="demonstrativo_grafico.php">Gráficos</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>