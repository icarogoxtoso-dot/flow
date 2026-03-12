<?php
require_once __DIR__ . '/../secure/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinar e Criar Conta - Clube dos Parceiros</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/theme.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-screen bg-[radial-gradient(circle_at_top,rgba(30,58,138,0.14),transparent_50%),radial-gradient(circle_at_bottom,rgba(59,130,246,0.10),transparent_45%)]">
        <nav class="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-slate-100">
            <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between gap-3">
                <a href="<?php echo htmlspecialchars(appPath('/'), ENT_QUOTES, 'UTF-8'); ?>" class="flex items-center gap-2 text-blue-900 font-black text-base sm:text-lg">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-blue-900 text-white shadow-sm">
                        <img src="../img/logomenor.png" alt="Logo Flow" class="h-7 w-auto object-contain">
                    </span>
                    <span class="truncate">Clube dos Parceiros</span>
                </a>
                <a href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=login'), ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 transition">
                    Entrar
                </a>
            </div>
        </nav>

        <main class="max-w-5xl mx-auto px-4 py-10">
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="bg-white border border-slate-200 rounded-2xl p-6 sm:p-7 shadow-sm">
                    <p class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-bold tracking-[0.18em] uppercase text-blue-900">
                        Plano Profissional
                    </p>
                    <h1 class="mt-3 text-3xl sm:text-4xl font-extrabold text-slate-900 leading-tight">Assine e crie sua conta.</h1>
                    <p class="mt-3 text-slate-600 leading-relaxed">
                        O acesso ao perfil profissional só é liberado para assinantes. Use o mesmo e-mail do pagamento no cadastro.
                    </p>

                    <div class="mt-6 grid gap-3">
                        <a href="<?php echo htmlspecialchars(appPath('/access/subscribe.php'), ENT_QUOTES, 'UTF-8'); ?>"
                           class="w-full text-center rounded-xl bg-blue-900 text-white font-semibold px-5 py-3 hover:bg-blue-800 transition">
                            Assinar (pagar) e continuar
                        </a>
                        <a href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=login'), ENT_QUOTES, 'UTF-8'); ?>"
                           class="w-full text-center rounded-xl border border-slate-200 bg-white text-slate-800 font-semibold px-5 py-3 hover:border-blue-300 transition">
                            Entrar (já assinei)
                        </a>
                    </div>

                    <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                        <p class="font-semibold text-slate-900">Importante</p>
                        <p class="mt-1 text-slate-600">Se você pagar com um e-mail e cadastrar com outro, o acesso não libera.</p>
                    </div>
                </section>

                <section class="bg-white border border-slate-200 rounded-2xl p-6 sm:p-7 shadow-sm">
                    <p class="text-xs font-bold tracking-[0.18em] uppercase text-slate-500">Passo a passo</p>
                    <ol class="mt-4 grid gap-3">
                        <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-900">1. Assine</p>
                            <p class="mt-1 text-sm text-slate-600">Finalize o checkout de R$5/mês.</p>
                        </li>
                        <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-900">2. Crie sua conta</p>
                            <p class="mt-1 text-sm text-slate-600">Cadastre usando o mesmo e-mail do pagamento.</p>
                        </li>
                        <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-900">3. Crie seu perfil</p>
                            <p class="mt-1 text-sm text-slate-600">O sistema libera automaticamente após confirmar a assinatura.</p>
                        </li>
                    </ol>

                    <div class="mt-6 flex flex-col sm:flex-row gap-3">
                        <a href="<?php echo htmlspecialchars(appPath('/access/checkout.html'), ENT_QUOTES, 'UTF-8'); ?>"
                           class="inline-flex w-full sm:w-auto justify-center rounded-xl border border-slate-200 bg-white px-6 py-3 text-slate-800 font-semibold hover:border-blue-300 transition">
                            Ver página do plano
                        </a>
                        <a href="<?php echo htmlspecialchars(appPath('/access/painel.php'), ENT_QUOTES, 'UTF-8'); ?>"
                           class="inline-flex w-full sm:w-auto justify-center rounded-xl bg-slate-900 px-6 py-3 text-white font-semibold hover:bg-slate-800 transition">
                            Ver profissionais
                        </a>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
