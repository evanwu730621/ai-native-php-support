const panels = document.querySelectorAll('.panel');
const navigation = document.querySelectorAll('.nav-button');
const applicationBaseUrl = new URL('.', document.currentScript.src);
const apiUrl = (path) => new URL(path.replace(/^\/+/, ''), applicationBaseUrl).toString();

navigation.forEach((button) => {
    button.addEventListener('click', () => {
        navigation.forEach((item) => item.classList.remove('active'));
        panels.forEach((panel) => panel.classList.remove('active'));
        button.classList.add('active');
        document.getElementById(button.dataset.panel).classList.add('active');
    });
});

const documentForm = document.getElementById('document-form');
const documentResult = document.getElementById('document-result');

documentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    documentResult.innerHTML = '<p>分析中…</p>';

    const text = document.getElementById('document-text').value.trim();
    const file = document.getElementById('document-file').files[0];
    let response;

    try {
        if (file) {
            const form = new FormData();
            form.append('document', file);
            const progress = document.getElementById('chunk-progress');
            const status = document.getElementById('chunk-status');
            progress.hidden = false;
            progress.value = 10;
            status.textContent = '上傳並解析文件中…';
            response = await fetch(apiUrl('api/documents/upload'), {
                method: 'POST',
                body: form,
            });
        } else {
            response = await fetch(apiUrl('api/documents/analyze'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({text}),
            });
        }

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || '分析失敗');
        }

        if (file) {
            document.getElementById('chunk-progress').value = data.progress;
            document.getElementById('chunk-status').textContent =
                `切塊完成：${data.chunk_count} 個區塊`;
            const analysis = data.analysis;
            documentResult.innerHTML = renderDocumentAnalysis(analysis);
            return;
        }

        documentResult.innerHTML = renderDocumentAnalysis(data);
    } catch (error) {
        documentResult.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
    }
});

function renderDocumentAnalysis(data) {
    return `
        <p>${escapeHtml(data.summary)}</p>
        <div class="analysis-badges">
            <span class="badge sentiment sentiment-${sentimentClass(data.sentiment)}">
                情緒：${escapeHtml(data.sentiment)}
            </span>
            <span class="badge severity severity-${severityClass(data.severity)}">
                嚴重度：${escapeHtml(data.severity)}
            </span>
        </div>
        <h4>重點</h4>
        <ul>${data.key_points.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>
        <h4>建議行動</h4>
        <ul>${data.suggested_actions.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>
    `;
}

function sentimentClass(sentiment) {
    return sentiment === '負面' ? 'negative' : sentiment === '正面' ? 'positive' : 'neutral';
}

function severityClass(severity) {
    return severity === '高' ? 'high' : severity === '中' ? 'medium' : 'low';
}

const supportForm = document.getElementById('support-form');
const supportMessage = document.getElementById('support-message');
const chatHistory = document.getElementById('chat-history');
const ticketResult = document.getElementById('ticket-result');

supportForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = supportMessage.value.trim();
    if (!message) {
        return;
    }

    chatHistory.insertAdjacentHTML(
        'beforeend',
        `<div class="message user">${escapeHtml(message)}</div>`
    );
    supportMessage.value = '';

    try {
        const response = await fetch(apiUrl('api/support/analyze'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message}),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || '客服分析失敗');
        }

        chatHistory.insertAdjacentHTML(
            'beforeend',
            `<div class="message assistant">${escapeHtml(data.reply)}</div>`
        );
        chatHistory.scrollTop = chatHistory.scrollHeight;

        ticketResult.innerHTML = `
            <h3>${escapeHtml(data.ticket_id)}</h3>
            <span class="badge">${escapeHtml(data.category)}</span>
            <span class="badge">${escapeHtml(data.priority)}</span>
            <span class="badge">${escapeHtml(data.sentiment)}</span>
            <p>${data.needs_human_review ? '建議轉人工審核' : '可由 AI 處理'}</p>
            <h4>建議回覆</h4>
            <ul>${data.suggested_replies.map((reply) => `<li>${escapeHtml(reply)}</li>`).join('')}</ul>
        `;
    } catch (error) {
        chatHistory.insertAdjacentHTML(
            'beforeend',
            `<div class="message assistant">${escapeHtml(error.message)}</div>`
        );
    }
});

function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = String(value);
    return element.innerHTML;
}
