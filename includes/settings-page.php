<div class="wrap">
    <h1>Openwebui Chatbot Settings</h1>
    <form method="post" action="options.php">
        <?php settings_fields('ollama-chatbot-settings-group'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">API Endpoint</th>
                <td><input type="text" name="ollama_endpoint" value="<?php echo esc_attr(get_option('ollama_endpoint')); ?>" size="50" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">API Key</th>
                <td><input type="password" name="ollama_api_key" value="<?php echo esc_attr(get_option('ollama_api_key')); ?>" size="50" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Model</th>
                <td><input type="text" name="ollama_model" value="<?php echo esc_attr(get_option('ollama_model')); ?>" size="50" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Prompt</th>
                <td><textarea name="ollama_prompt" rows="10" cols="60"><?php echo esc_textarea(get_option('ollama_prompt')); ?></textarea></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
