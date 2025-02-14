document.addEventListener('DOMContentLoaded', function() {
    const chatContainer = document.getElementById('ollama-chatbot-container');
    const toggleButton = document.getElementById('ollama-chatbot-toggle');
    const windowElement = document.getElementById('ollama-chatbot-window');
    const conversationElement = document.getElementById('ollama-chatbot-conversation');
    const inputElement = document.getElementById('ollama-chatbot-input');
    const sendButton = document.getElementById('ollama-chatbot-send');

    // Initialize Markdown-It
    const md = window.markdownit({
        html: true,
        linkify: true,
        typographer: true
    });

    toggleButton.addEventListener('click', function() {
        windowElement.style.display = windowElement.style.display === 'none' ? 'block' : 'none';
    });

    sendButton.addEventListener('click', function(event) {
        event.preventDefault();
        sendMessage(inputElement.value);
        inputElement.value = '';
    });

    inputElement.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendMessage(inputElement.value);
            inputElement.value = '';
        }
    });

    function sendMessage(userMessage) {
        const prompt = ollamaChatbotVars.prompt; // Get the custom prompt from localized variables
        console.log('Custom Prompt:', prompt); // Debug: Log the custom prompt

        if (!prompt) {
            conversationElement.innerHTML += '<div class="ollama-chat-message ollama-error"><strong>Error:</strong> Prompt is not set.</div>';
            return;
        }

        const data = {
            model: ollamaChatbotVars.model,
            messages: [
                { role: 'system', content: prompt }, // Use the custom prompt as system message
                { role: 'user', content: userMessage }
            ]
        };

        console.log('Sending request with payload:', JSON.stringify(data)); // Debug: Log the payload

        axios.post(admin_url + '?action=ollama_handle_chat_request', data, {
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(function(response) {
            if (response.data.status === 'success') {
                conversationElement.innerHTML += response.data.userMessage;
                const markdownContent = md.render(response.data.assistantMessage);
                conversationElement.innerHTML += `<div class="ollama-chat-message ollama-assistant">${markdownContent}</div>`;
                conversationElement.scrollTop = conversationElement.scrollHeight;
            } else {
                conversationElement.innerHTML += `<div class="ollama-chat-message ollama-error"><strong>Error:</strong> ${response.data.message}</div>`;
            }
        })
        .catch(function(error) {
            console.error('Error calling OpenWebUI API:', error);
            conversationElement.innerHTML += '<div class="ollama-chat-message ollama-error"><strong>Error:</strong> Failed to get a response from the AI.</div>';
        });
    }

    // Example: Print "Hello World" in the chat interface
    function printHelloWorld() {
        const message = `<div class="ollama-chat-message ollama-assistant"><strong>Assistant:</strong> ${md.render('**Hello World!**')}</div>`;
        conversationElement.innerHTML += message;
        conversationElement.scrollTop = conversationElement.scrollHeight;
    }

    // Call the function to print "Hello World"
    printHelloWorld();
});
