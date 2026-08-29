<?php
/**
 * TelePulse - Core Helper Functions Module
 */

function log_message($msg, $log_file = __DIR__ . '/telepulse.log') {
    $time = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$time] $msg" . PHP_EOL, FILE_APPEND);
}

function load_state($config) {
    $data_file = isset($config['data_file']) ? $config['data_file'] : __DIR__ . '/state.json';
    $default_monitors = isset($config['monitors']) ? $config['monitors'] : [];

    if (!file_exists($data_file)) {
        $initial = [
            'monitors' => $default_monitors,
            'history' => [],
            'problems' => [],
            'member_events' => [],
            'last_run' => time()
        ];
        foreach ($initial['monitors'] as &$m) {
            if (!isset($m['id'])) $m['id'] = 'mon-' . uniqid();
            $m['status'] = 'UNKNOWN';
            $m['enabled'] = true;
            $m['last_check'] = null;
            $m['last_response_time'] = 0;
            $m['consecutive_fails'] = 0;
            $m['total_checks'] = 0;
            $m['successful_checks'] = 0;
            $m['uptime_pct'] = 100.0;
            $m['history'] = [];
        }
        @file_put_contents($data_file, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $initial;
    }
    $content = @file_get_contents($data_file);
    $data = json_decode($content, true);
    if (!$data || !isset($data['monitors'])) {
        $data = [
            'monitors' => $default_monitors,
            'history' => [],
            'problems' => [],
            'member_events' => [],
            'last_run' => time()
        ];
    }
    if (!isset($data['problems'])) $data['problems'] = [];
    if (!isset($data['member_events'])) $data['member_events'] = [];

    foreach ($data['monitors'] as &$m) {
        if (!isset($m['id'])) $m['id'] = 'mon-' . uniqid();
        if (!isset($m['enabled'])) $m['enabled'] = true;
        if (!isset($m['total_checks'])) $m['total_checks'] = 0;
        if (!isset($m['successful_checks'])) $m['successful_checks'] = 0;
        if (!isset($m['uptime_pct'])) $m['uptime_pct'] = 100.0;
        if (!isset($m['history'])) $m['history'] = [];
    }

    return $data;
}

function save_state($state, $data_file = __DIR__ . '/state.json') {
    @file_put_contents($data_file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function add_monitor($config, $name, $type, $target, $interval = 60, $timeout = 8) {
    $state = load_state($config);
    $new_mon = [
        'id' => 'mon-' . time() . '-' . rand(100, 999),
        'name' => trim($name),
        'type' => trim($type),
        'target' => trim($target),
        'interval' => intval($interval) > 0 ? intval($interval) : 60,
        'timeout' => intval($timeout) > 0 ? intval($timeout) : 8,
        'status' => 'UNKNOWN',
        'enabled' => true,
        'last_check' => null,
        'last_response_time' => 0,
        'consecutive_fails' => 0,
        'total_checks' => 0,
        'successful_checks' => 0,
        'uptime_pct' => 100.0,
        'history' => []
    ];
    $state['monitors'][] = $new_mon;
    save_state($state, isset($config['data_file']) ? $config['data_file'] : __DIR__ . '/state.json');
    return $new_mon;
}

function delete_monitor($config, $id) {
    $state = load_state($config);
    $filtered = [];
    $deleted = false;
    foreach ($state['monitors'] as $m) {
        if ($m['id'] === $id) {
            $deleted = true;
        } else {
            $filtered[] = $m;
        }
    }
    if ($deleted) {
        $state['monitors'] = array_values($filtered);
        save_state($state, isset($config['data_file']) ? $config['data_file'] : __DIR__ . '/state.json');
    }
    return $deleted;
}

function send_telegram_message($text, $config, $chat_id = null, $reply_to = null) {
    $bot_token = isset($config['bot_token']) ? $config['bot_token'] : '';
    $target_chat = $chat_id ? $chat_id : (isset($config['chat_id']) ? $config['chat_id'] : '');
    $log_file = isset($config['log_file']) ? $config['log_file'] : __DIR__ . '/telepulse.log';

    if (empty($bot_token) || strpos($bot_token, '123456789') === 0) {
        log_message("Warning: BOT_TOKEN is not set.", $log_file);
        return false;
    }
    $url = "https://api.telegram.org/bot" . $bot_token . "/sendMessage";
    $payload = [
        'chat_id' => $target_chat,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ];
    if ($reply_to) {
        $payload['reply_to_message_id'] = $reply_to;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $http_code === 200;
}

function probe_target($target, $type = 'telegram_channel', $bot_token = '', $timeout = 8) {
    $clean_target = trim($target);
    $url = $clean_target;

    if ($type === 'telegram_channel' || $type === 'telegram_group') {
        $handle = ltrim(preg_replace('#^https?://t\.me/#i', '', $clean_target), '@');
        $url = "https://t.me/" . $handle;
    } elseif ($type === 'telegram_bot') {
        if (!empty($bot_token) && strpos($bot_token, '123456789') === false) {
            $url = "https://api.telegram.org/bot" . $bot_token . "/getMe";
        } else {
            $handle = ltrim(preg_replace('#^https?://t\.me/#i', '', $clean_target), '@');
            $url = "https://t.me/" . $handle;
        }
    }

    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (TelePulse Monitor Engine/3.0; Telegram Health Check)');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $body = curl_exec($ch);
    $latency = round((microtime(true) - $start) * 1000);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $is_up = ($http_code >= 200 && $http_code < 400);

    if ($is_up && ($type === 'telegram_channel' || $type === 'telegram_group')) {
        if (stripos($body, 'Channel with this username is not found') !== false) {
            $is_up = false;
            $error = "Channel username not found or deleted";
        }
    }

    return [
        'is_up' => $is_up,
        'http_code' => $http_code,
        'latency_ms' => $latency,
        'error' => $error ? $error : ($is_up ? 'OK' : "HTTP Status $http_code"),
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

function run_monitoring_cycle($config) {
    $state = load_state($config);
    $state['last_run'] = time();
    $alerts_sent = 0;
    $monitors_checked = 0;
    $log_file = isset($config['log_file']) ? $config['log_file'] : __DIR__ . '/telepulse.log';

    foreach ($state['monitors'] as &$mon) {
        if (isset($mon['enabled']) && $mon['enabled'] === false) {
            continue;
        }

        $res = probe_target($mon['target'], $mon['type'], $config['bot_token'] ?? '', $mon['timeout'] ?? 8);
        $prev_status = $mon['status'] ?? 'UNKNOWN';
        $current_status = $res['is_up'] ? 'UP' : 'DOWN';

        $monitors_checked++;
        $mon['last_check'] = $res['checked_at'];
        $mon['last_response_time'] = $res['latency_ms'];
        $mon['last_http_code'] = $res['http_code'];
        $mon['last_error'] = $res['error'];

        $mon['total_checks'] = ($mon['total_checks'] ?? 0) + 1;
        if ($res['is_up']) {
            $mon['successful_checks'] = ($mon['successful_checks'] ?? 0) + 1;
        }
        $mon['uptime_pct'] = round(($mon['successful_checks'] / $mon['total_checks']) * 100, 1);

        if (!isset($mon['history']) || !is_array($mon['history'])) {
            $mon['history'] = [];
        }
        array_unshift($mon['history'], [
            'status' => $current_status,
            'latency' => $res['latency_ms'],
            'time' => date('H:i:s')
        ]);
        $mon['history'] = array_slice($mon['history'], 0, 10);

        if ($current_status === 'DOWN') {
            $mon['consecutive_fails'] = ($mon['consecutive_fails'] ?? 0) + 1;

            array_unshift($state['problems'], [
                'type' => 'DOWNTIME_OUTAGE',
                'monitor' => $mon['name'],
                'target' => $mon['target'],
                'error' => $res['error'],
                'http_code' => $res['http_code'],
                'latency' => $res['latency_ms'],
                'time' => date('Y-m-d H:i:s T')
            ]);
            $state['problems'] = array_slice($state['problems'], 0, 50);

            if ($prev_status === 'UP' || $prev_status === 'UNKNOWN' || $mon['consecutive_fails'] === 5) {
                $mon['status'] = 'DOWN';
                $mon['downtime_start'] = date('Y-m-d H:i:s');

                $alert_msg = "🚨 *INCIDENT ALERT: Service DOWN / Error Detected*\n\n"
                           . "🔴 *Monitor:* " . $mon['name'] . "\n"
                           . "🎯 *Target:* `" . $mon['target'] . "`\n"
                           . "⚡ *Type:* " . strtoupper($mon['type']) . "\n"
                           . "⏱ *Latency:* " . $res['latency_ms'] . "ms\n"
                           . "❌ *Problem / Error:* " . $res['error'] . "\n"
                           . "📡 *HTTP Code:* " . ($res['http_code'] ? $res['http_code'] : 'Timeout / Failed') . "\n"
                           . "🔢 *Consecutive Fails:* " . $mon['consecutive_fails'] . "\n"
                           . "🕒 *Time:* " . date('Y-m-d H:i:s T') . "\n\n"
                           . "⚠️ *Action Required:* Please investigate the service immediately.";

                send_telegram_message($alert_msg, $config);
                log_message("ALERT SENT: Monitor {$mon['name']} is DOWN", $log_file);
                $alerts_sent++;
            }
        } else {
            $mon['consecutive_fails'] = 0;
            if ($prev_status === 'DOWN') {
                $mon['status'] = 'UP';
                $down_since = $mon['downtime_start'] ?? 'Unknown';

                $recovery_msg = "✅ *RESOLVED: Service Restored UP*\n\n"
                              . "🟢 *Monitor:* " . $mon['name'] . "\n"
                              . "🎯 *Target:* `" . $mon['target'] . "`\n"
                              . "⚡ *Latency:* " . $res['latency_ms'] . "ms\n"
                              . "🕒 *Restored At:* " . date('Y-m-d H:i:s T') . "\n"
                              . "⏳ *Down Since:* " . $down_since . "\n\n"
                              . "✨ All systems are now operating normally.";

                send_telegram_message($recovery_msg, $config);
                log_message("RECOVERY SENT: Monitor {$mon['name']} is UP", $log_file);
                $alerts_sent++;
            } else {
                $mon['status'] = 'UP';
            }
        }
    }

    save_state($state, isset($config['data_file']) ? $config['data_file'] : __DIR__ . '/state.json');
    return [
        'monitors_checked' => $monitors_checked,
        'alerts_sent' => $alerts_sent,
        'timestamp' => date('Y-m-d H:i:s T')
    ];
}

function handle_webhook_update($update, $config) {
    $state = load_state($config);
    $log_file = isset($config['log_file']) ? $config['log_file'] : __DIR__ . '/telepulse.log';
    $time_str = date('Y-m-d H:i:s T');

    // 1. User Join Notification (new_chat_members)
    if (isset($update['message']['new_chat_members'])) {
        $chat = $update['message']['chat'];
        $chat_title = $chat['title'] ?? ($chat['username'] ? '@' . $chat['username'] : 'Private Chat');
        $chat_id_from = $chat['id'];

        foreach ($update['message']['new_chat_members'] as $member) {
            $fname = $member['first_name'] ?? '';
            $lname = $member['last_name'] ?? '';
            $user_name = trim("$fname $lname");
            $user_handle = !empty($member['username']) ? '@' . $member['username'] : 'No Username';
            $user_id = $member['id'];
            $is_bot = !empty($member['is_bot']) ? '🤖 Bot' : '👤 User';

            array_unshift($state['member_events'], [
                'type' => 'JOIN',
                'user' => $user_name,
                'username' => $user_handle,
                'user_id' => $user_id,
                'chat' => $chat_title,
                'time' => $time_str
            ]);
            $state['member_events'] = array_slice($state['member_events'], 0, 50);

            $join_msg = "👋 *NEW MEMBER JOINED!*\n\n"
                      . "👤 *User:* " . ($user_name ? $user_name : 'Telegram User') . "\n"
                      . "🏷 *Username:* " . $user_handle . "\n"
                      . "🆔 *User ID:* `" . $user_id . "`\n"
                      . "⚡ *Type:* " . $is_bot . "\n"
                      . "📢 *Chat/Channel:* " . $chat_title . " (`" . $chat_id_from . "`)\n"
                      . "🕒 *Time:* " . $time_str . "\n\n"
                      . "✨ *Action:* Member logged in TelePulse registry.";

            send_telegram_message($join_msg, $config);
            log_message("JOIN EVENT: $user_name ($user_handle) joined $chat_title", $log_file);
        }
        save_state($state, isset($config['data_file']) ? $config['data_file'] : __DIR__ . '/state.json');
        return true;
    }

    // 2. User Leave Notification (left_chat_member)
    if (isset($update['message']['left_chat_member'])) {
        $chat = $update['message']['chat'];
        $chat_title = $chat['title'] ?? ($chat['username'] ? '@' . $chat['username'] : 'Private Chat');
        $chat_id_from = $chat['id'];
        $member = $update['message']['left_chat_member'];

        $fname = $member['first_name'] ?? '';
        $lname = $member['last_name'] ?? '';
        $user_name = trim("$fname $lname");
        $user_handle = !empty($member['username']) ? '@' . $member['username'] : 'No Username';
        $user_id = $member['id'];
        $is_bot = !empty($member['is_bot']) ? '🤖 Bot' : '👤 User';

        array_unshift($state['member_events'], [
            'type' => 'LEAVE',
            'user' => $user_name,
            'username' => $user_handle,
            'user_id' => $user_id,
            'chat' => $chat_title,
            'time' => $time_str
        ]);
        $state['member_events'] = array_slice($state['member_events'], 0, 50);

        $leave_msg = "🚪 *MEMBER LEFT / REMOVED!*\n\n"
                   . "👤 *User:* " . ($user_name ? $user_name : 'Telegram User') . "\n"
                   . "🏷 *Username:* " . $user_handle . "\n"
                   . "🆔 *User ID:* `" . $user_id . "`\n"
                   . "⚡ *Type:* " . $is_bot . "\n"
                   . "📢 *Chat/Channel:* " . $chat_title . " (`" . $chat_id_from . "`)\n"
                   . "🕒 *Time:* " . $time_str . "\n\n"
                   . "⚠️ *Notice:* User is no longer in the chat.";

        send_telegram_message($leave_msg, $config);
        log_message("LEAVE EVENT: $user_name ($user_handle) left $chat_title", $log_file);
        save_state($state, isset($config['data_file']) ? $config['data_file'] : __DIR__ . '/state.json');
        return true;
    }

    // 3. Status Changed (chat_member / my_chat_member)
    if (isset($update['chat_member']) || isset($update['my_chat_member'])) {
        $event = $update['chat_member'] ?? $update['my_chat_member'];
        $chat = $event['chat'];
        $chat_title = $chat['title'] ?? ($chat['username'] ? '@' . $chat['username'] : 'Chat');
        $user = $event['new_chat_member']['user'] ?? ($event['from'] ?? []);

        $fname = $user['first_name'] ?? '';
        $lname = $user['last_name'] ?? '';
        $user_name = trim("$fname $lname");
        $user_handle = !empty($user['username']) ? '@' . $user['username'] : 'No Username';
        $old_status = $event['old_chat_member']['status'] ?? 'unknown';
        $new_status = $event['new_chat_member']['status'] ?? 'unknown';

        if ($old_status !== $new_status) {
            $emoji = 'ℹ️';
            $title = "MEMBER STATUS UPDATED";
            if ($new_status === 'member') { $emoji = '👋'; $title = "MEMBER JOINED VIA LINK"; }
            elseif ($new_status === 'left') { $emoji = '🚪'; $title = "MEMBER LEFT CHANNEL"; }
            elseif ($new_status === 'kicked') { $emoji = '🚫'; $title = "MEMBER BANNED"; }
            elseif ($new_status === 'administrator') { $emoji = '👑'; $title = "PROMOTED TO ADMIN"; }

            $status_msg = "$emoji *$title*\n\n"
                        . "👤 *User:* " . ($user_name ? $user_name : 'User') . " (" . $user_handle . ")\n"
                        . "🔄 *Status:* `" . $old_status . "` ➔ `" . $new_status . "`\n"
                        . "📢 *Chat:* " . $chat_title . "\n"
                        . "🕒 *Time:* " . $time_str;

            send_telegram_message($status_msg, $config);
        }
        return true;
    }

    // 4. Commands
    if (isset($update['message']['text'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text']);
        $msg_id = $update['message']['message_id'];

        if ($text === '/start' || $text === '/help') {
            $reply = "👋 *TelePulse 24/7 Telegram Uptime & Member Alert Bot*\n\n"
                   . "🔥 *Commands:*\n"
                   . "📊 `/status` - Live uptime status of channels & bots\n"
                   . "📈 `/stats` - Overall system health & uptime statistics\n"
                   . "⚠️ `/problems` - View recent errors & downtime log\n"
                   . "🔍 `/check @handle` - Instant probe on any channel/bot\n"
                   . "➕ `/add Name | type | target` - Add new target monitor\n"
                   . "❌ `/del <monitor_id>` - Remove a target monitor\n"
                   . "👥 `/members` - View recent user join/leave events\n"
                   . "⚡ `/ping` - Trigger instant monitoring cycle\n\n"
                   . "🔔 *Active Alerts:* User Joins/Leaves, Downtime, Latency Spikes, HTTP Errors.";
            send_telegram_message($reply, $config, $chat_id, $msg_id);
            return true;
        }

        if ($text === '/status') {
            $msg = "📊 *TelePulse Live System Status*\n\n";
            $total = count($state['monitors']);
            $up_count = 0;

            foreach ($state['monitors'] as $m) {
                $is_up = ($m['status'] ?? 'UNKNOWN') === 'UP';
                if ($is_up) $up_count++;
                $emoji = $is_up ? '🟢' : '🔴';
                $msg .= "$emoji *" . $m['name'] . "* (`" . $m['id'] . "`)\n";
                $msg .= "   └ Target: `" . $m['target'] . "` | Status: *" . ($m['status'] ?? 'UNKNOWN') . "* | Uptime: *" . ($m['uptime_pct'] ?? 100) . "%*\n";
            }

            $msg .= "\n📈 *Health Summary:* $up_count / $total Online\n";
            $msg .= "🕒 Last checked: " . date('Y-m-d H:i:s T', $state['last_run'] ?? time());

            send_telegram_message($msg, $config, $chat_id, $msg_id);
            return true;
        }

        if ($text === '/stats') {
            $total = count($state['monitors']);
            $up_count = 0;
            $avg_latency = 0;
            $latencies = [];

            foreach ($state['monitors'] as $m) {
                if (($m['status'] ?? '') === 'UP') $up_count++;
                if (isset($m['last_response_time']) && $m['last_response_time'] > 0) {
                    $latencies[] = $m['last_response_time'];
                }
            }
            if (!empty($latencies)) {
                $avg_latency = round(array_sum($latencies) / count($latencies));
            }

            $msg = "📈 *TelePulse System Statistics*\n\n"
                 . "🎯 *Total Monitors:* " . $total . "\n"
                 . "🟢 *Online Services:* " . $up_count . "\n"
                 . "🔴 *Offline Services:* " . ($total - $up_count) . "\n"
                 . "⚡ *Average Latency:* " . $avg_latency . " ms\n"
                 . "⚠️ *Total Recorded Incidents:* " . count($state['problems']) . "\n"
                 . "👥 *Tracked Member Events:* " . count($state['member_events']) . "\n\n"
                 . "🕒 *Last Monitoring Cycle:* " . date('Y-m-d H:i:s T', $state['last_run'] ?? time());

            send_telegram_message($msg, $config, $chat_id, $msg_id);
            return true;
        }

        if ($text === '/problems' || $text === '/alerts') {
            $problems = $state['problems'] ?? [];
            if (empty($problems)) {
                send_telegram_message("✨ *No Active Problems!* All channels and bots are running with zero errors.", $config, $chat_id, $msg_id);
                return true;
            }

            $msg = "⚠️ *Recent Problems & Error Log (Last 10 Events):*\n\n";
            $recent = array_slice($problems, 0, 10);
            foreach ($recent as $idx => $p) {
                $num = $idx + 1;
                $msg .= "$num. ❌ *" . ($p['monitor'] ?? 'Target') . "*\n";
                $msg .= "   └ Error: `" . ($p['error'] ?? 'Unknown') . "`\n";
                $msg .= "   └ Latency: " . ($p['latency'] ?? 0) . "ms | Time: " . ($p['time'] ?? 'N/A') . "\n\n";
            }
            send_telegram_message($msg, $config, $chat_id, $msg_id);
            return true;
        }

        if ($text === '/members') {
            $members = $state['member_events'] ?? [];
            if (empty($members)) {
                send_telegram_message("ℹ️ *No member join/leave events recorded yet.* Invite this bot to your channel/group as admin to track members.", $config, $chat_id, $msg_id);
                return true;
            }

            $msg = "👥 *Recent Member Join & Leave Activity:*\n\n";
            $recent = array_slice($members, 0, 8);
            foreach ($recent as $idx => $m) {
                $icon = ($m['type'] ?? '') === 'JOIN' ? '👋' : '🚪';
                $msg .= "$icon *" . ($m['type'] ?? 'EVENT') . "*: " . ($m['user'] ?? 'User') . " (" . ($m['username'] ?? '') . ")\n";
                $msg .= "   └ Chat: " . ($m['chat'] ?? '') . " | Time: " . ($m['time'] ?? '') . "\n";
            }
            send_telegram_message($msg, $config, $chat_id, $msg_id);
            return true;
        }

        if (strpos($text, '/add') === 0) {
            $raw = trim(substr($text, 4));
            $parts = explode('|', $raw);
            if (count($parts) < 3) {
                send_telegram_message("❌ *Usage:* `/add Monitor Name | channel|bot|webhook|miniapp | target`", $config, $chat_id, $msg_id);
                return true;
            }
            $name = trim($parts[0]);
            $type_input = strtolower(trim($parts[1]));
            $target = trim($parts[2]);

            $type = 'telegram_channel';
            if (strpos($type_input, 'bot') !== false) $type = 'telegram_bot';
            elseif (strpos($type_input, 'webhook') !== false) $type = 'telegram_webhook';
            elseif (strpos($type_input, 'miniapp') !== false || strpos($type_input, 'app') !== false) $type = 'telegram_miniapp';

            $new_mon = add_monitor($config, $name, $type, $target);
            $reply = "✅ *MONITOR ADDED SUCCESSFULLY!*\n\n"
                   . "🆔 *ID:* `" . $new_mon['id'] . "`\n"
                   . "📌 *Name:* " . $new_mon['name'] . "\n"
                   . "⚡ *Type:* " . $new_mon['type'] . "\n"
                   . "🎯 *Target:* `" . $new_mon['target'] . "`\n\n"
                   . "Run `/ping` to run an instant health probe!";
            send_telegram_message($reply, $config, $chat_id, $msg_id);
            return true;
        }

        if (strpos($text, '/del') === 0) {
            $parts = explode(' ', $text, 2);
            $mon_id = isset($parts[1]) ? trim($parts[1]) : '';
            if (empty($mon_id)) {
                send_telegram_message("❌ *Usage:* `/del mon-123456`", $config, $chat_id, $msg_id);
                return true;
            }
            $deleted = delete_monitor($config, $mon_id);
            if ($deleted) {
                send_telegram_message("🗑 *Monitor deleted:* `$mon_id`", $config, $chat_id, $msg_id);
            } else {
                send_telegram_message("⚠️ *Monitor not found with ID:* `$mon_id`", $config, $chat_id, $msg_id);
            }
            return true;
        }

        if (strpos($text, '/check') === 0) {
            $parts = explode(' ', $text, 2);
            $target = isset($parts[1]) ? trim($parts[1]) : '';
            if ($target) {
                $res = probe_target($target, 'telegram_channel', $config['bot_token'] ?? '');
                $reply = ($res['is_up'] ? "🟢 *ONLINE*" : "🔴 *OFFLINE / ERROR*") . "\n\n"
                       . "Target: `" . $target . "`\n"
                       . "Latency: " . $res['latency_ms'] . "ms\n"
                       . "HTTP Code: " . ($res['http_code'] ? $res['http_code'] : 'Timeout') . "\n"
                       . "Info: " . $res['error'];
                send_telegram_message($reply, $config, $chat_id, $msg_id);
            }
            return true;
        }

        if ($text === '/ping') {
            $r = run_monitoring_cycle($config);
            send_telegram_message("⚡ *Ping Cycle Complete!* Checked " . $r['monitors_checked'] . " monitors. Alerts sent: " . $r['alerts_sent'], $config, $chat_id, $msg_id);
            return true;
        }
    }

    return false;
}
