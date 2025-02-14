# Wordpress-Chatbot-Openwebui
Open Source Wordpress chat bot plugin for wordpress. Openwebui API is implemented, need help designing the UI and other custom features are welcome. Any help would be appreciated.

To add the chatbot to a page, add the shortcode [ollama-chatbot]

----------------

# NEW FEATURES

# Security & Request Management

**Nonce Security:**

Every request (including AJAX and REST calls) is validated using a nonce mechanism to prevent unauthorized access.

**IP Banning & Rate Limiting:**

**Request Limit:** Limits each IP to a maximum of 100 requests per hour.

**Banning:** If an IP exceeds the limit, it is temporarily banned and an admin notification is sent.

**Caching for Rate Limiting:** Uses WordPress object cache and transients to track request counts.

**Spam Filtering:**

User inputs are passed through a filter (ollama_chatbot_filter_user_input) to help filter out unwanted content.

# Conversation & History Management

**Conversation IDs:**

Retrieves a conversation ID from POST data or browser cookies.

If none exists, a new unique conversation ID is generated and stored in a cookie.

**Conversation History:**

**Transient Storage:** Stores conversation history in WordPress transients (expires after one day by default).

**Persistent Logging:** Logs conversation details in a custom database table (ollama_chatbot_logs) with a retention period of 30 days.

**Special Commands:**

**Reset:** Typing “reset” clears the conversation history (both transient and DB log).

**History:** Typing “history” returns the formatted chat history.

**Logging:**

Database Logging: All conversations are logged persistently in a custom table with details such as conversation ID, user ID, IP, chat history, and timestamp.

**Export Options:** Admins can export logs as CSV or JSON, with a dedicated admin submenu page for exporting logs.

**Bulk Management:** Supports bulk deletion of logs via the admin interface.

# API Integration & Caching

**API Settings & Calls:**

Retrieves essential API settings (endpoint, API key, model, and prompt) from the WordPress options.

Builds a payload with the conversation history and sends it to the OpenWebUI API endpoint via a POST request.

Implements exponential backoff in case of API call failures, retrying up to three times.

**Response Caching:**

Uses both object caching and transients to cache API responses for 5 minutes by default.

Caching is keyed by a combination of the conversation ID and a hash of the conversation history.

**Fallback & Error Handling:**

Returns user-friendly error messages if the API call fails or if there is an invalid response.
Sends admin notifications for rate limit errors and API issues when in debug mode.

# REST & GraphQL Endpoints

**REST API Endpoints:**

**Chat Endpoint: /ollama-chatbot/v1/chat processes chat requests.**

**Log Retrieval Endpoint: /ollama-chatbot/v1/logs/{id} allows fetching a conversation log by ID.**

**Log Update Endpoint: /ollama-chatbot/v1/update-log lets clients update conversation logs.**

**Feedback Endpoint: /ollama-chatbot/v1/feedback accepts user feedback along with a rating.**

# Permission Checks:

REST endpoints verify nonce headers to ensure requests are authorized.

# GraphQL Integration (Optional):

If GraphQL is available, a field (chatbotLog) is registered on the RootQuery to retrieve conversation logs as JSON.

Front-End & User Interface Integration

# Shortcode:
Registers the [ollama_chatbot] shortcode to embed the chatbot interface within WordPress pages or posts.

# Enqueued Assets:

Styles: Loads a custom stylesheet (style.css) for the chatbot.

Scripts:

Loads external libraries such as Axios and Markdown-It for handling AJAX calls and Markdown formatting.

Loads a custom JavaScript file (ollama-chatbot-script.js) which is localized with necessary variables (API endpoint, keys, nonce, etc.) to enable smooth front-end interactions.

# Gutenberg Block Integration:

Registers a Gutenberg block (ollama/chatbot) so that site editors can insert the chatbot interface directly into posts or pages using the block editor.

# Widget Support:

Provides a legacy widget registration for sites that prefer widgetized areas for displaying the chatbot.

Administrative Features & WP-CLI Integration
 
# Admin Settings Page:

An options page is added under the Settings menu where admins can configure:

API endpoint, API key, model, prompt, and log retention days.

Debug mode toggle for detailed logging.

# Admin Menu for Logs:

Creates a dedicated admin menu page to view conversation logs with features including:

Search functionality.

Pagination and bulk actions (e.g., bulk delete).

Detailed log information including conversation ID, user details, IP, full chat history, and timestamp.

# Export Logs Page:

Provides a separate submenu page for exporting conversation logs in CSV or JSON formats. The export format can be filtered via a hook.

# WP-CLI Commands:

Integrates several WP-CLI commands to:

List recent conversation logs.

Flush the API cache.

List banned IP addresses. These commands facilitate command-line management and troubleshooting.

Scheduled Tasks & Debugging

Scheduled Events:

Hourly: Clears banned IP addresses to ensure temporary bans do not persist indefinitely.

Daily: Runs a cleanup task to delete conversation logs older than the retention period (default 30 days).

# Debug Logging:

Provides detailed logging via PHP’s error_log (and optionally a custom debug log file) when WordPress debug mode is enabled. This helps in tracking API call durations, errors, and other internal operations.

# Plugin Lifecycle & Uninstall Process

**Activation:**

Schedules the hourly and daily events.

Sets default options if they do not already exist.

Creates the custom database table for logging conversation histories.
**Deactivation:**

Clears all scheduled events to stop background tasks.

# Uninstall:

Deletes plugin-specific options.

Removes all related transients.

Drops the custom database table.

Flushes the object cache to ensure all plugin data is completely removed.

# Customizability & Filters

Throughout the plugin, many filters and actions are provided to allow developers to customize behavior without modifying the core code:

Caching and Rate Limiting: Filters allow adjustment of cache expiration and request limits.

API Settings & Payload: Hooks (ollama_chatbot_api_endpoint, ollama_chatbot_api_timeout) let you modify API call behavior.

Avatars: Filters (ollama_chatbot_user_avatar and ollama_chatbot_assistant_avatar) enable customization of user and assistant avatars.

Response Formatting: A filter (ollama_chatbot_response) allows tweaking of the final formatted output sent to the front end.

Logging and Debugging: Custom actions are provided for external logging, sentiment analysis integration, and analytics tracking.

