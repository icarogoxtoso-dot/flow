<?php
require_once __DIR__ . '/../secure/auth.php';
startSecureSession();

$cfg = appConfig();
$csrf = ensureCsrfToken();

function extractEmailAddress(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/<([^>]+)>/', $raw, $m)) {
        return trim($m[1]);
    }
    return $raw;
}

function canSendContactEmail(array $cfg, string $supportEmail): bool
{
    if (($cfg['mail_provider'] ?? '') !== 'resend') {
        return false;
    }
    $apiKey = (string) ($cfg['resend_api_key'] ?? '');
    $from = (string) ($cfg['mail_from'] ?? '');
    if ($apiKey === '' || $from === '' || $supportEmail === '') {
        return false;
    }
    return function_exists('curl_init');
}

function sendContactEmailResend(array $cfg, string $supportEmail, string $name, string $email, string $subject, string $message): bool
{
    $apiKey = (string) ($cfg['resend_api_key'] ?? '');
    $from = (string) ($cfg['mail_from'] ?? '');

    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $subjectSafe = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $messageSafe = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

    $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Contato</title></head>'
        . '<body style="margin:0;padding:0;background:#f0f4f8;color:#0f172a;font-family:Inter,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;">'
        . '<tr><td style="background:#ffffff;border:1px solid #d9e2ec;border-radius:16px;padding:18px 18px;">'
        . '<h1 style="margin:0 0 10px 0;font-size:18px;line-height:1.25;">Nova mensagem de contato</h1>'
        . '<p style="margin:0 0 8px 0;color:#64748b;font-size:13px;line-height:1.6;"><strong>Nome:</strong> ' . $nameSafe . '</p>'
        . '<p style="margin:0 0 8px 0;color:#64748b;font-size:13px;line-height:1.6;"><strong>E-mail:</strong> ' . $emailSafe . '</p>'
        . '<p style="margin:0 0 12px 0;color:#64748b;font-size:13px;line-height:1.6;"><strong>Assunto:</strong> ' . $subjectSafe . '</p>'
        . '<div style="margin:0;color:#0f172a;font-size:14px;line-height:1.7;border-top:1px solid #d9e2ec;padding-top:12px;">' . $messageSafe . '</div>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    $payload = [
        'from' => $from,
        'to' => [$supportEmail],
        'subject' => '[Contato] ' . $subject,
        'text' => "Nome: {$name}\nE-mail: {$email}\nAssunto: {$subject}\n\n{$message}\n",
        'html' => $html,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 12,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

$supportEmail = trim((string) envValue('SUPPORT_EMAIL', ''));
if ($supportEmail === '') {
    $supportEmail = extractEmailAddress((string) ($cfg['mail_from'] ?? ''));
}

$supportWhatsApp = trim((string) envValue('SUPPORT_WHATSAPP_E164', ''));
$supportHours = trim((string) envValue('SUPPORT_HOURS', 'Seg a Sex, 09h às 18h'));

$success = '';
$error = '';
$formName = '';
$formEmail = '';
$formSubject = '';
$formMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfTokenOrFail($_POST['csrf_token'] ?? null)) {
        $error = 'Falha de segurança (CSRF). Atualize a página e tente novamente.';
    } else {
        $honeypot = trim((string) ($_POST['company'] ?? ''));
        $formName = trim((string) ($_POST['nome'] ?? ''));
        $formEmail = trim((string) ($_POST['email'] ?? ''));
        $formSubject = trim((string) ($_POST['assunto'] ?? ''));
        $formMessage = trim((string) ($_POST['mensagem'] ?? ''));

        $now = time();
        $last = (int) ($_SESSION['contact_last_submit'] ?? 0);
        if ($last > 0 && ($now - $last) < 45) {
            $error = 'Aguarde alguns segundos antes de enviar outra mensagem.';
        } elseif ($honeypot !== '') {
            $success = 'Mensagem enviada com sucesso.';
        } elseif ($formName === '' || mb_strlen($formName) > 80) {
            $error = 'Informe um nome válido.';
        } elseif (!filter_var($formEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($formEmail) > 190) {
            $error = 'Informe um e-mail válido.';
        } elseif ($formSubject === '' || mb_strlen($formSubject) > 120) {
            $error = 'Informe um assunto válido.';
        } elseif (mb_strlen($formMessage) < 10 || mb_strlen($formMessage) > 2000) {
            $error = 'A mensagem deve ter entre 10 e 2000 caracteres.';
        } elseif (!canSendContactEmail($cfg, $supportEmail)) {
            $error = 'No momento, o formulário de contato está indisponível. Use o e-mail ou WhatsApp.';
        } else {
            $sent = sendContactEmailResend($cfg, $supportEmail, $formName, $formEmail, $formSubject, $formMessage);
            if ($sent) {
                $_SESSION['contact_last_submit'] = $now;
                $success = 'Mensagem enviada. Em breve entraremos em contato.';
                $formSubject = '';
                $formMessage = '';
            } else {
                $error = 'Não foi possível enviar sua mensagem agora. Tente novamente em instantes.';
            }
        }
    }
}

$whatsDigits = preg_replace('/\\D+/', '', $supportWhatsApp);
$whatsLink = $whatsDigits !== '' ? 'https://wa.me/' . $whatsDigits : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Entre em contato com o Clube dos Parceiros para suporte, dúvidas e sugestões.">
    <link rel="canonical" href="https://clubedosparceiros.cloud/access/contato.php">
    <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">

    <meta property="og:site_name" content="Clube dos Parceiros">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Contato - Clube dos Parceiros">
    <meta property="og:description" content="Fale com a equipe do Clube dos Parceiros.">
    <meta property="og:url" content="https://clubedosparceiros.cloud/access/contato.php">
    <meta property="og:image" content="https://clubedosparceiros.cloud/img/logo.png">
    <meta property="og:image:alt" content="Logo do Clube dos Parceiros">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Contato - Clube dos Parceiros">
    <meta name="twitter:description" content="Fale com a equipe do Clube dos Parceiros.">
    <meta name="twitter:image" content="https://clubedosparceiros.cloud/img/logo.png">

    <meta name="theme-color" content="#1A3D63">
    <title>Contato - Clube dos Parceiros</title>
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
        <section class="mb-6">
            <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-xs font-semibold text-slate-600">
                <span class="h-2 w-2 rounded-full bg-blue-700"></span>
                Atendimento e suporte
            </div>
            <h1 class="mt-3 text-2xl md:text-3xl font-extrabold text-slate-900 tracking-tight">Fale com a gente</h1>
            <p class="mt-2 text-slate-600 leading-relaxed">
                Se precisar de suporte, tiver dúvidas comerciais ou quiser enviar sugestões, use um dos canais abaixo.
            </p>
        </section>

        <article class="bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm">
            <?php if ($success !== ''): ?>
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm font-semibold">
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php elseif ($error !== ''): ?>
                <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800 text-sm font-semibold">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-sm text-slate-500 mb-1">E-mail</p>
                    <?php if ($supportEmail !== ''): ?>
                        <a href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-700 font-semibold hover:underline break-all">
                            <?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php else: ?>
                        <p class="text-slate-700 font-semibold">Indisponível</p>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-sm text-slate-500 mb-1">WhatsApp</p>
                    <?php if ($whatsLink !== ''): ?>
                        <a href="<?php echo htmlspecialchars($whatsLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-700 font-semibold hover:underline">
                            <?php echo htmlspecialchars($supportWhatsApp, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php else: ?>
                        <p class="text-slate-700 font-semibold">Indisponível</p>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-sm text-slate-500 mb-1">Horário</p>
                    <p class="text-slate-700 font-semibold"><?php echo htmlspecialchars($supportHours, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </section>

            <section class="border-t border-slate-100 pt-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Enviar mensagem</h2>
                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="hidden" aria-hidden="true">
                        <label for="company">Empresa</label>
                        <input id="company" name="company" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    <div>
                        <label for="nome" class="block text-sm font-semibold text-slate-700 mb-1">Nome</label>
                        <input id="nome" name="nome" type="text" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="Seu nome" maxlength="80" required value="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-semibold text-slate-700 mb-1">E-mail</label>
                        <input id="email" name="email" type="email" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="seu@email.com" maxlength="190" required value="<?php echo htmlspecialchars($formEmail, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="md:col-span-2">
                        <label for="assunto" class="block text-sm font-semibold text-slate-700 mb-1">Assunto</label>
                        <input id="assunto" name="assunto" type="text" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="Como podemos ajudar?" maxlength="120" required value="<?php echo htmlspecialchars($formSubject, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="md:col-span-2">
                        <label for="mensagem" class="block text-sm font-semibold text-slate-700 mb-1">Mensagem</label>
                        <textarea id="mensagem" name="mensagem" rows="6" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500" placeholder="Escreva sua mensagem" minlength="10" maxlength="2000" required><?php echo htmlspecialchars($formMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <p class="mt-2 text-xs text-slate-500">Não envie senha, dados bancários ou informações sensíveis neste formulário.</p>
                    </div>
                    <div class="md:col-span-2 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-500">
                            Ao enviar, você concorda com nossa <a class="text-blue-700 font-semibold hover:underline" href="<?php echo htmlspecialchars(appPath('/access/politica_privacidade.php'), ENT_QUOTES, 'UTF-8'); ?>">Política de Privacidade</a>.
                        </p>
                        <button type="submit" class="px-5 py-3 rounded-xl bg-blue-900 text-white font-semibold hover:bg-blue-800 transition">
                            Enviar mensagem
                        </button>
                    </div>
                </form>
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
