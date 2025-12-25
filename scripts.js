document.addEventListener('DOMContentLoaded', function () {
    // Redireciona para outra página ao clicar em qualquer lugar da página
    document.body.addEventListener('click', function() {
        // Tenta entrar em fullscreen quando o usuário clicar
        var element = document.documentElement;
        if (element.requestFullscreen) {
            element.requestFullscreen().catch(function(error) {
                console.log('Não foi possível entrar em fullscreen:', error);
            });
        } else if (element.mozRequestFullScreen) { // Firefox
            element.mozRequestFullScreen().catch(function(error) {
                console.log('Não foi possível entrar em fullscreen:', error);
            });
        } else if (element.webkitRequestFullscreen) { // Chrome, Safari e Opera
            element.webkitRequestFullscreen().catch(function(error) {
                console.log('Não foi possível entrar em fullscreen:', error);
            });
        } else if (element.msRequestFullscreen) { // IE/Edge
            element.msRequestFullscreen().catch(function(error) {
                console.log('Não foi possível entrar em fullscreen:', error);
            });
        }
        
        window.location.href = 'HomePage.php'; // Substitua 'HomePage.php' pelo caminho para a próxima página
    });
});