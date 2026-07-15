<?php
require_once __DIR__ . '/../secure/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Termo de Responsabilidade do Clube dos Parceiros. Leia as condições de uso da plataforma e responsabilidades das partes.">
    <link rel="canonical" href="https://clubedosparceiros.cloud/access/termos_responsabilidade.php">
    <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">

    <meta property="og:site_name" content="Clube dos Parceiros">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Termo de Responsabilidade - Clube dos Parceiros">
    <meta property="og:description" content="Condições de uso e responsabilidades na plataforma.">
    <meta property="og:url" content="https://clubedosparceiros.cloud/access/termos_responsabilidade.php">
    <meta property="og:image" content="https://clubedosparceiros.cloud/img/logo.png">
    <meta property="og:image:alt" content="Logo do Clube dos Parceiros">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Termo de Responsabilidade - Clube dos Parceiros">
    <meta name="twitter:description" content="Condições de uso e responsabilidades na plataforma.">
    <meta name="twitter:image" content="https://clubedosparceiros.cloud/img/logo.png">

    <meta name="theme-color" content="#1A3D63">
    <title>Termo de Responsabilidade - Clube dos Parceiros</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/img/logomenor.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/theme.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
    <nav class="fixed top-0 w-full z-50 bg-blue-900/95 text-white backdrop-blur-md border-b border-blue-800/60">
        <div class="container mx-auto px-4 sm:px-6 py-3.5 flex items-center gap-3">
            <a href="<?php echo htmlspecialchars(appPath('/index.html'), ENT_QUOTES, 'UTF-8'); ?>" class="shrink-0 flex items-center gap-3 text-white font-black text-base sm:text-xl min-w-0">
                <img src="../img/logomenor.png" alt="Logo Clube dos Parceiros" class="h-10 sm:h-14 w-auto object-contain">
                <span class="truncate">Clube dos Parceiros</span>
            </a>

            <div class="hidden md:flex flex-1 items-center justify-center gap-6 lg:gap-8 text-sm font-medium text-white/85">
                <a href="<?php echo htmlspecialchars(appPath('/index.html#como-funciona'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-white transition-colors">Como funciona</a>
                <a href="<?php echo htmlspecialchars(appPath('/index.html#vantagens'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-white transition-colors">Vantagens</a>
                <a href="<?php echo htmlspecialchars(appPath('/index.html#videos'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-white transition-colors">Vídeos</a>
                <a href="<?php echo htmlspecialchars(appPath('/access/painel.php'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-white transition-colors">Profissionais</a>
            </div>

            <div class="shrink-0 flex flex-wrap items-center justify-end gap-2 sm:gap-3">
                <a href="<?php echo htmlspecialchars(appPath('/access/checkout.html'), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-1.5 sm:px-4 sm:py-2 rounded-lg font-semibold transition-all duration-300 transform hover:-translate-y-1 shadow-md flex items-center justify-center gap-2 bg-blue-700 text-white border-2 border-blue-600 hover:bg-blue-600 text-xs sm:text-sm whitespace-nowrap">
                    Cadastrar
                </a>
                <a href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=login'), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-1.5 sm:px-4 sm:py-2 rounded-lg font-semibold transition-all duration-300 transform hover:-translate-y-1 shadow-md flex items-center justify-center gap-2 bg-white text-blue-900 border-2 border-white hover:bg-blue-50 hover:shadow-lg hover:shadow-black/10 text-xs sm:text-sm whitespace-nowrap">
                    Entrar
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto px-6 pt-28 pb-10">
        <article class="bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
            <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900 mb-2">Termo de Responsabilidade</h2>
            <p class="text-sm text-slate-500 mb-8">Última atualização: 09/03/2026</p>

            <section class="space-y-4 text-slate-700 leading-relaxed">
                <p>
                    O <strong>Clube dos Parceiros</strong> atua exclusivamente como uma plataforma de intermediação para exibição de perfis de profissionais e conexão entre usuários interessados.
                </p>
                <p>
                    A plataforma <strong>não presta serviços técnicos</strong>, não executa obras, não realiza instalações, reparos ou qualquer atividade operacional anunciada pelos profissionais cadastrados.
                </p>
                <p>
                    Toda negociação, contratação, execução, prazo, qualidade, preço, garantia e eventuais danos decorrentes da prestação de serviço são de <strong>responsabilidade exclusiva do profissional contratado e do cliente</strong>.
                </p>
                <p>
                    O Clube dos Parceiros não se responsabiliza por condutas, omissões, atrasos, prejuízos, perdas materiais, danos diretos ou indiretos, ou quaisquer disputas oriundas da relação entre cliente e profissional.
                </p>
                <p>
                    Ao utilizar a plataforma, o usuário declara ciência de que o Clube dos Parceiros limita-se à disponibilização de informações e meios de contato, sem assumir responsabilidade pela execução dos serviços.
                </p>
            </section>

            <section class="mt-8 pt-6 border-t border-slate-100 text-sm text-slate-500">
                <p>Em caso de dúvidas, utilize os canais oficiais de contato informados no site.</p>
            </section>
        </article>
    </main>
</body>
</html>
