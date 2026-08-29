<?php
/**
 * TelePulse - Standalone Telegram Uptime Monitor & Member Alert Bot (cPanel Ready)
 */

require_once __DIR__ . '/functions.php';

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [
    'bot_token' => 'YOUR_BOT_TOKEN_HERE',
    'chat_id' => 'YOUR_CHAT_ID_HERE',
    'data_file' => __DIR__ . '/state.json',
    'log_file' => __DIR__ . '/telepulse.log',
    'monitors' => []
];

// AJAX API Routing
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];

    if ($action === 'state') {
        echo json_encode(load_state($config));
        exit;
    }

    if ($action === 'ping') {
        $res = run_monitoring_cycle($config);
        echo json_encode(['status' => 'success', 'data' => $res]);
        exit;
    }

    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;

        $name = $input['name'] ?? '';
        $type = $input['type'] ?? 'telegram_channel';
        $target = $input['target'] ?? '';
        $interval = intval($input['interval'] ?? 60);
        $timeout = intval($input['timeout'] ?? 8);

        if (empty($name) || empty($target)) {
            echo json_encode(['status' => 'error', 'message' => 'Name and target are required.']);
            exit;
        }

        $mon = add_monitor($config, $name, $type, $target, $interval, $timeout);
        echo json_encode(['status' => 'success', 'monitor' => $mon]);
        exit;
    }

    if ($action === 'delete' && ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'DELETE')) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_REQUEST;
        $id = $input['id'] ?? '';

        if (empty($id)) {
            echo json_encode(['status' => 'error', 'message' => 'Monitor ID required.']);
            exit;
        }

        $deleted = delete_monitor($config, $id);
        echo json_encode(['status' => $deleted ? 'success' : 'error', 'message' => $deleted ? 'Monitor deleted' : 'Monitor not found']);
        exit;
    }

    if ($action === 'probe') {
        $target = $_GET['target'] ?? '';
        $type = $_GET['type'] ?? 'telegram_channel';
        if (empty($target)) {
            echo json_encode(['status' => 'error', 'message' => 'Target required.']);
            exit;
        }
        $res = probe_target($target, $type, $config['bot_token'] ?? '');
        echo json_encode(['status' => 'success', 'data' => $res]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid API action']);
    exit;
}

// 1. Check if invoked via CLI (cPanel Cron Job) or ?cron=1
if (php_sapi_name() === 'cli' || isset($_GET['cron']) || (isset($argv) && in_array('--cron', $argv))) {
    header('Content-Type: application/json');
    $result = run_monitoring_cycle($config);
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// 2. Check if invoked via Telegram Bot Webhook (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['api'])) {
    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);
    if ($update) {
        handle_webhook_update($update, $config);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// 3. Render Modern Dashboard
$state = load_state($config);
$monitors = $state['monitors'] ?? [];
$problems = $state['problems'] ?? [];
$member_events = $state['member_events'] ?? [];

$total_monitors = count($monitors);
$up_count = 0;
$latencies = [];

foreach ($monitors as $m) {
    if (($m['status'] ?? 'UNKNOWN') === 'UP') {
        $up_count++;
    }
    if (isset($m['last_response_time']) && $m['last_response_time'] > 0) {
        $latencies[] = $m['last_response_time'];
    }
}

$all_up = ($total_monitors > 0 && $up_count === $total_monitors);
$overall_uptime = $total_monitors > 0 ? round(($up_count / $total_monitors) * 100, 1) : 100.0;
$avg_latency = !empty($latencies) ? round(array_sum($latencies) / count($latencies)) : 0;
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TelePulse - Telegram Channel & Bot Monitoring Suite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #080c14;
            color: #f3f4f6;
        }
        .glass-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card-hover:hover {
            border-color: rgba(56, 189, 248, 0.3);
            box-shadow: 0 10px 30px -10px rgba(14, 165, 233, 0.15);
        }
    </style>
</head>
<body class="min-h-screen pb-12 antialiased selection:bg-sky-500 selection:text-white">

    <!-- Top Navigation Bar -->
    <nav class="border-b border-gray-800/80 bg-gray-950/80 backdrop-blur-md sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-sky-500 to-blue-600 flex items-center justify-center text-white shadow-lg shadow-sky-500/20 text-lg font-black">
                    <i class="fa-solid fa-satellite-dish"></i>
                </div>
                <div>
                    <span class="text-lg font-extrabold tracking-tight text-white flex items-center gap-2">
                        TelePulse <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20">cPanel Edition v3.0</span>
                    </span>
                    <p class="text-[11px] text-gray-400 hidden sm:block">Telegram 24/7 Channel, Bot & Webhook Uptime Monitoring Engine</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="triggerPing()" id="ping-btn" class="px-3.5 py-2 rounded-xl bg-sky-500/10 hover:bg-sky-500/20 border border-sky-500/30 text-sky-400 font-bold text-xs transition-all flex items-center gap-2">
                    <i class="fa-solid fa-bolt text-sky-400"></i>
                    <span class="hidden sm:inline">Run Instant Probe</span>
                </button>
                <button onclick="openAddModal()" class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-400 hover:to-blue-500 text-white font-bold text-xs shadow-lg shadow-sky-500/20 transition-all flex items-center gap-1.5">
                    <i class="fa-solid fa-plus"></i> Add Monitor
                </button>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 space-y-8">

        <!-- System Status Header Banner -->
        <div class="p-6 rounded-2xl glass-card flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
            <div class="absolute -right-12 -bottom-12 w-48 h-48 rounded-full <?php echo $all_up ? 'bg-emerald-500/10' : 'bg-rose-500/10'; ?> blur-3xl pointer-events-none"></div>
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl <?php echo $all_up ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'; ?> flex items-center justify-center text-2xl flex-shrink-0">
                    <i class="fa-solid <?php echo $all_up ? 'fa-shield-halved' : 'fa-triangle-exclamation'; ?>"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-white flex items-center gap-2">
                        <?php echo $all_up ? 'All Monitored Services Operational' : 'Degraded Service Performance Detected!'; ?>
                    </h2>
                    <p class="text-xs text-gray-400 mt-0.5">
                        Automated cURL probes checking targets every 60s &bull; Last Cycle: <span class="text-gray-200 font-mono" id="last-run-time"><?php echo date('Y-m-d H:i:s T', $state['last_run'] ?? time()); ?></span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 w-full md:w-auto">
                <a href="index.php?cron=1" target="_blank" class="w-full md:w-auto text-center px-4 py-2 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 text-xs font-semibold text-gray-300 transition-colors">
                    <i class="fa-solid fa-clock-rotate-left mr-1.5"></i> Run Cron CLI
                </a>
            </div>
        </div>

        <!-- Key Metrics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="p-5 rounded-2xl glass-card space-y-2">
                <div class="flex items-center justify-between text-gray-400 text-xs font-medium">
                    <span>Overall Health</span>
                    <i class="fa-solid fa-heart-pulse text-emerald-400"></i>
                </div>
                <div class="text-2xl font-black text-white flex items-baseline gap-2">
                    <span id="stat-health-pct"><?php echo $overall_uptime; ?>%</span>
                    <span class="text-xs text-emerald-400 font-semibold"><?php echo $up_count; ?>/<?php echo $total_monitors; ?> Online</span>
                </div>
                <div class="w-full bg-gray-800 rounded-full h-1.5 overflow-hidden">
                    <div class="bg-emerald-400 h-1.5 rounded-full" style="width: <?php echo $overall_uptime; ?>%"></div>
                </div>
            </div>

            <div class="p-5 rounded-2xl glass-card space-y-2">
                <div class="flex items-center justify-between text-gray-400 text-xs font-medium">
                    <span>Active Monitors</span>
                    <i class="fa-solid fa-list-check text-sky-400"></i>
                </div>
                <div class="text-2xl font-black text-white" id="stat-total-monitors">
                    <?php echo $total_monitors; ?>
                </div>
                <p class="text-[11px] text-gray-400">Channels, Bots, Webhooks, Mini Apps</p>
            </div>

            <div class="p-5 rounded-2xl glass-card space-y-2">
                <div class="flex items-center justify-between text-gray-400 text-xs font-medium">
                    <span>Avg Response Time</span>
                    <i class="fa-solid fa-gauge-high text-amber-400"></i>
                </div>
                <div class="text-2xl font-black text-white" id="stat-avg-latency">
                    <?php echo $avg_latency; ?> <span class="text-xs text-gray-400 font-normal">ms</span>
                </div>
                <p class="text-[11px] text-gray-400">Direct cURL Latency Probe</p>
            </div>

            <div class="p-5 rounded-2xl glass-card space-y-2">
                <div class="flex items-center justify-between text-gray-400 text-xs font-medium">
                    <span>Recorded Incidents</span>
                    <i class="fa-solid fa-bug text-rose-400"></i>
                </div>
                <div class="text-2xl font-black text-white" id="stat-total-problems">
                    <?php echo count($problems); ?>
                </div>
                <p class="text-[11px] text-gray-400">Errors & Downtime Logged</p>
            </div>
        </div>

        <!-- Dashboard Navigation Tabs -->
        <div class="flex items-center gap-2 border-b border-gray-800 pb-2">
            <button onclick="switchTab('monitors')" id="tab-btn-monitors" class="px-4 py-2 rounded-xl bg-sky-500/10 text-sky-400 font-bold text-xs border border-sky-500/30 flex items-center gap-2">
                <i class="fa-solid fa-desktop"></i> Active Targets (<span id="monitors-count-tab"><?php echo $total_monitors; ?></span>)
            </button>
            <button onclick="switchTab('incidents')" id="tab-btn-incidents" class="px-4 py-2 rounded-xl text-gray-400 hover:text-white font-bold text-xs flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation"></i> Incidents Log (<span id="incidents-count-tab"><?php echo count($problems); ?></span>)
            </button>
            <button onclick="switchTab('members')" id="tab-btn-members" class="px-4 py-2 rounded-xl text-gray-400 hover:text-white font-bold text-xs flex items-center gap-2">
                <i class="fa-solid fa-users"></i> Member Tracker (<span id="members-count-tab"><?php echo count($member_events); ?></span>)
            </button>
            <button onclick="switchTab('guide')" id="tab-btn-guide" class="px-4 py-2 rounded-xl text-gray-400 hover:text-white font-bold text-xs flex items-center gap-2">
                <i class="fa-solid fa-book"></i> cPanel Setup
            </button>
        </div>

        <!-- TAB 1: MONITORS GRID -->
        <div id="tab-monitors" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="monitors-grid">
                <?php if (empty($monitors)): ?>
                    <div class="col-span-full p-8 text-center rounded-2xl glass-card text-gray-400 space-y-3">
                        <i class="fa-solid fa-satellite-dish text-3xl text-gray-600"></i>
                        <p>No monitoring targets configured yet.</p>
                        <button onclick="openAddModal()" class="px-4 py-2 rounded-xl bg-sky-500 text-white font-bold text-xs">Add Your First Target</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($monitors as $m): ?>
                        <?php
                            $is_up = ($m['status'] ?? 'UNKNOWN') === 'UP';
                            $type_badge = match($m['type'] ?? '') {
                                'telegram_channel' => 'bg-sky-500/10 text-sky-400 border-sky-500/20',
                                'telegram_bot' => 'bg-purple-500/10 text-purple-400 border-purple-500/20',
                                'telegram_webhook' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                                'telegram_miniapp' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                default => 'bg-gray-500/10 text-gray-400 border-gray-500/20'
                            };
                        ?>
                        <div class="p-5 rounded-2xl glass-card glass-card-hover transition-all flex flex-col justify-between space-y-4 relative">
                            <div class="flex items-start justify-between gap-3">
                                <div class="space-y-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 <?php echo $is_up ? 'bg-emerald-400 shadow-sm shadow-emerald-400' : 'bg-rose-500 animate-pulse'; ?>"></span>
                                        <h3 class="font-bold text-white text-sm truncate"><?php echo htmlspecialchars($m['name']); ?></h3>
                                    </div>
                                    <p class="text-xs text-gray-400 font-mono truncate"><?php echo htmlspecialchars($m['target']); ?></p>
                                </div>
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border uppercase tracking-wider flex-shrink-0 <?php echo $type_badge; ?>">
                                    <?php echo str_replace('telegram_', '', htmlspecialchars($m['type'])); ?>
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-2 p-3 rounded-xl bg-gray-950/50 border border-gray-800/60 text-xs font-mono">
                                <div>
                                    <span class="text-[10px] text-gray-400 uppercase tracking-wider block">Status</span>
                                    <span class="font-bold <?php echo $is_up ? 'text-emerald-400' : 'text-rose-400'; ?>">
                                        <?php echo htmlspecialchars($m['status'] ?? 'UNKNOWN'); ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 uppercase tracking-wider block">Latency</span>
                                    <span class="text-gray-200"><?php echo $m['last_response_time'] ?? 0; ?> ms</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 uppercase tracking-wider block">Uptime</span>
                                    <span class="text-gray-200"><?php echo $m['uptime_pct'] ?? 100; ?>%</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-gray-400 uppercase tracking-wider block">HTTP Code</span>
                                    <span class="text-gray-200"><?php echo $m['last_http_code'] ?? 'N/A'; ?></span>
                                </div>
                            </div>

                            <div class="flex items-center justify-between text-xs text-gray-400 pt-1 border-t border-gray-800/60">
                                <span class="text-[11px]">Last Check: <?php echo $m['last_check'] ? date('H:i:s', strtotime($m['last_check'])) : 'Pending'; ?></span>
                                <div class="flex items-center gap-2">
                                    <button onclick="probeSingle(<?php echo htmlspecialchars(json_encode($m['target'])); ?>, <?php echo htmlspecialchars(json_encode($m['type'])); ?>)" class="text-gray-400 hover:text-sky-400 p-1 transition-colors" title="Instant Probe">
                                        <i class="fa-solid fa-arrows-rotate"></i>
                                    </button>
                                    <button onclick="deleteMonitor(<?php echo htmlspecialchars(json_encode($m['id'])); ?>)" class="text-gray-400 hover:text-rose-400 p-1 transition-colors" title="Delete Monitor">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 2: INCIDENTS LOG -->
        <div id="tab-incidents" class="space-y-4 hidden">
            <div class="rounded-2xl glass-card overflow-hidden">
                <div class="p-4 bg-gray-950/60 border-b border-gray-800 flex items-center justify-between">
                    <h3 class="font-bold text-sm text-white">Recent Errors, HTTP Failures & Downtime Events</h3>
                    <span class="text-xs text-gray-400">Stored in state.json</span>
                </div>
                <div class="divide-y divide-gray-800/60" id="incidents-list">
                    <?php if (empty($problems)): ?>
                        <div class="p-8 text-center text-gray-400">
                            <i class="fa-circle-check text-2xl text-emerald-400 mb-2 block"></i>
                            Zero incidents logged! All monitored targets are fully operational.
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($problems, 0, 15) as $p): ?>
                            <div class="p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 hover:bg-gray-800/30 transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-400 flex items-center justify-center text-sm flex-shrink-0">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </div>
                                    <div>
                                        <div class="font-bold text-sm text-white"><?php echo htmlspecialchars($p['monitor'] ?? 'Target'); ?></div>
                                        <div class="text-xs text-rose-400 font-mono"><?php echo htmlspecialchars($p['error'] ?? 'Unknown Error'); ?></div>
                                    </div>
                                </div>
                                <div class="text-right text-xs font-mono text-gray-400">
                                    <div>HTTP <?php echo $p['http_code'] ?? '0'; ?> &bull; <?php echo $p['latency'] ?? 0; ?> ms</div>
                                    <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars($p['time'] ?? ''); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB 3: MEMBER TRACKER -->
        <div id="tab-members" class="space-y-4 hidden">
            <div class="rounded-2xl glass-card overflow-hidden">
                <div class="p-4 bg-gray-950/60 border-b border-gray-800 flex items-center justify-between">
                    <h3 class="font-bold text-sm text-white">Live User Join & Leave Events Registry</h3>
                    <span class="text-xs text-gray-400">Telegram Webhook Events</span>
                </div>
                <div class="divide-y divide-gray-800/60" id="members-list">
                    <?php if (empty($member_events)): ?>
                        <div class="p-8 text-center text-gray-400 space-y-2">
                            <i class="fa-solid fa-users text-2xl text-sky-400 mb-2 block"></i>
                            <p>No member join or leave activity logged yet.</p>
                            <p class="text-xs text-gray-400">Add your Bot as Admin to your Telegram Channel or Group to track live user activity!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($member_events, 0, 15) as $me): ?>
                            <?php $is_join = ($me['type'] ?? '') === 'JOIN'; ?>
                            <div class="p-4 flex items-center justify-between gap-4 hover:bg-gray-800/30 transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg <?php echo $is_join ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400'; ?> flex items-center justify-center text-sm flex-shrink-0">
                                        <i class="fa-solid <?php echo $is_join ? 'fa-user-plus' : 'fa-user-minus'; ?>"></i>
                                    </div>
                                    <div>
                                        <div class="font-bold text-sm text-white"><?php echo htmlspecialchars($me['user'] ?? 'Telegram User'); ?> <span class="text-xs font-normal text-sky-400"><?php echo htmlspecialchars($me['username'] ?? ''); ?></span></div>
                                        <div class="text-xs text-gray-400">Chat/Channel: <strong class="text-gray-300"><?php echo htmlspecialchars($me['chat'] ?? 'Group'); ?></strong></div>
                                    </div>
                                </div>
                                <div class="text-right text-xs font-mono text-gray-400">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $is_join ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400'; ?>">
                                        <?php echo htmlspecialchars($me['type'] ?? 'EVENT'); ?>
                                    </span>
                                    <div class="text-[10px] text-gray-400 mt-1"><?php echo htmlspecialchars($me['time'] ?? ''); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB 4: CPANEL SETUP GUIDE -->
        <div id="tab-guide" class="space-y-6 hidden">
            <div class="p-6 rounded-2xl glass-card space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-server text-sky-400"></i> cPanel Deployment & Webhook Setup Guide
                    </h3>
                    <p class="text-xs text-gray-400 mt-1">Follow these simple steps to host TelePulse on any cPanel Shared/VPS Hosting account.</p>
                </div>

                <div class="space-y-4 text-xs">
                    <div class="p-4 rounded-xl bg-gray-950/80 border border-gray-800 space-y-2">
                        <div class="font-bold text-sky-400 text-sm">Step 1: Upload Files to cPanel</div>
                        <p class="text-gray-300">Upload all files (`index.php`, `config.php`, `functions.php`, `cron.php`, `webhook.php`, `.htaccess`) into `public_html/telepulse/` using cPanel File Manager.</p>
                    </div>

                    <div class="p-4 rounded-xl bg-gray-950/80 border border-gray-800 space-y-2">
                        <div class="font-bold text-sky-400 text-sm">Step 2: Configure cPanel Cron Job (24/7 Monitoring)</div>
                        <p class="text-gray-300">In cPanel -> <strong>Cron Jobs</strong>, create a new job running every 1 or 5 minutes (`* * * * *`):</p>
                        <div class="p-3 rounded-lg bg-gray-900 font-mono text-emerald-400 select-all border border-gray-800">
                            /usr/local/bin/php /home/USERNAME/public_html/telepulse/index.php --cron >/dev/null 2>&1
                        </div>
                        <p class="text-[11px] text-gray-400">*Replace `USERNAME` with your actual cPanel account username.</p>
                    </div>

                    <div class="p-4 rounded-xl bg-gray-950/80 border border-gray-800 space-y-2">
                        <div class="font-bold text-sky-400 text-sm">Step 3: Connect Telegram Webhook</div>
                        <p class="text-gray-300">Open this URL in your web browser to activate live Telegram Bot Webhook:</p>
                        <div class="p-3 rounded-lg bg-gray-900 font-mono text-sky-300 select-all border border-gray-800">
                            https://api.telegram.org/bot<?php echo htmlspecialchars($config['bot_token'] ?? ''); ?>/setWebhook?url=https://YOUR-DOMAIN.com/telepulse/index.php
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- Add Monitor Modal -->
    <div id="add-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="max-w-md w-full p-6 rounded-2xl glass-card border border-gray-800 space-y-5 relative">
            <button onclick="closeAddModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <div>
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-plus-circle text-sky-400"></i> Add New Target Monitor
                </h3>
                <p class="text-xs text-gray-400">Add a Telegram Channel, Bot, Webhook URL, or Mini App</p>
            </div>

            <form id="add-monitor-form" onsubmit="submitAddMonitor(event)" class="space-y-4 text-xs">
                <div>
                    <label class="block text-gray-300 font-semibold mb-1">Monitor Name</label>
                    <input type="text" id="add-name" required placeholder="e.g. News Channel (@telegram)" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-sky-500">
                </div>

                <div>
                    <label class="block text-gray-300 font-semibold mb-1">Monitor Type</label>
                    <select id="add-type" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white focus:outline-none focus:border-sky-500">
                        <option value="telegram_channel">Telegram Channel (@username or link)</option>
                        <option value="telegram_bot">Telegram Bot (@botname)</option>
                        <option value="telegram_webhook">Webhook Endpoint (HTTP / HTTPS URL)</option>
                        <option value="telegram_miniapp">Telegram Mini App Link</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-300 font-semibold mb-1">Target Handle / URL</label>
                    <input type="text" id="add-target" required placeholder="e.g. telegram OR https://api.telegram.org/probe" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-sky-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-gray-300 font-semibold mb-1">Interval (Sec)</label>
                        <input type="number" id="add-interval" value="60" min="10" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white focus:outline-none focus:border-sky-500">
                    </div>
                    <div>
                        <label class="block text-gray-300 font-semibold mb-1">Timeout (Sec)</label>
                        <input type="number" id="add-timeout" value="8" min="2" max="30" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white focus:outline-none focus:border-sky-500">
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeAddModal()" class="px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 font-semibold">Cancel</button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-white font-bold shadow-lg shadow-sky-500/20">Add Monitor</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript Handlers -->
    <script>
        function switchTab(tab) {
            ['monitors', 'incidents', 'members', 'guide'].forEach(t => {
                document.getElementById('tab-' + t).classList.add('hidden');
                const btn = document.getElementById('tab-btn-' + t);
                btn.className = "px-4 py-2 rounded-xl text-gray-400 hover:text-white font-bold text-xs flex items-center gap-2";
            });
            document.getElementById('tab-' + tab).classList.remove('hidden');
            const activeBtn = document.getElementById('tab-btn-' + tab);
            activeBtn.className = "px-4 py-2 rounded-xl bg-sky-500/10 text-sky-400 font-bold text-xs border border-sky-500/30 flex items-center gap-2";
        }

        function openAddModal() {
            document.getElementById('add-modal').classList.remove('hidden');
        }

        function closeAddModal() {
            document.getElementById('add-modal').classList.add('hidden');
        }

        async function submitAddMonitor(e) {
            e.preventDefault();
            const name = document.getElementById('add-name').value;
            const type = document.getElementById('add-type').value;
            const target = document.getElementById('add-target').value;
            const interval = document.getElementById('add-interval').value;
            const timeout = document.getElementById('add-timeout').value;

            try {
                const res = await fetch('index.php?api=add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, type, target, interval, timeout })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeAddModal();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('Failed to add monitor');
            }
        }

        async function deleteMonitor(id) {
            if (!confirm('Are you sure you want to delete this target monitor?')) return;
            try {
                const res = await fetch('index.php?api=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert('Error deleting monitor');
                }
            } catch (err) {
                alert('Failed to delete monitor');
            }
        }

        async function triggerPing() {
            const btn = document.getElementById('ping-btn');
            btn.innerHTML = `<i class="fa-solid fa-spinner animate-spin text-sky-400"></i> Probing...`;
            try {
                const res = await fetch('index.php?api=ping');
                const data = await res.json();
                if (data.status === 'success') {
                    location.reload();
                }
            } catch (err) {
                alert('Ping check failed');
            } font-bold
                btn.innerHTML = `<i class="fa-solid fa-bolt text-sky-400"></i> <span class="hidden sm:inline">Run Instant Probe</span>`;
            }
        }

        async function probeSingle(target, type) {
            alert('Probing ' + target + '...');
            try {
                const res = await fetch(`index.php?api=probe&target=${encodeURIComponent(target)}&type=${encodeURIComponent(type)}`);
                const json = await res.json();
                if (json.status === 'success') {
                    const d = json.data;
                    alert(`Target: ${target}\nStatus: ${d.is_up ? 'ONLINE' : 'OFFLINE'}\nLatency: ${d.latency_ms} ms\nHTTP Code: ${d.http_code}\nInfo: ${d.error}`);
                }
            } catch (e) {
                alert('Probe failed');
            }
        }
    </script>
</body>
</html>
