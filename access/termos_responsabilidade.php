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

    <meta name="theme-color" content="#1e3a8a">
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
