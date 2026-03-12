<?php
require_once __DIR__ . '/../secure/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento cancelado - Clube dos Parceiros</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/theme.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <main class="max-w-xl mx-auto px-4 py-14">
        <div class="bg-white border border-slate-200 rounded-2xl p-7 shadow-sm">
            <h1 class="text-2xl font-extrabold text-slate-900">Pagamento cancelado</h1>
            <p class="mt-3 text-slate-600 leading-relaxed">
                Sem problemas. Quando quiser, você pode tentar novamente para ativar seu perfil.
            </p>
            <div class="mt-6 grid gap-3">
                <a href="<?php echo htmlspecialchars(appPath('/access/checkout.html'), ENT_QUOTES, 'UTF-8'); ?>"
                   class="w-full text-center rounded-xl bg-blue-900 text-white font-semibold px-5 py-3 hover:bg-blue-800 transition">
                    Voltar para assinatura
                </a>
                <a href="<?php echo htmlspecialchars(appPath('/'), ENT_QUOTES, 'UTF-8'); ?>"
                   class="w-full text-center rounded-xl border border-slate-200 bg-white text-slate-800 font-semibold px-5 py-3 hover:border-blue-300 transition">
                    Ir para a home
                </a>
            </div>
        </div>
    </main>
</body>
</html>
