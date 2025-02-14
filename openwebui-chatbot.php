<?php
/**
 * Plugin Name: OpenWebUI Chatbot
 * Description: A simple chatbot plugin using OpenWebUI API with IP banning and Markdown formatting.
 * Version: 1.0
 * Author: YTM Solutions
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define the plugin directory path and URL
define('OLLAMA_CHATBOT_DIR', plugin_dir_path(__FILE__));
define('OLLAMA_CHATBOT_URL', plugins_url('', __FILE__));

// Log a message to confirm the plugin is loading
error_log('Ollama Chatbot Plugin Loaded');

// Enqueue scripts and styles
function ollama_chatbot_enqueue_scripts() {
    wp_enqueue_style('ollama-chatbot-style', OLLAMA_CHATBOT_URL . '/css/style.css');
    wp_enqueue_script('axios', 'https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js', array(), null, true); // Include axios
    wp_enqueue_script('markdown-it', 'https://cdn.jsdelivr.net/npm/markdown-it@12.3.2/dist/markdown-it.min.js', array(), null, true); // Include Markdown-It for parsing markdown
    wp_enqueue_script('ollama-chatbot-script', OLLAMA_CHATBOT_URL . '/js/ollama-chatbot-script.js', array('jquery', 'axios', 'markdown-it'), null, true);
    
    // Localize script to pass variables to JavaScript
    wp_localize_script('ollama-chatbot-script', 'ollamaChatbotVars', array(
        'endpoint' => get_option('ollama_endpoint'),
        'apiKey' => get_option('ollama_api_key'),
        'model' => get_option('ollama_model'),
        'prompt' => get_option('ollama_prompt') // Ensure this is correctly localized
    ));
}
add_action('wp_enqueue_scripts', 'ollama_chatbot_enqueue_scripts');

// Add settings link on plugin page
function ollama_chatbot_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=ollama-chatbot-settings">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'ollama_chatbot_settings_link');

// Register settings
function ollama_chatbot_register_settings() {
    register_setting('ollama-chatbot-settings-group', 'ollama_endpoint');
    register_setting('ollama-chatbot-settings-group', 'ollama_api_key');
    register_setting('ollama-chatbot-settings-group', 'ollama_model');
    register_setting('ollama-chatbot-settings-group', 'ollama_prompt'); // Register prompt setting
}
add_action('admin_init', 'ollama_chatbot_register_settings');

// Create settings page
function ollama_chatbot_settings_page() {
    include OLLAMA_CHATBOT_DIR . '/includes/settings-page.php';
}
add_action('admin_menu', function() {
    add_options_page(
        'Ollama Chatbot Settings',
        'Ollama Chatbot',
        'manage_options',
        'ollama-chatbot-settings',
        'ollama_chatbot_settings_page'
    );
});

// Shortcode
function ollama_chatbot_shortcode() {
    error_log('Shortcode rendered');
    return '<div id="ollama-chatbot-container">
                <button id="ollama-chatbot-toggle">Chat with us!</button>
                <div id="ollama-chatbot-window" style="display:none;">
                    <div id="ollama-chatbot-conversation"></div>
                    <textarea id="ollama-chatbot-input" placeholder="Type your message here..."></textarea>
                    <button id="ollama-chatbot-send">Send</button>
                </div>
            </div>';
}
add_shortcode('ollama_chatbot', 'ollama_chatbot_shortcode');

// Handle the chat request
function ollama_handle_chat_request() {
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // Check if IP is banned
    $banned_ips = get_option('ollama_banned_ips', []);
    if (in_array($ip, $banned_ips)) {
        echo json_encode(['status' => 'error', 'message' => '<p>Your IP has been temporarily blocked due to excessive requests.</p>']);
        wp_die();
    }
    
    // Track request count
    $request_count = get_transient('ollama_request_count_' . $ip);
    if (!$request_count) {
        $request_count = 1;
    } else {
        $request_count++;
    }
    
    set_transient('ollama_request_count_' . $ip, $request_count, HOUR_IN_SECONDS);
    
    // Ban IP after 100 requests
    if ($request_count >= 100) {
        $banned_ips[] = $ip;
        update_option('ollama_banned_ips', $banned_ips);
        echo json_encode(['status' => 'error', 'message' => '<p>Your IP has been temporarily blocked due to excessive requests.</p>']);
        wp_die();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userMessage = isset($_POST['userMessage']) ? sanitize_text_field($_POST['userMessage']) : '';
        
        if (!empty($userMessage)) {
            $prompt = get_option('ollama_prompt');
            
            if (!$prompt) {
                echo json_encode(['status' => 'error', 'message' => '<p>Prompt is not set.</p>']);
                wp_die();
            }
            
            $data = [
                'model' => get_option('ollama_model'),
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => $userMessage]
                ]
            ];
            
            $response = wp_remote_post(get_option('ollama_endpoint'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . get_option('ollama_api_key'),
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($data)
            ]);
            
            if (is_wp_error($response)) {
                echo json_encode(['status' => 'error', 'message' => '<p>Error calling OpenWebUI API.</p>']);
                wp_die();
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            
            if (isset($response_data['choices'][0]['message']['content'])) {
                $assistantMessage = $response_data['choices'][0]['message']['content'];
                
                // Format messages with markup
                $formattedUserMessage = '<div class="ollama-chat-message ollama-user"><strong>You:</strong> ' . esc_html($userMessage) . '</div>';
                $formattedAssistantMessage = '<div class="ollama-chat-message ollama-assistant"><strong>Assistant:</strong> ' . wp_kses_post($assistantMessage) . '</div>';
                
                echo json_encode(['status' => 'success', 'userMessage' => $formattedUserMessage, 'assistantMessage' => $formattedAssistantMessage]);
            } else {
                echo json_encode(['status' => 'error', 'message' => '<p>Unexpected response from OpenWebUI API.</p>']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '<p>User message is empty.</p>']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => '<p>Invalid request method.</p>']);
    }
    
    wp_die();
}
add_action('admin_post_ollama_handle_chat_request', 'ollama_handle_chat_request');
add_action('admin_post_nopriv_ollama_handle_chat_request', 'ollama_handle_chat_request');

// Function to clear banned IPs after an hour
function ollama_clear_banned_ips() {
    $banned_ips = get_option('ollama_banned_ips', []);
    if (!empty($banned_ips)) {
        update_option('ollama_banned_ips', []);
    }
}
add_action('hourly_event', 'ollama_clear_banned_ips');
if (!wp_next_scheduled('hourly_event')) {
    wp_schedule_event(time(), 'hourly', 'hourly_event');
}

// Function to clear request counts every hour
function ollama_clear_request_counts() {
    $banned_ips = get_option('ollama_banned_ips', []);
    foreach ($banned_ips as $ip) {
        delete_transient('ollama_request_count_' . $ip);
    }
}
add_action('hourly_event', 'ollama_clear_request_counts');