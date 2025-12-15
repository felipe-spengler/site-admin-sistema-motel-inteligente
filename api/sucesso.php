<?php
date_default_timezone_set('America/Sao_Paulo');

// Captura o sistema que está sendo atualizado para exibição
$sistema = isset($_GET['sistema']) ? htmlspecialchars(ucfirst(strtolower($_GET['sistema']))) : 'Sistema';

// Informações de contato
$telefone_contato = '49 99945-9490';
$telefone_whatsapp = preg_replace('/[^0-9]/', '', $telefone_contato); // Remove caracteres não numéricos

// Formatação do link do WhatsApp
$whatsapp_link = "https://api.whatsapp.com/send?phone=55{$telefone_whatsapp}";
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento Confirmado - <?= $sistema ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 600px; margin-top: 50px; }
        .success-card { 
            border-left: 5px solid #198754; 
            box-shadow: 0 4px 8px rgba(0,0,0,.05);
        }
        .icon-success { font-size: 3rem; color: #198754; }
    </style>
</head>
<body>

<div class="container text-center">
    <div class="card p-4 success-card">
        <div class="card-body">
            <div class="icon-success mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" fill="currentColor" class="bi bi-check-circle-fill" viewBox="0 0 16 16">
                    <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.497 5.394 7.29a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                </svg>
            </div>
            
            <h1 class="card-title text-success mb-3">Pagamento Aprovado!</h1>
            
            <p class="lead">Sua mensalidade de <?= $sistema ?> foi confirmada com sucesso.</p>
            
            <hr>
            
            <p class="text-muted">O pagamento já está registrado em nosso sistema. A atualização do status no seu <b>sistema desktop será feita automaticamente em breve.</b></p>
            
            <p>Agradecemos a sua preferência!</p>
            
            <div class="mt-4 p-3 bg-light rounded">
                <p class="mb-1">Em caso de dúvidas ou problemas, entre em contato:</p>
                <p class="mb-0">
                    <a href="<?= $whatsapp_link ?>" target="_blank" class="btn btn-success btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-whatsapp" viewBox="0 0 16 16">
                            <path d="M13.6 2.4a.75.75 0 0 0-.58-.328C11.66 2 8 2 8 2s-3.66 0-5.02.072A.75.75 0 0 0 2.4 2.4C2 3.86 2 8 2 8s0 4.14.072 5.597a.75.75 0 0 0 .328.58C4.34 14 8 14 8 14s3.66 0 5.02-.072a.75.75 0 0 0 .58-.328C14 12.14 14 8 14 8s0-4.14-.072-5.597zM8 12.15c-2.31 0-4.18-1.87-4.18-4.18s1.87-4.18 4.18-4.18 4.18 1.87 4.18 4.18-1.87 4.18-4.18 4.18zm.13-2.92c-.13-.08-.34-.14-.54-.14-.2 0-.39.06-.52.14s-.26.25-.33.45-.1.43-.03.65.17.38.3.46.28.1.48.1.37-.03.5-.09.25-.19.32-.39.1-.42.1-.64-.04-.42-.17-.55z"/>
                        </svg>
                        Ligar ou Enviar WhatsApp
                    </a>
                    <br>
                    <span class="text-secondary small mt-1 d-block">Telefone: <?= $telefone_contato ?></span>
                </p>
            </div>
            
        </div>
    </div>
</div>

</body>
</html>