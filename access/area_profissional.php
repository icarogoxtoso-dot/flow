<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe_db.php';

startSecureSession();

if (!isAuthenticated()) {
    header('Location: ' . appPath('/access/login.php?mode=login&next=' . rawurlencode(appPath('/access/area_profissional.php'))));
    exit;
}

$cfg = appConfig();
$host = $cfg['db_host'];
$db = $cfg['db_name'];
$user = $cfg['db_user'];
$pass = $cfg['db_pass'];
$charset = $cfg['db_charset'];

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$subscription = null;
$canEditProfile = false;
$dbError = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, $options);
    ensureStripeTables($pdo);
    $uid = (int) (currentUserId() ?? 0);
    $subscription = $uid > 0 ? getUserSubscription($pdo, $uid) : null;
    $canEditProfile = $uid > 0 ? userCanCreateProfile($pdo, $uid) : false;
} catch (Throwable $e) {
    $dbError = 'Não foi possível verificar sua assinatura.';
}

$status = is_array($subscription) ? (string) ($subscription['subscription_status'] ?? '') : '';
$periodEnd = is_array($subscription) ? (string) ($subscription['current_period_end'] ?? '') : '';
$userName = currentUserName();

$hasValidSub = $dbError === '' && $canEditProfile;
$statusLabel = $dbError !== '' ? 'Erro' : ($status !== '' ? $status : 'Não encontrada');
$statusTone = $hasValidSub ? 'bg-emerald-500/10 text-emerald-700 border-emerald-200' : 'bg-amber-500/10 text-amber-800 border-amber-200';
$periodLabel = $periodEnd !== '' ? $periodEnd : '-';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área do Profissional - Clube dos Parceiros</title>
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
                <div class="flex items-center gap-2">
                    <a href="<?php echo htmlspecialchars(appPath('/access/checkout.html'), ENT_QUOTES, 'UTF-8'); ?>"
                       class="hidden sm:inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300 transition">
                        Assinar
                    </a>
                    <a href="<?php echo htmlspecialchars(appPath('/access/logout.php'), ENT_QUOTES, 'UTF-8'); ?>"
                       class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition">
                        Sair
                    </a>
                </div>
            </div>
        </nav>

        <main class="max-w-5xl mx-auto px-4 py-10">
            <div class="grid gap-6">
                <header class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold tracking-[0.18em] uppercase text-slate-500">Área do profissional</p>
                        <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold text-slate-900">Bem-vindo<?php echo $userName !== '' ? ', ' . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') : ''; ?>.</h1>
                        <p class="mt-2 text-slate-600">Acesse seu perfil e veja a situação da assinatura.</p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold <?php echo $statusTone; ?>">
                        <span class="w-2.5 h-2.5 rounded-full <?php echo $hasValidSub ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span>
                        <span>Status:</span>
                        <span class="font-black"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </header>

                <section class="bg-white border border-slate-200 rounded-2xl p-6 sm:p-7 shadow-sm">
                    <?php if ($dbError !== ''): ?>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                <p class="text-sm font-semibold text-slate-700">Assinatura</p>
                                <p class="mt-2 text-2xl font-extrabold text-slate-900"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="mt-2 text-sm text-slate-600">Válida até <span class="font-semibold"><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></span></p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                                <p class="text-sm font-semibold text-slate-700">Próximo passo</p>
                                <p class="mt-2 text-sm text-slate-600"><?php echo $canEditProfile ? 'Editar ou completar seu perfil.' : 'Assinar para liberar o acesso ao perfil.'; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-6 flex flex-col sm:flex-row gap-3">
                        <?php if ($canEditProfile): ?>
                            <a href="<?php echo htmlspecialchars(appPath('/secure/save_profile.php'), ENT_QUOTES, 'UTF-8'); ?>"
                               class="inline-flex w-full sm:w-auto justify-center rounded-xl bg-blue-900 px-6 py-3 text-white font-semibold hover:bg-blue-800 transition">
                                Criar / editar meu perfil
                            </a>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars(appPath('/access/criar_conta.php'), ENT_QUOTES, 'UTF-8'); ?>"
                               class="inline-flex w-full sm:w-auto justify-center rounded-xl bg-blue-900 px-6 py-3 text-white font-semibold hover:bg-blue-800 transition">
                                Assinar para liberar
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars(appPath('/access/painel.php'), ENT_QUOTES, 'UTF-8'); ?>"
                           class="inline-flex w-full sm:w-auto justify-center rounded-xl border border-slate-200 bg-white px-6 py-3 text-slate-800 font-semibold hover:border-blue-300 transition">
                            Ver profissionais
                        </a>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
