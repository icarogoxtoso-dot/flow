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
        <section class="mb-6">
            <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-xs font-semibold text-slate-600">
                <span class="h-2 w-2 rounded-full bg-blue-700"></span>
                Privacidade e dados
            </div>
            <h1 class="mt-3 text-2xl md:text-3xl font-extrabold text-slate-900 tracking-tight">Política de Privacidade</h1>
            <p class="mt-2 text-slate-600 leading-relaxed">
                Esta Política explica como o <strong>Clube dos Parceiros</strong> coleta, usa e protege dados pessoais quando você utiliza a plataforma.
            </p>
            <p class="mt-2 text-sm text-slate-500">Última atualização: 12/03/2026</p>
        </section>

        <article class="bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
            <section class="space-y-6 text-slate-700 leading-relaxed">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 mb-2">1) Quais dados coletamos</h2>
                    <ul class="list-disc pl-5 space-y-1 text-slate-700">
                        <li><strong>Cadastro:</strong> nome, e-mail, telefone e senha (armazenada como hash).</li>
                        <li><strong>Perfil e uso:</strong> informações do perfil, preferências e interações dentro da plataforma.</li>
                        <li><strong>Dados técnicos:</strong> IP, navegador, datas/horários e eventos necessários para segurança e funcionamento.</li>
                    </ul>
                </div>

                <div>
                    <h2 class="text-lg font-bold text-slate-900 mb-2">2) Como usamos os dados</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Operar a plataforma, autenticar usuários e manter sessões seguras.</li>
                        <li>Exibir informações de perfis e permitir contato direto quando aplicável.</li>
                        <li>Prevenir fraudes, abuso, acessos indevidos e melhorar a experiência.</li>
                        <li>Enviar comunicações essenciais (ex.: redefinição de senha e avisos de segurança).</li>
                    </ul>
                </div>

                <div>
                    <h2 class="text-lg font-bold text-slate-900 mb-2">3) Cookies e tecnologias semelhantes</h2>
                    <p>
                        Usamos cookies para manter sua sessão, lembrar preferências e apoiar recursos de segurança. Você pode gerenciar cookies nas configurações do seu navegador.
                    </p>
                </div>

                <div>
                    <h2 class="text-lg font-bold text-slate-900 mb-2">4) Compartilhamento de dados</h2>
                    <p>
                        Não vendemos dados pessoais. O compartilhamento pode ocorrer somente quando necessário para:
                    </p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Operação do serviço (ex.: provedores de infraestrutura e e-mail transacional).</li>
                        <li>Cumprimento de obrigação legal ou ordem de autoridade competente.</li>
                        <li>Proteção da plataforma e dos usuários (segurança e prevenção de fraude).</li>
                    </ul>
                </div>

                <div>
                    <h2 class="text-lg font-bold text-slate-900 mb-2">5) Retenção e segurança</h2>
                    <p>
                        Mantemos dados pelo tempo necessário para as finalidades descritas e para cumprir obrigações legais. Aplicamos medidas técnicas e organizacionais para proteger as informações contra acesso não autorizado.
                    </p>
                </div>

                <div>
                    <h2 class="text-lg font-bold text-slate-900 mb-2">6) Seus direitos</h2>
                    <p>
                        Você pode solicitar confirmação, acesso, correção e exclusão de dados, quando aplicável. Algumas informações podem ser mantidas por obrigação legal ou por segurança.
                    </p>
                </div>
            </section>

            <section class="mt-8 pt-6 border-t border-slate-100">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-sm text-slate-700">
                        Dúvidas sobre privacidade? Acesse a página de <a class="text-blue-700 font-semibold hover:underline" href="<?php echo htmlspecialchars(appPath('/access/contato.php'), ENT_QUOTES, 'UTF-8'); ?>">Contato</a>.
                    </p>
                </div>
                <p class="mt-4 text-xs text-slate-500">
                    Ao continuar usando a plataforma, você concorda com esta Política de Privacidade.
                </p>
            </section>
        </article>
    </main>

    <footer class="bg-slate-900 text-slate-400 py-12 border-t border-slate-800">
        <div class="container mx-auto px-6 text-center">
            <div class="flex items-center justify-center gap-2 text-white font-bold text-xl mb-8">
                Clube dos parceiros
            </div>
            <p class="mb-6 text-sm max-w-md mx-auto">
                Plataforma de conexão entre clientes e profissionais de manutenção, com avaliações reais e contato direto.
            </p>
            <div class="flex justify-center gap-6 mb-8">
                <a href="<?php echo htmlspecialchars(appPath('/access/termos_responsabilidade.php'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-white transition-colors">Termos de Uso</a>
                <a href="<?php echo htmlspecialchars(appPath('/access/politica_privacidade.php'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-white transition-colors">Privacidade</a>
                <a href="<?php echo htmlspecialchars(appPath('/access/contato.php'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-white transition-colors">Contato</a>
            </div>
            <p class="text-xs">© 2025. Todos os direitos reservados.</p>
        </div>
    </footer>
</body>
</html>
