document.addEventListener('DOMContentLoaded', function () {
    var element = document.documentElement;
    if (element.requestFullscreen) {
        element.requestFullscreen();
    } else if (element.mozRequestFullScreen) { // Firefox
        element.mozRequestFullScreen();
    } else if (element.webkitRequestFullscreen) { // Chrome, Safari e Opera
        element.webkitRequestFullscreen();
    } else if (element.msRequestFullscreen) { // IE/Edge
        element.msRequestFullscreen();
    }

    // Redireciona para outra página ao clicar em qualquer lugar da página
    document.body.addEventListener('click', function() {
        window.location.href = 'HomePage.php'; // Substitua 'HomePage.php' pelo caminho para a próxima página
    });
});