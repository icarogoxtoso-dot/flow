<?php
require_once __DIR__ . '/../secure/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidade - Clube dos Parceiros</title>
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
            <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900 mb-2">Política de Privacidade</h2>
            <p class="text-sm text-slate-500 mb-8">Última atualização: 09/03/2026</p>

            <section class="space-y-4 text-slate-700 leading-relaxed">
                <p>
                    Esta Política de Privacidade descreve como o <strong>Clube dos Parceiros</strong> coleta, usa e protege os dados pessoais dos usuários da plataforma.
                </p>
                <p>
                    Coletamos dados informados no cadastro e no uso do serviço, como nome, e-mail, telefone, informações do perfil profissional e dados técnicos de acesso (ex.: IP e navegador), para operar a plataforma com segurança.
                </p>
                <p>
                    Os dados são utilizados para autenticação, exibição de perfis, comunicação entre usuários e profissionais, prevenção de fraudes e melhoria da experiência do serviço.
                </p>
                <p>
                    Não vendemos dados pessoais. O compartilhamento ocorre somente quando necessário para funcionamento da plataforma, cumprimento de obrigação legal ou mediante solicitação do próprio usuário.
                </p>
                <p>
                    O usuário pode solicitar atualização, correção ou exclusão de dados, respeitadas obrigações legais e de segurança aplicáveis.
                </p>
                <p>
                    Utilizamos cookies e tecnologias semelhantes para manter sessão, lembrar preferências e analisar desempenho. O usuário pode gerenciar cookies no navegador.
                </p>
            </section>

            <section class="mt-8 pt-6 border-t border-slate-100 text-sm text-slate-500 space-y-2">
                <p>Ao continuar usando a plataforma, você concorda com esta Política de Privacidade.</p>
                <p>Em caso de dúvidas, utilize os canais oficiais informados no site.</p>
            </section>
        </article>
    </main>
</body>
</html>
