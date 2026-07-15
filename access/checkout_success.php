<?php
require_once __DIR__ . '/../secure/config.php';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <link rel="canonical" href="https://clubedosparceiros.cloud/access/checkout_success.php">
    <meta name="theme-color" content="#1A3D63">
    <title>Assinatura confirmada</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/img/logomenor.png" type="image/png">
    <link rel="stylesheet" href="../assets/theme.css">
</head>
<body class="bg-slate-50 text-slate-900">
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

    <main class="max-w-xl mx-auto px-6 pt-28 pb-16">
        <div class="bg-white border border-slate-200 rounded-2xl p-8 shadow-sm">
            <h1 class="text-2xl font-extrabold mb-2">Pagamento recebido</h1>
            <p class="text-slate-600 mb-6">A ativação pode levar alguns segundos. Se seu acesso não liberar, atualize a página em instantes.</p>
            <div class="flex gap-3">
                <a class="px-5 py-2.5 rounded-lg bg-blue-900 text-white font-semibold hover:bg-blue-800 transition" href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=login'), ENT_QUOTES, 'UTF-8'); ?>">Ir para login</a>
                <a class="px-5 py-2.5 rounded-lg border border-slate-300 font-semibold hover:bg-slate-50 transition" href="<?php echo htmlspecialchars(appPath('/index.html'), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao site</a>
            </div>
        </div>
    </main>
</body>
</html>
