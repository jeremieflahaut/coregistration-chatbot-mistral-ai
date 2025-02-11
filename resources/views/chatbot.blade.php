@extends('layouts.app')
@section('title', 'Chatbot de Coregistration')

@section('content')
    <div class="container">
        <div class="card">
            <div class="card-header">🤖 Chatbot de Coregistration</div>
            <div class="card-body">
                <div id="chatbox" class="mb-3 border p-3" style="height: 300px; overflow-y: auto;">
                    <!-- Les messages du chatbot seront ajoutés ici dynamiquement -->
                </div>

                <div id="offers-container" class="mb-3"></div>

                <input type="text" id="user-input" class="form-control" placeholder="Votre réponse..." autofocus>
                <button id="send-btn" class="btn btn-primary mt-2">Envoyer</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            let sessionId = null;
            const chatbox = document.getElementById("chatbox");
            const userInput = document.getElementById("user-input");
            const sendBtn = document.getElementById("send-btn");
            const offersContainer = document.getElementById("offers-container");

            async function startChat() {
                const response = await fetch("{{ url('/api/chat/start') }}", { method: "POST" });
                const data = await response.json();
                sessionId = data.session_id;
                appendMessage("bot", data.message)
            }

            async function sendMessage() {
                const message = userInput.value.trim();
                if (!message) return;

                appendMessage("user", message);
                userInput.value = "";

                const response = await fetch("{{ url('/api/chat/reply') }}", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ session_id: sessionId, message: message })
                });

                const data = await response.json();
                appendMessage("bot", data.message);

                if(data.state === "finished") {
                    disableChat();
                }
            }

            function disableChat() {
                userInput.disabled = true;
                sendBtn.disabled = true;
                userInput.placeholder = "Conversation terminée.";
            }

            function appendMessage(sender, message) {
                const msgClass = sender === "bot" ? "bot-message" : "user-message";
                chatbox.innerHTML += `<p class="${msgClass}">${message}</p>`;
                chatbox.scrollTop = chatbox.scrollHeight;
            }



            sendBtn.addEventListener("click", sendMessage);
            userInput.addEventListener("keypress", function (e) {
                if (e.key === "Enter") sendMessage();
            });

            startChat();
        });
    </script>

    <style>
        #chatbox {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
        }
        .bot-message {
            background: #e9ecef;
            padding: 8px;
            border-radius: 5px;
            margin: 5px 0;
        }
        .user-message {
            background: #007bff;
            color: white;
            padding: 8px;
            border-radius: 5px;
            margin: 5px 0;
            text-align: right;
        }
    </style>
@endsection
