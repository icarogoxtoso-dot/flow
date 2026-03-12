<?php
/**
 * CONFIGURACAO DO BANCO DE DADOS
 * Ajuste os valores conforme seu ambiente.
 */
session_start();
require_once __DIR__ . '/../secure/config.php';
if (empty($_SESSION['feedback_csrf']) || !is_string($_SESSION['feedback_csrf'])) {
    $_SESSION['feedback_csrf'] = bin2hex(random_bytes(32));
}

$cfg = appConfig();
$host = $cfg['db_host'];
$db = $cfg['db_name'];
$user = $cfg['db_user'];
$pass = $cfg['db_pass'];
$charset = $cfg['db_charset'];
$schemaFile = __DIR__ . '/../scripts/migrations/001_init.sql';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

/**
 * Carrega comandos SQL validos do arquivo de schema.
 */
function loadSchemaStatements(string $filePath): array {
    if (!is_file($filePath)) {
        return [];
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $allowedStarts = [
        'CREATE TABLE',
        'INSERT INTO',
        'ALTER TABLE',
        'DROP TABLE'
    ];

    $statements = [];
    $buffer = '';

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--') || strtoupper($trim) === 'SQL PARA CRIAR O BANCO E A TABELA:') {
            continue;
        }

        $upper = strtoupper($trim);

        if ($buffer === '') {
            $isSqlStart = false;
            foreach ($allowedStarts as $prefix) {
                if (str_starts_with($upper, $prefix)) {
                    $isSqlStart = true;
                    break;
                }
            }

            if (!$isSqlStart) {
                continue;
            }
        }

        $buffer .= ($buffer === '' ? '' : "\n") . $trim;

        if (str_ends_with($trim, ';')) {
            $statements[] = $buffer;
            $buffer = '';
        }
    }

    if ($buffer !== '') {
        $statements[] = $buffer . ';';
    }

    return $statements;
}

function ensureProfessionalTableColumns(PDO $pdo): void {
    $requiredColumns = [
        'nome' => "VARCHAR(100) NULL",
        'online' => "TINYINT(1) DEFAULT 1",
        'bairro' => "VARCHAR(80) NULL",
        'cidade' => "VARCHAR(80) NULL",
        'tags' => "VARCHAR(255) NULL",
        'descricao' => "TEXT NULL",
        'fotos_trabalhos' => "TEXT NULL",
        'desde' => "YEAR NULL",
        'nota' => "DECIMAL(2,1) NOT NULL DEFAULT 0.0",
        'whatsapp' => "VARCHAR(20) NULL",
        'instagram' => "VARCHAR(120) NULL",
        'site_url' => "VARCHAR(255) NULL",
        'facebook' => "VARCHAR(255) NULL",
        'youtube' => "VARCHAR(255) NULL",
        'foto_perfil' => "VARCHAR(255) NULL",
        'public_id' => "CHAR(32) NULL",
        'total_avaliacoes' => "INT DEFAULT 0",
    ];

    foreach ($requiredColumns as $column => $definition) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM profissionais LIKE " . $pdo->quote($column));
        $columnExists = $columnStmt !== false && $columnStmt->fetch();
        if (!$columnExists) {
            $pdo->exec("ALTER TABLE profissionais ADD COLUMN `$column` $definition");
        }
    }

    // Garante que novos perfis comecem sem nota inicial artificial.
    $pdo->exec("ALTER TABLE profissionais MODIFY COLUMN nota DECIMAL(2,1) NOT NULL DEFAULT 0.0");
}

function syncProfessionalRatings(PDO $pdo): void {
    // Sincroniza nota e total de avaliações para todos os perfis.
    $pdo->exec(
        "UPDATE profissionais p
         LEFT JOIN (
            SELECT profissional_id, ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS total_feedbacks
            FROM feedbacks
            GROUP BY profissional_id
         ) f ON f.profissional_id = p.id
         SET p.nota = COALESCE(f.avg_rating, 0),
             p.total_avaliacoes = COALESCE(f.total_feedbacks, 0)"
    );
}

function ensureProfessionalPublicIds(PDO $pdo): void {
    $indexStmt = $pdo->query("SHOW INDEX FROM profissionais WHERE Key_name = 'ux_profissionais_public_id'");
    $hasIndex = $indexStmt !== false && $indexStmt->fetch();
    if (!$hasIndex) {
        $pdo->exec("ALTER TABLE profissionais ADD UNIQUE KEY `ux_profissionais_public_id` (`public_id`)");
    }

    $missingStmt = $pdo->query("SELECT id FROM profissionais WHERE public_id IS NULL OR public_id = ''");
    if ($missingStmt === false) {
        return;
    }
    $missingRows = $missingStmt->fetchAll();
    if (!$missingRows) {
        return;
    }

    $existsStmt = $pdo->prepare("SELECT 1 FROM profissionais WHERE public_id = :public_id LIMIT 1");
    $updateStmt = $pdo->prepare("UPDATE profissionais SET public_id = :public_id WHERE id = :id");

    foreach ($missingRows as $row) {
        do {
            $candidate = bin2hex(random_bytes(16));
            $existsStmt->execute([':public_id' => $candidate]);
            $alreadyExists = (bool) $existsStmt->fetchColumn();
        } while ($alreadyExists);

        $updateStmt->execute([
            ':public_id' => $candidate,
            ':id' => (int) $row['id'],
        ]);
    }
}

function ensureFeedbackTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS feedbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            profissional_id INT NOT NULL,
            client_name VARCHAR(80) NULL,
            rating TINYINT NOT NULL,
            comment TEXT NOT NULL,
            fingerprint CHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_profissional (profissional_id),
            CONSTRAINT fk_feedback_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

try {
    if (!empty($cfg['app_auto_migrate'])) {
        // Modo desenvolvimento: permite bootstrap automatico.
        $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, $options);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db`");

        $tableExistsStmt = $pdo->query("SHOW TABLES LIKE 'profissionais'");
        $tableExists = $tableExistsStmt !== false && $tableExistsStmt->fetchColumn();

        if (!$tableExists) {
            $schemaStatements = loadSchemaStatements($schemaFile);
            foreach ($schemaStatements as $sql) {
                $pdo->exec($sql);
            }
        }

        ensureProfessionalTableColumns($pdo);
        ensureProfessionalPublicIds($pdo);
        ensureFeedbackTable($pdo);
        syncProfessionalRatings($pdo);
    } else {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, $options);
    }

    $countStmt = $pdo->query("SELECT COUNT(*) FROM profissionais");
    $profilesCount = (int) ($countStmt ? $countStmt->fetchColumn() : 0);

    if ($profilesCount === 0) {
        $schemaStatements = loadSchemaStatements($schemaFile);
        foreach ($schemaStatements as $sql) {
            if (str_starts_with(strtoupper(trim($sql)), 'INSERT INTO')) {
                $pdo->exec($sql);
            }
        }
    }

    $stmt = $pdo->query(
        "SELECT p.*,
                COALESCE(f.avg_rating, 0) AS nota_exibicao,
                COALESCE(f.total_feedbacks, 0) AS total_avaliacoes_exibicao
         FROM profissionais p
         LEFT JOIN (
              SELECT profissional_id,
                    ROUND(AVG(rating), 1) AS avg_rating,
                    COUNT(*) AS total_feedbacks
             FROM feedbacks
             GROUP BY profissional_id
         ) f ON f.profissional_id = p.id
         ORDER BY p.id DESC"
    );
    $profissionais_db = $stmt->fetchAll();
} catch (\PDOException $e) {
    $db_error = $e->getMessage();
    $profissionais_db = [];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Busque profissionais de manutenção por serviço e localização no Clube dos Parceiros. Encontre especialistas na sua cidade e bairro.">
    <link rel="canonical" href="https://clubedosparceiros.cloud/access/painel.php">
    <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">

    <meta property="og:site_name" content="Clube dos Parceiros">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Busca de Profissionais - Clube dos Parceiros">
    <meta property="og:description" content="Encontre profissionais de manutenção na sua região.">
    <meta property="og:url" content="https://clubedosparceiros.cloud/access/painel.php">
    <meta property="og:image" content="https://clubedosparceiros.cloud/img/logo.png">
    <meta property="og:image:alt" content="Logo do Clube dos Parceiros">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Busca de Profissionais - Clube dos Parceiros">
    <meta name="twitter:description" content="Encontre profissionais de manutenção na sua região.">
    <meta name="twitter:image" content="https://clubedosparceiros.cloud/img/logo.png">

    <meta name="theme-color" content="#1e3a8a">
    <title>Busca de Profissionais - PHP Edition</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/img/logomenor.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/theme.css">
    <style>
        body { background-color: #f0f4f8; font-family: 'Inter', sans-serif; }
        .card-shadow { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); }
        .bg-navy { background-color: #1e3a8a; }
        .dot-online { height: 8px; width: 8px; background-color: #10b981; border-radius: 50%; display: inline-block; margin-left: 5px; }
        .sidebar-sticky { position: sticky; top: 1.5rem; }
        .result-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 1rem 1.25rem;
            box-shadow: 0 2px 8px rgba(2, 6, 23, .05);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .card-enter {
            animation: fadeUp .28s ease-out both;
        }
        .page-enter {
            animation: fadeUp .24s ease-out both;
        }
        .result-tags { display: flex; flex-wrap: wrap; gap: .4rem; }
        .result-tag {
            background: #f1f5f9;
            color: #475569;
            font-size: .78rem;
            line-height: 1;
            padding: .4rem .55rem;
            border-radius: .5rem;
            font-weight: 600;
        }
        .result-tag.more { background: #e2e8f0; color: #334155; }
        .result-actions { display: grid; gap: .5rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .result-actions a,
        .result-actions button {
            transition: transform .16s ease, box-shadow .16s ease;
        }
        .rating-badge {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border: 1px solid #e8dbad;
            border-radius: .6rem;
            background: #fbf2cf;
            padding: .3rem .55rem;
        }
        .rating-badge-stars {
            display: inline-flex;
            align-items: center;
            gap: .08rem;
            color: #d4a017;
            font-size: .88rem;
            line-height: 1;
        }
        .rating-badge-stars .is-off { color: #cbd5e1; }
        .rating-badge-score {
            color: #7c5a16;
            font-size: .98rem;
            font-weight: 800;
            line-height: 1;
        }
        @media (min-width: 1280px) {
            .result-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); min-width: 260px; }
        }
        .toast {
            position: fixed;
            right: 1rem;
            bottom: 1rem;
            background: #0f172a;
            color: #fff;
            font-size: .9rem;
            padding: .75rem 1rem;
            border-radius: .65rem;
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: all .2s ease;
            z-index: 50;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .top-header {
            background: #ffffff;
            border-bottom: 1px solid #d9e2ec;
        }
        .brand-icon {
            width: 44px;
            height: 44px;
            border-radius: 9px;
            background: #1e3a8a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: .38rem;
        }
        .brand-title {
            color: #1e3a8a;
            font-size: 1.05rem;
            font-weight: 800;
            line-height: 1;
        }
        @media (max-width: 640px) {
            .brand-title { font-size: 0.95rem; }
            .sidebar-sticky { position: static; }
            .result-card { padding: .9rem; border-radius: .85rem; }
            .result-actions { gap: .4rem; }
            .result-actions a,
            .result-actions button { min-height: 44px; }
        }
        @media (hover: hover) {
            .result-card:hover {
                transform: translateY(-2px);
                border-color: #cbd5e1;
                box-shadow: 0 8px 20px rgba(2, 6, 23, .09);
            }
            .result-actions a:hover,
            .result-actions button:hover {
                transform: translateY(-1px);
            }
            .card-shadow:hover {
                box-shadow: 0 10px 24px rgba(15, 23, 42, .08), 0 4px 10px rgba(15, 23, 42, .05);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .card-enter { animation: none; }
            .page-enter { animation: none; }
            .toast { transition: none; }
            .result-card,
            .result-actions a,
            .result-actions button { transition: none; }
        }
    </style>
</head>
<body class="min-h-screen text-slate-900 bg-slate-50 selection:bg-blue-200 selection:text-blue-900">
    <nav class="fixed top-0 w-full z-50 bg-white/80 backdrop-blur-md border-b border-slate-100">
        <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center gap-2">
            <div class="flex items-center gap-2 text-blue-900 font-black text-base sm:text-xl min-w-0">
                <div class="bg-blue-900 text-white p-1.5 rounded-lg">
                    <img src="../img/logomenor.png" alt="Logo Clube dos Parceiros" class="h-10 w-auto rounded-md object-contain">
                </div>
                <span class="truncate">Clube dos Parceiros</span>
            </div>
                    <a href="<?php echo htmlspecialchars(appPath('/index.html'), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 sm:px-4 py-2 rounded-lg font-semibold transition-all duration-300 transform hover:-translate-y-1 shadow-md flex items-center justify-center gap-2 bg-white text-blue-900 border-2 border-blue-900 hover:bg-blue-50 text-xs sm:text-sm whitespace-nowrap">
                Página inicial
            </a>
        </div>
    </nav>
    <div class="pt-28 md:pt-32 px-4 md:px-8 pb-6 md:pb-8">
    
    <?php if(isset($db_error)): ?>
    <div class="max-w-6xl mx-auto mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded" role="alert">
        <p class="font-bold">Aviso de Banco de Dados:</p>
        <p>Não foi possível conectar: <?php echo htmlspecialchars($db_error, ENT_QUOTES, 'UTF-8'); ?>. <br> 
           <strong>Instrução:</strong> Crie um banco chamado <code>servicos_db</code> e uma tabela <code>profissionais</code>. 
           Veja o SQL comentado no código PHP.</p>
    </div>
    <?php endif; ?>

    <div class="page-enter max-w-6xl mx-auto flex flex-col md:flex-row gap-5 md:gap-8">
        <!-- Barra Lateral -->
        <aside class="w-full md:w-80 bg-white p-5 sm:p-6 rounded-2xl card-shadow h-fit sidebar-sticky">
            <h2 class="text-xl font-bold mb-6 text-gray-800">Buscar Profissional</h2>
            <div class="space-y-4">
                <div>
                    <label class="text-sm text-gray-400 block mb-1">O que precisa?</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-3 top-3 text-gray-300"></i>
                        <input type="text" id="filterService" placeholder="ex: elétrica, ar-condicionado..." 
                               class="w-full pl-10 pr-4 py-2 border border-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-100 transition-all">
                    </div>
                </div>
                <div>
                    <label class="text-sm text-gray-400 block mb-1">Local (cidade / bairro)</label>
                    <div class="relative">
                        <i class="fa-solid fa-location-dot absolute left-3 top-3 text-gray-300"></i>
                        <input type="text" id="filterLocation" placeholder="Ex: São Paulo, Pinheiros" 
                               class="w-full pl-10 pr-4 py-2 border border-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-100 transition-all">
                    </div>
                </div>
                <div>
                    <label class="text-sm text-gray-400 block mb-1">Ordenar por</label>
                    <select id="sortOrder" class="w-full px-3 py-2 border border-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-100 transition-all bg-white text-slate-700">
                        <option value="best_rating">Melhor avaliação</option>
                        <option value="most_reviews">Mais avaliações</option>
                        <option value="newest">Mais recente</option>
                        <option value="name_asc">Nome (A-Z)</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400 block mb-2">Categorias populares</label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" data-cat="Eletrica" class="category-chip px-3 py-1.5 rounded-full border border-slate-200 bg-white text-slate-600 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 transition">Eletrica</button>
                        <button type="button" data-cat="Encanamento" class="category-chip px-3 py-1.5 rounded-full border border-slate-200 bg-white text-slate-600 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 transition">Encanamento</button>
                        <button type="button" data-cat="Ar-condicionado" class="category-chip px-3 py-1.5 rounded-full border border-slate-200 bg-white text-slate-600 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 transition">Ar-condicionado</button>
                        <button type="button" data-cat="Eletrica residencial" class="category-chip px-3 py-1.5 rounded-full border border-slate-200 bg-white text-slate-600 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 transition">Residencial</button>
                    </div>
                </div>
                <button id="btnSearch" class="w-full bg-navy text-white font-bold py-3 rounded-xl hover:opacity-90 transition-opacity mt-4 shadow-lg shadow-blue-900/20">Buscar</button>
                <button id="btnClear" class="w-full bg-white text-gray-400 border border-gray-200 font-bold py-3 rounded-xl hover:bg-gray-50 transition-colors">Limpar</button>
            </div>
        </aside>

        <!-- Lista de Resultados -->
        <main class="flex-1 min-w-0">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 mb-6">
                <h1 class="text-xl font-bold text-gray-700">Profissionais encontrados</h1>
                <span id="resultsCount" class="text-sm text-gray-500">Carregando...</span>
            </div>
            <div id="activeFilters" class="flex flex-wrap gap-2 mb-4"></div>
            <div id="professionalsList" class="space-y-4"></div>
        </main>
    </div>
    <div id="appToast" class="toast"></div>

    <script>
        // Passando os dados do PHP para o JavaScript
        const professionals = <?php echo json_encode($profissionais_db); ?>;

        const listContainer = document.getElementById('professionalsList');
        const filterService = document.getElementById('filterService');
        const filterLocation = document.getElementById('filterLocation');
        const sortOrder = document.getElementById('sortOrder');
        const categoryButtons = Array.from(document.querySelectorAll('.category-chip'));
        const activeFilters = document.getElementById('activeFilters');
        const resultsCount = document.getElementById('resultsCount');
        const btnSearch = document.getElementById('btnSearch');
        const btnClear = document.getElementById('btnClear');
        const appToast = document.getElementById('appToast');
        const urlParams = new URLSearchParams(window.location.search);
        const initialService = (urlParams.get('service') || urlParams.get('q') || '').trim();
        const initialLocation = (urlParams.get('location') || '').trim();
        const initialSort = (urlParams.get('sort') || 'best_rating').trim();
        let toastTimer = null;

        function showToast(message) {
            appToast.textContent = message;
            appToast.classList.add('show');
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => appToast.classList.remove('show'), 1800);
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function buildRatingStarsMarkup(rating) {
            const safeRating = Number.isFinite(rating) ? Math.max(0, Math.min(5, rating)) : 0;
            const fullStars = Math.floor(safeRating);
            let stars = '';
            for (let i = 1; i <= 5; i++) {
                stars += `<span class="${i <= fullStars ? 'is-on' : 'is-off'}">★</span>`;
            }
            return stars;
        }

        function renderCards(data) {
            listContainer.innerHTML = '';
            resultsCount.textContent = `Mostrando ${data.length} resultados`;

            if (data.length === 0) {
                listContainer.innerHTML = `<div class="bg-white p-8 sm:p-12 text-center rounded-2xl text-gray-400">Nenhum profissional encontrado.</div>`;
                return;
            }

            data.forEach((p, index) => {
                const tagsArray = typeof p.tags === 'string' ? p.tags.split(',').map(t => t.trim()) : [];
                const visibleTags = tagsArray.filter(Boolean).slice(0, 3);
                const hiddenTagsCount = Math.max(tagsArray.filter(Boolean).length - visibleTags.length, 0);
                const displayNameRaw = p.nome || p.nome_completo || 'Profissional';
                const displayName = escapeHtml(displayNameRaw);
                const initials = ((displayNameRaw || '').split(' ').map(part => part[0] || '').join('').slice(0, 2) || 'PF').toUpperCase();
                const photoUrl = (typeof p.foto_perfil === 'string' && p.foto_perfil.trim() !== '') ? p.foto_perfil : '';
                const location = escapeHtml([p.bairro, p.cidade].filter(Boolean).join(' - ') || 'Local não informado');
                const startYear = p.desde || '-';
                const rating = Number(p.nota_exibicao || p.nota || 0);
                const ratingLabel = rating > 0 ? rating.toFixed(1).replace('.', ',') : '0,0';
                const feedbackCount = Number(p.total_avaliacoes_exibicao || 0);
                const safePhone = String(p.whatsapp || '').replace(/\D/g, '');
                const safePublicId = String(p.public_id || '');

                const card = document.createElement('div');
                card.className = 'result-card card-enter';
                card.style.animationDelay = `${Math.min(index * 40, 220)}ms`;
                card.innerHTML = `
                    <div class="grid grid-cols-1 xl:grid-cols-[1fr_auto] gap-4 items-end">
                        <div class="flex items-start gap-4 min-w-0">
                        <div class="w-20 h-20 rounded-full border border-gray-200 bg-gray-100 overflow-hidden flex items-center justify-center text-gray-400 font-bold text-lg shrink-0">
                            ${photoUrl ? `<img src="${photoUrl}" alt="Foto de ${displayName}" class="w-full h-full object-cover">` : initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1 mb-1 min-w-0">
                                <h3 class="text-xl font-bold text-gray-800 truncate">${displayName}</h3>
                                ${p.online == 1 ? '<span class="dot-online"></span>' : ''}
                            </div>
                            <p class="text-sm text-gray-500 flex items-center gap-1 mb-3">
                                <i class="fa-solid fa-location-dot"></i> ${location}
                            </p>
                            <div class="rating-badge mb-2" aria-label="Nota do profissional">
                                <span class="rating-badge-stars" aria-hidden="true">${buildRatingStarsMarkup(rating)}</span>
                                <span class="rating-badge-score">${ratingLabel}</span>
                            </div>
                            <p class="text-xs text-slate-500 mb-2">${feedbackCount} feedback${feedbackCount === 1 ? '' : 's'}</p>
                            <div class="result-tags mb-3">
                                ${visibleTags.map(tag => `<span class="result-tag">${escapeHtml(tag)}</span>`).join('')}
                                ${hiddenTagsCount > 0 ? `<span class="result-tag more">+${hiddenTagsCount}</span>` : ''}
                            </div>
                            <p class="text-xs text-gray-400">Atua desde ${startYear}</p>
                        </div>
                    </div>
                    <div class="result-actions w-full xl:w-auto">
                        <a href="<?php echo htmlspecialchars(appPath('/access/perfil.php'), ENT_QUOTES, 'UTF-8'); ?>?p=${encodeURIComponent(safePublicId)}" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-xl text-sm font-semibold text-center hover:bg-gray-50">Ver profissional</a>
                        <button onclick='contatar(${JSON.stringify(safePhone)}, ${JSON.stringify(displayNameRaw)})' class="bg-navy text-white px-5 py-2 rounded-xl text-sm font-semibold">Contatar</button>
                    </div>
                    </div>
                `;
                listContainer.appendChild(card);
            });
        }

        function filterData() {
            const serviceRaw = filterService.value.trim();
            const locationRaw = filterLocation.value.trim();
            const serviceQuery = serviceRaw.toLowerCase();
            const locationQuery = locationRaw.toLowerCase();
            const filtered = professionals.filter(p => {
                const tags = (p.tags || '').toLowerCase();
                const nome = (p.nome || '').toLowerCase();
                const bairro = (p.bairro || '').toLowerCase();
                const cidade = (p.cidade || '').toLowerCase();
                const matchService = tags.includes(serviceQuery) || nome.includes(serviceQuery);
                const matchLocation = bairro.includes(locationQuery) || cidade.includes(locationQuery);
                return matchService && matchLocation;
            });

            const sorted = [...filtered];
            switch (sortOrder.value) {
                case 'most_reviews':
                    sorted.sort((a, b) => Number(b.total_avaliacoes_exibicao || 0) - Number(a.total_avaliacoes_exibicao || 0));
                    break;
                case 'newest':
                    sorted.sort((a, b) => Number(b.id || 0) - Number(a.id || 0));
                    break;
                case 'name_asc':
                    sorted.sort((a, b) => String(a.nome || '').localeCompare(String(b.nome || ''), 'pt-BR'));
                    break;
                case 'best_rating':
                default:
                    sorted.sort((a, b) => Number(b.nota_exibicao || b.nota || 0) - Number(a.nota_exibicao || a.nota || 0));
                    break;
            }
            renderActiveFilters(serviceRaw, locationRaw);
            renderCards(sorted);
        }

        function renderActiveFilters(service, location) {
            if (!activeFilters) return;
            const chips = [];
            if (service !== '') {
                chips.push(`<button type="button" data-remove=\"service\" class=\"px-2.5 py-1 rounded-full bg-blue-50 border border-blue-200 text-blue-700 text-xs font-semibold\">${escapeHtml(service)} ×</button>`);
            }
            if (location !== '') {
                chips.push(`<button type="button" data-remove=\"location\" class=\"px-2.5 py-1 rounded-full bg-blue-50 border border-blue-200 text-blue-700 text-xs font-semibold\">${escapeHtml(location)} ×</button>`);
            }
            activeFilters.innerHTML = chips.join('');
            Array.from(activeFilters.querySelectorAll('button[data-remove]')).forEach((btn) => {
                btn.addEventListener('click', () => {
                    const target = btn.getAttribute('data-remove');
                    if (target === 'service') filterService.value = '';
                    if (target === 'location') filterLocation.value = '';
                    filterData();
                });
            });
        }

        function contatar(phone, name) {
            if (!phone || String(phone).replace(/\D/g, '').length < 10) {
                showToast('Telefone não informado para este profissional.');
                return;
            }
            const message = encodeURIComponent(`Olá ${name}, vi seu perfil no buscador de profissionais e gostaria de solicitar um orçamento.`);
            window.open(`https://wa.me/${phone}?text=${message}`, '_blank');
        }

        async function compartilharPerfil(url) {
            try {
                if (navigator.share) {
                    await navigator.share({ title: 'Perfil profissional', url });
                    return;
                }
                await navigator.clipboard.writeText(url);
                showToast('Link do perfil copiado.');
            } catch (error) {
                console.error('Erro ao compartilhar perfil:', error);
                showToast('Não foi possível compartilhar este perfil.');
            }
        }

        filterService.addEventListener('input', filterData);
        filterLocation.addEventListener('input', filterData);
        sortOrder.addEventListener('change', filterData);
        categoryButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                filterService.value = btn.getAttribute('data-cat') || '';
                filterData();
            });
        });
        btnSearch.addEventListener('click', filterData);
        btnClear.addEventListener('click', () => {
            filterService.value = '';
            filterLocation.value = '';
            sortOrder.value = 'best_rating';
            filterData();
        });

        if (['best_rating', 'most_reviews', 'newest', 'name_asc'].includes(initialSort)) {
            sortOrder.value = initialSort;
        }
        if (initialService !== '' || initialLocation !== '') {
            filterService.value = initialService;
            filterLocation.value = initialLocation;
            filterData();
        } else {
            filterData();
        }
    </script>
    </div>
</body>
</html>

<!-- 
SQL PARA CRIAR O BANCO E A TABELA:

CREATE DATABASE servicos_db;
USE servicos_db;

CREATE TABLE profissionais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    online TINYINT(1) DEFAULT 1,
    bairro VARCHAR(50),
    cidade VARCHAR(50),
    tags VARCHAR(255), -- Exemplo: "Elétrica, Ar-condicionado"
    desde YEAR,
    nota DECIMAL(2,1) NOT NULL DEFAULT 0.0,
    whatsapp VARCHAR(20)
);

INSERT INTO profissionais (nome, online, bairro, cidade, tags, desde, nota, whatsapp) VALUES
('Rogério Silva', 1, 'Pinheiros', 'São Paulo', 'Elétrica, Ar-condicionado', 2019, 0.0, '5511999999999'),
('Mariana Costa', 0, 'Vila Madalena', 'São Paulo', 'Hidráulica, Gás', 2018, 0.0, '5511988888888'),
('Carlos Alberto', 1, 'Centro', 'Osasco', 'Refrigeração, Ar-condicionado', 2020, 0.0, '5511977777777');
-->

