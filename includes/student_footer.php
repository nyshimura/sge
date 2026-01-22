</div> </div> </div> <div class="sidebar-overlay"></div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const sidebarMenu = document.querySelector('.sidebar-menu');

        if (sidebarMenu) {
            // 1. RESTAURAR POSIÇÃO: Verifica se tem uma posição salva e aplica
            const savedPosition = sessionStorage.getItem('sidebarScrollPos');
            
            if (savedPosition) {
                sidebarMenu.scrollLeft = savedPosition;
            } else {
                // Opcional: Se não tiver nada salvo, centraliza o item ativo (o botão verde)
                const activeLink = sidebarMenu.querySelector('a.active');
                if (activeLink) {
                    activeLink.scrollIntoView({ inline: 'center', behavior: 'auto' });
                }
            }

            // 2. SALVAR POSIÇÃO: Antes de sair da página (clicar em link ou atualizar)
            window.addEventListener('beforeunload', function() {
                sessionStorage.setItem('sidebarScrollPos', sidebarMenu.scrollLeft);
            });
        }
    });
    </script>
</body>
</html>