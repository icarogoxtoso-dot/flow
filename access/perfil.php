<?php
session_start();
require_once __DIR__ . '/../secure/config.php';

$cfg = appConfig();
$host = $cfg['db_host'];
$db   = $cfg['db_name'];
$user = $cfg['db_user'];
$pass = $cfg['db_pass'];
$charset = $cfg['db_charset'];

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Erro na conexao: ' . $e->getMessage());
}

$publicId = trim((string) ($_GET['p'] ?? $_GET['public_id'] ?? ''));
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (preg_match('/^[a-f0-9]{32}$/', $publicId)) {
    $stmt = $pdo->prepare('SELECT * FROM profissionais WHERE public_id = :public_id LIMIT 1');
    $stmt->execute([':public_id' => $publicId]);
} elseif ($id) {
    $stmt = $pdo->prepare('SELECT * FROM profissionais WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
} else {
    $stmt = $pdo->query('SELECT * FROM profissionais ORDER BY id DESC LIMIT 1');
}

$pro = $stmt ? $stmt->fetch() : false;
if (!$pro) {
    die('Profissional não encontrado.');
}

$name = trim((string) ($pro['nome'] ?? $pro['nome_completo'] ?? 'Profissional'));
$photo = trim((string) ($pro['foto_perfil'] ?? ''));
$city = trim((string) ($pro['cidade'] ?? ''));
$district = trim((string) ($pro['bairro'] ?? ''));
$locationLabel = trim($district . ', ' . $city, ', ');
$startYear = (int) ($pro['desde'] ?? 0);
$rating = (float) ($pro['nota'] ?? 0);
$online = ((int) ($pro['online'] ?? 0)) === 1;
$description = trim((string) ($pro['descricao'] ?? $pro['bio'] ?? ''));
$email = trim((string) ($pro['email'] ?? ''));
$instagram = trim((string) ($pro['instagram'] ?? ''));
$siteUrl = trim((string) ($pro['site_url'] ?? ''));
$facebook = trim((string) ($pro['facebook'] ?? ''));

$phone = preg_replace('/\D+/', '', (string) ($pro['whatsapp'] ?? $pro['telefone'] ?? ''));
$phoneDisplay = $phone !== '' ? $phone : 'Não informado';
$whatsUrl = $phone !== '' ? 'https://wa.me/' . $phone : '';

$especialidades = [];
if (!empty($pro['tags'])) {
    $especialidades = array_values(array_filter(array_map('trim', explode(',', (string) $pro['tags']))));
} else {
    $fromJson = json_decode((string) ($pro['serviÃ§os'] ?? '[]'), true);
    if (is_array($fromJson)) {
        $especialidades = array_values(array_filter(array_map(static fn($item) => is_string($item) ? trim($item) : '', $fromJson)));
    }
}

$fotosRaw = (string) ($pro['fotos_trabalhos'] ?? $pro['fotos_trabalho'] ?? '[]');
$fotos_trabalho = json_decode($fotosRaw, true);
if (!is_array($fotos_trabalho)) {
    $fotos_trabalho = [];
}

$anoAtual = (int) date('Y');
$anosExp = $startYear > 0 ? max(0, $anoAtual - $startYear) : 0;

if (empty($_SESSION['feedback_csrf']) || !is_string($_SESSION['feedback_csrf'])) {
    $_SESSION['feedback_csrf'] = bin2hex(random_bytes(32));
}
$feedbackCsrf = (string) $_SESSION['feedback_csrf'];
$publicProfileId = trim((string) ($pro['public_id'] ?? ''));
$canSubmitFeedback = preg_match('/^[a-f0-9]{32}$/', $publicProfileId) === 1;
$feedbackCount = 0;
$averageRating = 0.0;
$feedbacks = [];

try {
    $aggStmt = $pdo->prepare(
        "SELECT ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS total_feedbacks
         FROM feedbacks
         WHERE profissional_id = :profissional_id"
    );
    $aggStmt->execute([':profissional_id' => (int) $pro['id']]);
    $agg = $aggStmt->fetch() ?: ['avg_rating' => 0, 'total_feedbacks' => 0];
    $averageRating = (float) ($agg['avg_rating'] ?? 0);
    $feedbackCount = (int) ($agg['total_feedbacks'] ?? 0);

    $fbStmt = $pdo->prepare(
        "SELECT client_name, rating, comment, image_path, created_at
         FROM feedbacks
         WHERE profissional_id = :profissional_id
         ORDER BY id DESC
         LIMIT 10"
    );
    $fbStmt->execute([':profissional_id' => (int) $pro['id']]);
    $feedbacks = $fbStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $feedbackCount = 0;
    $averageRating = 0.0;
    $feedbacks = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Confira o perfil profissional de <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?> no Clube dos Parceiros. Veja serviços e formas de contato.">
    <link rel="canonical" href="<?php echo htmlspecialchars(rtrim((string) (appConfig()['app_url'] ?? ''), '/') . appPath('/access/perfil.php?p=' . $publicProfileId), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">

    <meta property="og:site_name" content="Clube dos Parceiros">
    <meta property="og:type" content="profile">
    <meta property="og:title" content="Perfil - <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="Confira serviços e formas de contato.">
    <meta property="og:url" content="<?php echo htmlspecialchars(rtrim((string) (appConfig()['app_url'] ?? ''), '/') . appPath('/access/perfil.php?p=' . $publicProfileId), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="https://clubedosparceiros.cloud/img/logo.png">
    <meta property="og:image:alt" content="Logo do Clube dos Parceiros">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Perfil - <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="Confira serviços e formas de contato no Clube dos Parceiros.">
    <meta name="twitter:image" content="https://clubedosparceiros.cloud/img/logo.png">

    <meta name="theme-color" content="#1e3a8a">
    <title>Perfil - <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/img/logomenor.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/theme.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .pro-card { background: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; }
        .service-badge { background-color: #eff6ff; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 0.875rem; font-weight: 500; }
        .toast { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%); background: #1e293b; color: white; padding: 0.75rem 1.5rem; border-radius: 99px; display: none; z-index: 1000; }
        .top-header {
            background: #ffffff;
            border-bottom: 1px solid #d9e2ec;
        }
        .brand-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #1e3a8a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(30, 58, 138, .18);
            overflow: hidden;
            flex-shrink: 0;
        }
        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 6px;
        }
        .brand-title {
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: -0.01em;
            font-size: 1.05rem;
            line-height: 1.1;
            white-space: nowrap;
        }
        .profile-spotlight {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(59, 130, 246, 0.28), transparent 32%),
                linear-gradient(135deg, #0f172a 0%, #0b132b 55%, #111827 100%);
            border-color: rgba(30, 41, 59, 0.9);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
        }
        .profile-spotlight::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(255, 255, 255, 0.06), transparent 45%);
            pointer-events: none;
        }
        .about-copy {
            position: relative;
            color: #cbd5e1;
            font-size: 1rem;
            line-height: 1.9;
            max-width: 62ch;
        }
        .stat-panel {
            background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
            border-color: rgba(30, 41, 59, 0.95);
            color: #e2e8f0;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
        }
        .metric-card {
            background: rgba(148, 163, 184, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.14);
            border-radius: 1rem;
            padding: 1rem;
            min-height: 116px;
            transition: transform .25s ease, border-color .25s ease, background-color .25s ease;
        }
        .metric-card:hover {
            transform: translateY(-3px);
            border-color: rgba(96, 165, 250, 0.3);
            background: rgba(59, 130, 246, 0.09);
        }
        .metric-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(59, 130, 246, 0.14);
            color: #93c5fd;
        }
        .metric-label {
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .metric-value {
            color: #f8fafc;
            font-size: 1.85rem;
            line-height: 1.1;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .metric-subtext {
            color: #cbd5e1;
            font-size: 0.92rem;
        }
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        .gallery-trigger {
            position: relative;
            display: block;
            overflow: hidden;
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
            cursor: zoom-in;
        }
        .gallery-trigger img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            transition: transform .3s ease, filter .3s ease;
        }
        .gallery-trigger::after {
            content: 'Ampliar';
            position: absolute;
            right: 0.75rem;
            bottom: 0.75rem;
            background: rgba(15, 23, 42, 0.72);
            color: #fff;
            padding: 0.35rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .gallery-trigger:hover img,
        .gallery-trigger:focus-visible img {
            transform: scale(1.04);
            filter: saturate(1.05);
        }
        .feedback-zoom {
            cursor: zoom-in;
            transition: transform .25s ease, box-shadow .25s ease;
        }
        .feedback-zoom:hover {
            transform: scale(1.01);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
        }
        .lightbox {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(2, 6, 23, 0.82);
            backdrop-filter: blur(10px);
        }
        .lightbox.is-open {
            display: flex;
        }
        .lightbox-dialog {
            width: min(100%, 960px);
            max-height: calc(100vh - 2rem);
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(2, 6, 23, 0.45);
        }
        .lightbox-frame {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.88));
            min-height: 300px;
            max-height: calc(100vh - 8rem);
            padding: 1rem;
        }
        .lightbox-frame img {
            max-width: 100%;
            max-height: calc(100vh - 10rem);
            object-fit: contain;
            border-radius: 1rem;
        }
        @media (max-width: 767px) {
            .pro-card {
                border-radius: 1.25rem;
            }
            .metric-value {
                font-size: 1.55rem;
            }
            .gallery-grid {
                grid-template-columns: 1fr;
            }
            .lightbox {
                padding: 0.5rem;
            }
            .lightbox-dialog {
                border-radius: 1rem;
            }
            .lightbox-frame {
                min-height: 220px;
                padding: 0.75rem;
            }
        }
    </style>
</head>
<body class="min-h-screen text-slate-900 bg-slate-50 antialiased selection:bg-blue-200 selection:text-blue-900">

    <div id="toast" class="toast text-sm font-medium"></div>

    <nav class="fixed top-0 w-full z-50 bg-white/80 backdrop-blur-md border-b border-slate-100">
        <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center gap-2">
            <div class="flex items-center gap-2 text-blue-900 font-black text-base sm:text-xl min-w-0">
                <div class="bg-blue-900 text-white p-1.5 rounded-lg">
                    <img src="../img/logomenor.png" alt="Logo Clube dos Parceiros" class="h-10 w-auto rounded-md object-contain">
                </div>
                <span class="truncate">Clube dos Parceiros</span>
            </div>
            <a href="<?php echo htmlspecialchars(appPath('/access/painel.php'), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 sm:px-4 py-2 rounded-lg font-semibold transition-all duration-300 transform hover:-translate-y-1 shadow-md flex items-center justify-center gap-2 bg-white text-blue-900 border-2 border-blue-900 hover:bg-blue-50 text-xs sm:text-sm whitespace-nowrap">
                Voltar ao painel
            </a>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-4 pt-24 pb-8 space-y-6 mb-20">
        <section class="pro-card p-4 sm:p-6">
            <div class="flex flex-col md:flex-row gap-5 md:gap-6 items-start">
                <div class="relative self-center md:self-start">
                    <img src="<?php echo htmlspecialchars($photo !== '' ? $photo : 'https://via.placeholder.com/150', ENT_QUOTES, 'UTF-8'); ?>" class="w-32 h-32 rounded-full object-cover border-4 border-white shadow-md" alt="Foto do profissional">
                    <?php if ($online): ?>
                        <span class="absolute bottom-2 right-2 w-5 h-5 bg-green-500 border-4 border-white rounded-full" title="Online agora"></span>
                    <?php endif; ?>
                </div>

                <div class="flex-1 space-y-3 w-full min-w-0">
                    <div class="flex flex-wrap items-center gap-2 justify-center md:justify-start text-center md:text-left">
                        <h1 class="text-2xl md:text-3xl font-bold text-slate-900 break-words"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h1>
                        <span class="flex items-center gap-1 bg-blue-50 text-blue-700 text-xs font-bold px-2 py-1 rounded">
                            <i data-lucide="badge-check" class="w-4 h-4"></i> VERIFICADO
                        </span>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-4 text-slate-600 text-sm text-center md:text-left">
                        <div class="flex items-center justify-center md:justify-start gap-1 min-w-0"><i data-lucide="map-pin" class="w-4 h-4 shrink-0"></i> <span class="break-words"><?php echo htmlspecialchars($locationLabel !== '' ? $locationLabel : 'Local não informado', ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="flex items-center justify-center md:justify-start gap-1"><i data-lucide="calendar" class="w-4 h-4 shrink-0"></i> Desde <?php echo htmlspecialchars($startYear > 0 ? (string) $startYear : '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-2 justify-center md:justify-start">
                        <?php foreach ($especialidades as $serviÃ§o): ?>
                            <span class="service-badge"><?php echo htmlspecialchars($serviÃ§o, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mt-4 pt-4 border-t border-slate-50">
                        <div><p class="text-xs text-slate-400 uppercase">Avaliação</p><div class="flex items-center gap-1 font-bold text-yellow-500"><?php echo htmlspecialchars(number_format($rating, 1, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> <i data-lucide="star" class="w-4 h-4 fill-current"></i></div></div>
                        <div><p class="text-xs text-slate-400 uppercase">Tags</p><p class="font-medium text-xs text-slate-600"><?php echo htmlspecialchars(implode(', ', $especialidades), ENT_QUOTES, 'UTF-8'); ?></p></div>
                    </div>
                </div>

                <div class="flex flex-col gap-3 w-full md:w-auto md:min-w-[220px]">
                    <?php if ($whatsUrl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($whatsUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="bg-blue-600 text-center text-white font-semibold py-3 px-6 rounded-xl shadow-lg hover:bg-blue-700 transition">Solicitar Orçamento</a>
                    <?php else: ?>
                        <button type="button" class="bg-slate-300 text-center text-slate-500 font-semibold py-3 px-6 rounded-xl cursor-not-allowed" disabled>Solicitar Orçamento</button>
                    <?php endif; ?>
                    <button id="btn-phone" onclick="showPhone('<?php echo htmlspecialchars($phoneDisplay, ENT_QUOTES, 'UTF-8'); ?>')" class="bg-white border border-slate-200 text-slate-700 font-semibold py-3 px-6 rounded-xl transition hover:border-blue-400">Ver telefone</button>
                    <button onclick="shareProfile()" class="text-slate-500 text-sm flex items-center justify-center gap-1 hover:text-blue-600"><i data-lucide="share-2" class="w-4 h-4"></i> Compartilhar Perfil</button>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-3 space-y-6">



                <section class="pro-card profile-spotlight p-6 md:p-8 text-slate-100">
                    <div class="relative z-10 space-y-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-lg md:text-xl font-bold flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-white/10 border border-white/10 text-blue-300">
                                    <i data-lucide="user-round" class="w-5 h-5"></i>
                                </span>
                                Sobre o Profissional
                            </h2>
                            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold tracking-[0.18em] uppercase text-slate-300">
                                <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                                Perfil em destaque
                            </span>
                        </div>
                        <div class="rounded-3xl border border-white/10 bg-white/5 p-5 md:p-6 backdrop-blur-sm">
                            <p class="about-copy"><?php echo nl2br(htmlspecialchars($description !== '' ? $description : 'Descrição não informada.', ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                    </div>
                </section>

                <section class="pro-card p-4 sm:p-6">
                    <h2 class="text-lg font-bold mb-6 flex items-center gap-2"><i data-lucide="camera" class="text-blue-600"></i> Fotos de Trabalhos Realizados</h2>
                    <?php if (!empty($fotos_trabalho)): ?>
                        <?php $totalFotosTrabalho = count($fotos_trabalho); ?>
                        <div class="space-y-4">
                            <div id="work-gallery" class="gallery-grid">
                                <?php foreach ($fotos_trabalho as $idx => $foto): ?>
                                    <button
                                        type="button"
                                        class="gallery-trigger js-open-lightbox js-work-photo<?php echo $idx >= 2 ? ' hidden' : ''; ?>"
                                        data-gallery-index="<?php echo (int) $idx; ?>"
                                        data-image-src="<?php echo htmlspecialchars((string) $foto, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-image-alt="Trabalho concluido"
                                    >
                                        <img src="<?php echo htmlspecialchars((string) $foto, ENT_QUOTES, 'UTF-8'); ?>" alt="Trabalho concluido">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($totalFotosTrabalho > 2): ?>
                                <button
                                    type="button"
                                    id="toggle-works"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-white hover:border-blue-300 transition flex items-center justify-center gap-2"
                                    aria-expanded="false"
                                    aria-controls="work-gallery"
                                >
                                    <i data-lucide="chevron-down" class="w-4 h-4 transform transition-transform js-works-toggle-icon"></i>
                                    <span id="toggle-works-label">Mostrar mais trabalhos</span>
                                    <span id="toggle-works-count" class="text-slate-500 font-semibold">+<?php echo (int) ($totalFotosTrabalho - 2); ?></span>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="rounded-lg border border-dashed border-slate-300 py-8 px-4 text-center text-sm text-slate-400">
                            Nenhuma foto de trabalho cadastrada ainda.
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="space-y-6 lg:col-span-2">


                <section class="pro-card stat-panel p-6 md:p-7">
                    <div class="flex items-start justify-between gap-3 mb-5">
                        <div>
                            <h2 class="text-xl font-extrabold text-white mb-1 flex items-center gap-2">
                                <i data-lucide="bar-chart-3" class="w-5 h-5 text-blue-300"></i>
                                Estatísticas
                            </h2>
                            <p class="text-sm text-slate-400">Indicadores principais do profissional.</p>
                        </div>
                        <span class="hidden sm:inline-flex rounded-full border border-blue-400/20 bg-blue-400/10 px-3 py-1 text-xs font-semibold text-blue-200">
                            Atualizado agora
                        </span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4">
                        <div class="metric-card">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="metric-label">Tempo no mercado</p>
                                    <p class="metric-value mt-3"><?php echo (int) $anosExp; ?> <span class="text-lg font-semibold text-slate-300">anos</span></p>
                                    <p class="metric-subtext mt-2">Experiência acumulada desde <?php echo htmlspecialchars($startYear > 0 ? (string) $startYear : '-', ENT_QUOTES, 'UTF-8'); ?>.</p>
                                </div>
                                <span class="metric-icon">
                                    <i data-lucide="briefcase-business" class="w-5 h-5"></i>
                                </span>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="metric-label">Disponibilidade</p>
                                    <p class="metric-value mt-3 <?php echo $online ? 'text-green-300' : 'text-slate-100'; ?>"><?php echo $online ? 'Online' : 'Offline'; ?></p>
                                    <p class="metric-subtext mt-2 inline-flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 rounded-full <?php echo $online ? 'bg-green-400' : 'bg-slate-400'; ?>"></span>
                                        <?php echo $online ? 'Respondendo no momento.' : 'Sem atividade agora.'; ?>
                                    </p>
                                </div>
                                <span class="metric-icon <?php echo $online ? '!bg-green-400/15 !text-green-300' : ''; ?>">
                                    <i data-lucide="<?php echo $online ? 'wifi' : 'moon-star'; ?>" class="w-5 h-5"></i>
                                </span>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="metric-label">Avaliação</p>
                                    <p class="metric-value mt-3">
                                        <?php echo htmlspecialchars(number_format($averageRating > 0 ? $averageRating : $rating, 1, ',', '.'), ENT_QUOTES, 'UTF-8'); ?>
                                        <span class="text-lg text-amber-300 align-middle">&#9733;</span>
                                    </p>
                                    <p class="metric-subtext mt-2">Média consolidada das avaliações recebidas.</p>
                                </div>
                                <span class="metric-icon !bg-amber-400/15 !text-amber-300">
                                    <i data-lucide="star" class="w-5 h-5 fill-current"></i>
                                </span>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="metric-label">Feedbacks</p>
                                    <p class="metric-value mt-3"><?php echo (int) $feedbackCount; ?></p>
                                    <p class="metric-subtext mt-2"><?php echo $feedbackCount === 1 ? '1 avaliação publicada.' : htmlspecialchars((string) $feedbackCount, ENT_QUOTES, 'UTF-8') . ' avaliações publicadas.'; ?></p>
                                </div>
                                <span class="metric-icon">
                                    <i data-lucide="messages-square" class="w-5 h-5"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pro-card p-6"> 
                    <h2 class="text-lg font-bold mb-4">Informações de Contato</h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center gap-3 text-slate-600">
                            <i data-lucide="mail" class="w-5 h-5 text-blue-500"></i>
                            <span><?php echo htmlspecialchars($email !== '' ? $email : 'Não informado', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="flex items-center gap-3 text-slate-600">
                            <i data-lucide="phone" class="w-5 h-5 text-blue-500"></i>
                            <span id="txt-phone">********</span>
                        </div>
                        <?php if ($instagram !== ''): ?>
                        <div class="flex items-center gap-3 text-slate-600">
                            <i data-lucide="instagram" class="w-5 h-5 text-blue-500"></i>
                            <a href="<?php echo htmlspecialchars($instagram, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="hover:text-blue-700 hover:underline break-all">
                                <?php echo htmlspecialchars($instagram, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if ($siteUrl !== ''): ?>
                        <div class="flex items-center gap-3 text-slate-600">
                            <i data-lucide="globe" class="w-5 h-5 text-blue-500"></i>
                            <a href="<?php echo htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="hover:text-blue-700 hover:underline break-all">
                                <?php echo htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if ($facebook !== ''): ?>
                        <div class="flex items-center gap-3 text-slate-600">
                            <i data-lucide="facebook" class="w-5 h-5 text-blue-500"></i>
                            <a href="<?php echo htmlspecialchars($facebook, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="hover:text-blue-700 hover:underline break-all">
                                <?php echo htmlspecialchars($facebook, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>

        <section class="pro-card p-4 sm:p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900">Avaliações e Comentários</h2>
                <p class="text-xl md:text-2xl font-semibold text-slate-600">&#9733; <?php echo htmlspecialchars(number_format($averageRating, 1, ',', '.'), ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int) $feedbackCount; ?>)</p>
            </div>

            <?php if ($canSubmitFeedback): ?>
            <form id="feedbackForm" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-5">
                <div>
                    <label for="fbName" class="block text-sm font-semibold text-slate-700 mb-1">Seu nome (opcional)</label>
                    <input id="fbName" name="client_name" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" maxlength="80" placeholder="Ex: Maria">
                </div>
                <div>
                    <label for="fbRating" class="block text-sm font-semibold text-slate-700 mb-1">Nota</label>
                    <select id="fbRating" name="rating" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                        <option value="5">5 - Excelente</option>
                        <option value="4">4 - Muito bom</option>
                        <option value="3">3 - Bom</option>
                        <option value="2">2 - Regular</option>
                        <option value="1">1 - Ruim</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="fbComment" class="block text-sm font-semibold text-slate-700 mb-1">Comentário</label>
                    <textarea id="fbComment" name="comment" rows="3" maxlength="500" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="Conte como foi o atendimento (mínimo 10 caracteres)." required></textarea>
                </div>
                <div class="md:col-span-2">
                    <label for="feedbackImage" class="block text-sm font-semibold text-slate-700 mb-1">Imagem do serviço (opcional, máx. 15MB)</label>
                    <input id="feedbackImage" name="feedback_image" type="file" accept="image/png,image/jpeg,image/webp" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition">Enviar avaliação</button>
                </div>
            </form>
            <?php else: ?>
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 text-amber-700 text-sm px-3 py-2">
                    Este perfil ainda não pode receber avaliações (public_id ausente).
                </div>
            <?php endif; ?>

            <div class="space-y-3">
                <?php if (!empty($feedbacks)): ?>
                    <?php foreach ($feedbacks as $fb): ?>
                        <?php
                            $fbName = trim((string) ($fb['client_name'] ?? ''));
                            $fbRating = max(1, min(5, (int) ($fb['rating'] ?? 0)));
                            $fbComment = (string) ($fb['comment'] ?? '');
                            $fbDate = trim((string) ($fb['created_at'] ?? ''));
                            $fbImage = trim((string) ($fb['image_path'] ?? ''));
                        ?>
                        <article class="border border-slate-200 rounded-xl p-3">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <p class="text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($fbName !== '' ? $fbName : 'Cliente', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-sm text-amber-500"><?php echo str_repeat('★', $fbRating) . str_repeat('☆', 5 - $fbRating); ?></p>
                            </div>
                            <p class="text-sm text-slate-600"><?php echo nl2br(htmlspecialchars($fbComment, ENT_QUOTES, 'UTF-8')); ?></p>
                            <?php if ($fbImage !== ''): ?>
                                <img src="<?php echo htmlspecialchars($fbImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Imagem enviada no feedback" class="js-open-lightbox feedback-zoom mt-3 rounded-lg border border-slate-200 max-h-72 w-auto max-w-full" data-image-src="<?php echo htmlspecialchars($fbImage, ENT_QUOTES, 'UTF-8'); ?>" data-image-alt="Imagem enviada no feedback">
                            <?php endif; ?>
                            <?php if ($fbDate !== ''): ?>
                                <?php $fbTs = strtotime($fbDate); ?>
                                <?php if ($fbTs !== false): ?>
                                    <p class="text-xs text-slate-400 mt-2"><?php echo htmlspecialchars(date('d/m/Y H:i', $fbTs), ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="rounded-lg border border-dashed border-slate-300 py-8 px-4 text-center text-sm text-slate-400">
                        Ainda não há avaliações para este profissional.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <div id="image-lightbox" class="lightbox" aria-hidden="true">
        <div class="lightbox-dialog" role="dialog" aria-modal="true" aria-labelledby="lightbox-title">
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-700">
                <p id="lightbox-title" class="text-sm font-semibold text-slate-200">Visualização da imagem</p>
                <button type="button" id="lightbox-close" class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/5 text-slate-200 hover:bg-white/10" aria-label="Fechar imagem">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="lightbox-frame">
                <img id="lightbox-image" src="" alt="">
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function showPhone(phone) {
            document.getElementById('txt-phone').innerText = phone;
            document.getElementById('btn-phone').innerText = 'Telefone Exibido';
            document.getElementById('btn-phone').disabled = true;
            document.getElementById('btn-phone').classList.add('opacity-50');
        }

        function shareProfile() {
            if (navigator.share) {
                navigator.share({ title: 'Perfil Profissional', url: window.location.href });
            } else {
                const dummy = document.createElement('input');
                document.body.appendChild(dummy);
                dummy.value = window.location.href;
                dummy.select();
                document.execCommand('copy');
                document.body.removeChild(dummy);
                showToast('Link copiado para a area de transferencia!');
            }
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            t.innerText = msg;
            t.style.display = 'block';
            setTimeout(() => t.style.display = 'none', 3000);
        }

        const lightbox = document.getElementById('image-lightbox');
        const lightboxImage = document.getElementById('lightbox-image');

        function openLightbox(src, altText) {
            if (!lightbox || !lightboxImage || !src) return;
            lightboxImage.src = src;
            lightboxImage.alt = altText || 'Imagem ampliada';
            lightbox.classList.add('is-open');
            lightbox.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
        }

        function closeLightbox() {
            if (!lightbox || !lightboxImage) return;
            lightbox.classList.remove('is-open');
            lightbox.setAttribute('aria-hidden', 'true');
            lightboxImage.src = '';
            lightboxImage.alt = '';
            document.body.classList.remove('overflow-hidden');
        }

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('.js-open-lightbox');
            if (trigger) {
                openLightbox(trigger.dataset.imageSrc, trigger.dataset.imageAlt);
                return;
            }

            if (event.target === lightbox || event.target.closest('#lightbox-close')) {
                closeLightbox();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeLightbox();
            }
        });

        const worksToggle = document.getElementById('toggle-works');
        const worksGallery = document.getElementById('work-gallery');
        if (worksToggle && worksGallery) {
            const worksItems = Array.from(worksGallery.querySelectorAll('.js-work-photo'));
            const worksLabel = document.getElementById('toggle-works-label');
            const worksCount = document.getElementById('toggle-works-count');
            const worksIcon = worksToggle.querySelector('.js-works-toggle-icon');
            const initialVisible = 2;

            const collapseWorks = () => {
                worksItems.forEach((item, idx) => {
                    if (idx >= initialVisible) item.classList.add('hidden');
                });
                worksToggle.setAttribute('aria-expanded', 'false');
                if (worksLabel) worksLabel.textContent = 'Mostrar mais trabalhos';
                if (worksCount) worksCount.classList.remove('hidden');
                if (worksIcon) worksIcon.classList.remove('rotate-180');
            };

            const expandWorks = () => {
                worksItems.forEach((item) => item.classList.remove('hidden'));
                worksToggle.setAttribute('aria-expanded', 'true');
                if (worksLabel) worksLabel.textContent = 'Mostrar menos';
                if (worksCount) worksCount.classList.add('hidden');
                if (worksIcon) worksIcon.classList.add('rotate-180');
            };

            let isExpanded = worksToggle.getAttribute('aria-expanded') === 'true';
            collapseWorks();

            worksToggle.addEventListener('click', () => {
                isExpanded = !isExpanded;
                if (isExpanded) {
                    expandWorks();
                } else {
                    collapseWorks();
                }
            });
        }

        const feedbackForm = document.getElementById('feedbackForm');
        if (feedbackForm) {
            feedbackForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const clientName = (document.getElementById('fbName')?.value || '').trim();
                const rating = Number(document.getElementById('fbRating')?.value || 0);
                const comment = (document.getElementById('fbComment')?.value || '').trim();
                const feedbackImage = document.getElementById('feedbackImage')?.files?.[0] || null;

                if (comment.length < 10) {
                    showToast('Comentário deve ter no mínimo 10 caracteres.');
                    return;
                }
                if (feedbackImage && feedbackImage.size > 15 * 1024 * 1024) {
                    showToast('A imagem do feedback deve ter no máximo 15MB.');
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('public_id', '<?php echo htmlspecialchars($publicProfileId, ENT_QUOTES, 'UTF-8'); ?>');
                    formData.append('rating', String(rating));
                    formData.append('comment', comment);
                    formData.append('client_name', clientName);
                    formData.append('csrf_token', '<?php echo htmlspecialchars($feedbackCsrf, ENT_QUOTES, 'UTF-8'); ?>');
                    if (feedbackImage) {
                        formData.append('feedback_image', feedbackImage);
                    }
                    const response = await fetch('<?php echo htmlspecialchars(appPath('/access/submit_feedback.php'), ENT_QUOTES, 'UTF-8'); ?>', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        showToast(data.message || 'Não foi possível enviar a avaliação.');
                        return;
                    }
                    showToast('Avaliação enviada com sucesso!');
                    setTimeout(() => window.location.reload(), 800);
                } catch (e) {
                    showToast('Erro ao enviar avaliação.');
                }
            });
        }
    </script>
</body>
</html>


