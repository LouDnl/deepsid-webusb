<?php

require_once 'class.account.php';  // Includes setup

const FAVICON_CACHE_DIR = __DIR__ . '/../cache/favicons/';
const FAVICON_CACHE_URL = '../cache/favicons/';
const FAVICON_MAX_AGE   = 30 * 24 * 60 * 60; // 30 days
const FAVICON_MAX_SIZE  = 1024 * 1024;       // 1 MB

$site_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($site_id === false || $site_id === null) {
    serveDefaultIcon();
}

$db = $account->getDB();

$select = $db->prepare('
    SELECT url
    FROM external_links
    WHERE id = ?
      AND enabled = 1
    LIMIT 1
');

$select->execute([$site_id]);
$site = $select->fetch(PDO::FETCH_ASSOC);

if (!$site || !isSafePublicUrl($site['url'])) {
    serveDefaultIcon();
}

if (!is_dir(FAVICON_CACHE_DIR)) {
    mkdir(FAVICON_CACHE_DIR, 0755, true);
}

$cache_base = FAVICON_CACHE_DIR . $site_id;

// Find an existing cached icon, regardless of extension.
$cached_files = glob($cache_base . '.*');

if ($cached_files) {
    $cached_file = $cached_files[0];

    if (filemtime($cached_file) >= time() - FAVICON_MAX_AGE) {
        serveImage($cached_file);
    }
}

$icon = fetchFavicon($site['url']);

if ($icon === null) {
    serveDefaultIcon();
}

$extension = extensionFromMimeType($icon['content_type']);
$cache_file = $cache_base . '.' . $extension;

// Remove an older cached version with another extension.
foreach (glob($cache_base . '.*') ?: [] as $old_file) {
    @unlink($old_file);
}

file_put_contents($cache_file, $icon['body'], LOCK_EX);

serveImage($cache_file);


function fetchFavicon(string $site_url): ?array
{
    $html_response = downloadUrl($site_url, [
        'text/html',
        'application/xhtml+xml'
    ]);

    $icon_urls = [];

    if ($html_response !== null) {
        $declared_icon = findDeclaredFavicon(
            $html_response['body'],
            $html_response['final_url']
        );

        if ($declared_icon !== null) {
            $icon_urls[] = $declared_icon;
        }
    }

    $parts = parse_url($site_url);

    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $icon_urls[] = $parts['scheme'] . '://' . $parts['host'] . '/favicon.ico';
    }

    foreach (array_unique($icon_urls) as $icon_url) {
        if (!isSafePublicUrl($icon_url)) {
            continue;
        }

        $response = downloadUrl($icon_url, [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            'image/svg+xml'
        ]);

        if ($response !== null && isSupportedImage($response)) {
            return $response;
        }
    }

    return null;
}


function findDeclaredFavicon(string $html, string $page_url): ?string
{
    libxml_use_internal_errors(true);

    $document = new DOMDocument();

    if (!$document->loadHTML($html)) {
        libxml_clear_errors();
        return null;
    }

    $xpath = new DOMXPath($document);

    /*
     * Prefer ordinary favicons. Apple touch icons can be added as
     * another fallback, but they are often unnecessarily large.
     */
    $nodes = $xpath->query(
        '//link[contains(
            concat(" ", translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "),
            " icon "
        )]'
    );

    if (!$nodes || $nodes->length === 0) {
        libxml_clear_errors();
        return null;
    }

    foreach ($nodes as $node) {
        $href = trim($node->getAttribute('href'));

        if ($href !== '') {
            libxml_clear_errors();
            return resolveUrl($page_url, $href);
        }
    }

    libxml_clear_errors();

    return null;
}


function downloadUrl(string $url, array $allowed_types): ?array
{
    if (!isSafePublicUrl($url)) {
        return null;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_CONNECTTIMEOUT  => 4,
        CURLOPT_TIMEOUT         => 8,
        CURLOPT_USERAGENT       => 'DeepSID favicon cache/1.0',
        CURLOPT_HEADER          => false,
        CURLOPT_ENCODING        => '',
    ]);

    $body         = curl_exec($ch);
    $status       = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $content_type = strtolower(
        trim(explode(';', curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '')[0])
    );
    $final_url    = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

    curl_close($ch);

    if (
        !is_string($body) ||
        $status < 200 ||
        $status >= 300 ||
        strlen($body) === 0 ||
        strlen($body) > FAVICON_MAX_SIZE
    ) {
        return null;
    }

    if (!in_array($content_type, $allowed_types, true)) {
        return null;
    }

    return [
        'body'          => $body,
        'content_type'  => $content_type,
        'final_url'     => $final_url
    ];
}


function isSupportedImage(array $response): bool
{
    $allowed = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/svg+xml'
    ];

    return in_array($response['content_type'], $allowed, true);
}


function isSafePublicUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);

    if (
        !$parts ||
        empty($parts['scheme']) ||
        empty($parts['host']) ||
        !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
    ) {
        return false;
    }

    return true;
}


function resolveUrl(string $base_url, string $relative_url): string
{
    // Already absolute.
    if (preg_match('~^https?://~i', $relative_url)) {
        return $relative_url;
    }

    $base = parse_url($base_url);

    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return $relative_url;
    }

    $origin = $base['scheme'] . '://' . $base['host'];

    if (str_starts_with($relative_url, '//')) {
        return $base['scheme'] . ':' . $relative_url;
    }

    if (str_starts_with($relative_url, '/')) {
        return $origin . $relative_url;
    }

    $path = $base['path'] ?? '/';
    $directory = rtrim(dirname($path), '/\\');

    return $origin .
        ($directory !== '' && $directory !== '.' ? '/' . $directory : '') .
        '/' . $relative_url;
}


function extensionFromMimeType(string $content_type): string
{
    return match ($content_type) {
        'image/png'                 => 'png',
        'image/jpeg'                => 'jpg',
        'image/gif'                 => 'gif',
        'image/webp'                => 'webp',
        'image/svg+xml'             => 'svg',
        'image/x-icon',
        'image/vnd.microsoft.icon'  => 'ico',
        default                     => 'ico'
    };
}


function serveImage(string $file): never
{
    $mime_type = mime_content_type($file) ?: 'image/x-icon';

    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');

    readfile($file);
    exit;
}


function serveDefaultIcon(): never
{
    $default = __DIR__ . '/../images/default-site.svg';

    if (is_file($default)) {
        serveImage($default);
    }

    http_response_code(404);
    exit;
}
?>