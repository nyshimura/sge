// src/api.js
const API_URL = 'api/index.php';

/**
 * Realiza uma chamada para a API backend.
 */
export async function apiCall(action, data = {}, method = 'POST') {
    try {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
            },
        };

        // Monta a URL base
        let url = `${API_URL}?action=${action}`;

        if (method === 'POST') {
            options.body = JSON.stringify(data);
        } else if (Object.keys(data).length) {
            url += '&' + new URLSearchParams(data).toString();
        }

        const response = await fetch(url, options);

        if (!response.ok) {
            // Tenta ler erro do backend se houver
            let errorText = await response.text();
            try {
                const jsonErr = JSON.parse(errorText);
                if (jsonErr && (jsonErr.message || jsonErr.data?.message)) {
                    errorText = jsonErr.data?.message || jsonErr.message;
                }
            } catch(e) {}
            throw new Error(errorText || `HTTP error! status: ${response.status}`);
        }

        const result = await response.json();
        if (result.success === false) {
            throw new Error(result.data?.message || result.message || 'Erro na API');
        }
        return result.data;
    } catch (error) {
        console.error(`Falha na API (${action}):`, error);
        throw error;
    }
}