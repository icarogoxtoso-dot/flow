<?php
require_once __DIR__ . '/../secure/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contato - Clube dos Parceiros</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/theme.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
    <header class="top-header">
        <div class="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <span class="brand-icon"><img src="../img/logomenor.png" alt="Logo Clube dos Parceiros"></span>
                <span class="brand-title">Clube dos Parceiros</span>
            </div>
            <a href="<?php echo htmlspecialchars(appPath('/index.html'), ENT_QUOTES, 'UTF-8'); ?>" class="px-4 py-2 rounded-lg border border-slate-300 text-sm font-semibold text-slate-700 hover:bg-slate-50">Voltar ao site</a>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-6 py-10">
        <article class="bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
            <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 mb-2">Fale com a gente</h1>
            <p class="text-slate-600 mb-8">Se precisar de suporte, tiver dúvidas comerciais ou quiser enviar sugestões, use um dos canais abaixo.</p>

            <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-sm text-slate-500 mb-1">E-mail</p>
                    <a href="mailto:contato@clubedosparceiros.com" class="text-blue-700 font-semibold hover:underline break-all">contato@clubedosparceiros.com</a>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-sm text-slate-500 mb-1">WhatsApp</p>
                    <a href="https://wa.me/5511999999999" target="_blank" rel="noopener noreferrer" class="text-blue-700 font-semibold hover:underline">(11) 99999-9999</a>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-sm text-slate-500 mb-1">Horário</p>
                    <p class="text-slate-700 font-semibold">Seg a Sex, 09h às 18h</p>
                </div>
            </section>

            <section class="border-t border-slate-100 pt-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Enviar mensagem</h2>
                <form class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="nome" class="block text-sm font-semibold text-slate-700 mb-1">Nome</label>
                        <input id="nome" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="Seu nome">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-semibold text-slate-700 mb-1">E-mail</label>
                        <input id="email" type="email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="seu@email.com">
                    </div>
                    <div class="md:col-span-2">
                        <label for="assunto" class="block text-sm font-semibold text-slate-700 mb-1">Assunto</label>
                        <input id="assunto" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="Como podemos ajudar?">
                    </div>
                    <div class="md:col-span-2">
                        <label for="mensagem" class="block text-sm font-semibold text-slate-700 mb-1">Mensagem</label>
                        <textarea id="mensagem" rows="5" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="Escreva sua mensagem"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <button type="button" class="px-5 py-2.5 rounded-lg bg-blue-900 text-white font-semibold hover:bg-blue-800 transition">Enviar</button>
                    </div>
                </form>
                <p class="text-xs text-slate-500 mt-3">Esta página é informativa. Se quiser, depois eu conecto o formulário ao backend.</p>
            </section>
        </article>
    </main>
</body>
</html>
