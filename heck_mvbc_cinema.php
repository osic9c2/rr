<?php
// check_mvbc_cinema.php
//
// PHP script to check https://mvbzcinema.top for new posts and send updates to Telegram.
//
// Usage:
// - Configure your Telegram bot token and chat ID below.
// - Run this script manually or schedule via cron every X minutes/hours.
// - Requires PHP with cURL enabled.

$telegram_bot_token = 'YOUR_TELEGRAM_BOT_TOKEN';  // Replace with your bot token
$telegram_chat_id = 'YOUR_TELEGRAM_CHAT_ID';      // Replace with your chat ID
$site_url = 'https://mvbzcinema.top';
$state_file = __DIR__ . '/mvbc_state.json';

// Fetch site HTML
function fetch_site_html($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set a user agent to mimic a browser request
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP script)');
    $html = curl_exec($ch);
    if(curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $html;
}

// Extract posts from html using regex or DOMDocument
function extract_posts($html) {
    $posts = [];

    // Use DOM parsing:
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        return $posts;
    }

    $xpath = new DOMXPath($dom);
    // Posts are inside div with class 'posts-group', inside 'article'
    $articles = $xpath->query("//div[contains(@class,'posts-group')]//article");

    foreach ($articles as $article) {
        // Find h2 with class 'entry-title' inside article
        $h2s = $xpath->query(".//h2[contains(@class,'entry-title')]/a", $article);
        if ($h2s->length > 0) {
            $a = $h2s->item(0);
            $title = trim($a->nodeValue);
            $link = $a->getAttribute('href');
            $posts[] = ['title' => $title, 'link' => $link];
        }
    }

    return $posts;
}

// Load previous state from file
function load_state($file) {
    if (!file_exists($file)) {
        return [];
    }
    $data = file_get_contents($file);
    $state = json_decode($data, true);
    return is_array($state) ? $state : [];
}

// Save current state to file
function save_state($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Compare posts and detect new ones
function detect_new_posts($old_posts, $new_posts) {
    $old_links = array_column($old_posts, 'link');
    $new = [];
    foreach ($new_posts as $post) {
        if (!in_array($post['link'], $old_links)) {
            $new[] = $post;
        }
    }
    return $new;
}

// Send Telegram message
function send_telegram_message($token, $chat_id, $message) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false,
    ];

    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL, $url);
    curl_setopt($ch,CURLOPT_POST, true);
    curl_setopt($ch,CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);

    if(curl_errno($ch)) {
        error_log('Telegram API error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return json_decode($result, true);
}

// Main logic
$html = fetch_site_html($site_url);
if ($html === false) {
    error_log("Failed to fetch site");
    exit;
}

$posts = extract_posts($html);
if (empty($posts)) {
    error_log("No posts found on site");
    exit;
}

$old_posts = load_state($state_file);
$new_posts = detect_new_posts($old_posts, $posts);

if (!empty($new_posts)) {
    foreach ($new_posts as $post) {
        $message = "🎬 پست جدید در mvbzcinema.top:\n<a href='{$post['link']}'>{$post['title']}</a>";
        $res = send_telegram_message($telegram_bot_token, $telegram_chat_id, $message);
        if ($res && isset($res['ok']) && $res['ok']) {
            echo "Sent message for post: {$post['title']}\n";
        } else {
            echo "Failed to send message for post: {$post['title']}\n";
        }
    }
    // Save new state only if successful
    save_state($state_file, $posts);
} else {
    echo "No new posts detected.\n";
}
