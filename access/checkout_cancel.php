<?php
require_once __DIR__ . '/../secure/config.php';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout cancelado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/theme.css">
</head>
<body class="bg-slate-50 text-slate-900">
    <main class="max-w-xl mx-auto px-6 py-16">
        <div class="bg-white border border-slate-200 rounded-2xl p-8 shadow-sm">
            <h1 class="text-2xl font-extrabold mb-2">Checkout cancelado</h1>
            <p class="text-slate-600 mb-6">Você pode tentar novamente quando quiser.</p>
            <div class="flex gap-3">
                <a class="px-5 py-2.5 rounded-lg bg-blue-900 text-white font-semibold hover:bg-blue-800 transition" href="<?php echo htmlspecialchars(appPath('/access/checkout.html'), ENT_QUOTES, 'UTF-8'); ?>">Tentar novamente</a>
                <a class="px-5 py-2.5 rounded-lg border border-slate-300 font-semibold hover:bg-slate-50 transition" href="<?php echo htmlspecialchars(appPath('/index.html'), ENT_QUOTES, 'UTF-8'); ?>">Voltar ao site</a>
            </div>
        </div>
    </main>
</body>
</html>

